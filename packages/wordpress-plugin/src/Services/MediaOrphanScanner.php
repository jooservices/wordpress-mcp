<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds two distinct kinds of media inconsistency between the attachments
 * table and the uploads directory on disk:
 *
 * - "Broken attachments": an attachment post exists but its file is missing.
 * - "Orphan files": a file exists on disk but no attachment (original or
 *   registered subsize) references it.
 *
 * Both scans are read-only and admin-triggered (wp-admin, not the MCP REST
 * surface) — a full filesystem walk is too slow/unbounded for a synchronous
 * tool call, so results are capped rather than paginated.
 */
final class MediaOrphanScanner
{
    private const MAX_RESULTS = 500;

    private const MAX_FILES_SCANNED = 20000;

    /**
     * @return list<array{id: int, title: string, attached_file: string, edit_url: string}>
     */
    public function findBrokenAttachments(): array
    {
        global $wpdb;

        $basedir = $this->basedir();

        if ($basedir === null) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, pm.meta_value AS attached_file
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                ORDER BY p.ID DESC",
            ARRAY_A,
        );

        $broken = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $relative = ltrim((string) $row['attached_file'], '/');
            $full = $basedir . '/' . $relative;

            if ($relative === '' || is_file($full)) {
                continue;
            }

            $broken[] = [
                'id' => (int) $row['ID'],
                'title' => (string) $row['post_title'],
                'attached_file' => $relative,
                'edit_url' => admin_url('post.php?post=' . (int) $row['ID'] . '&action=edit'),
            ];

            if (count($broken) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $broken;
    }

    /**
     * @return array{items: list<string>, truncated: bool}
     */
    public function findOrphanFiles(): array
    {
        $basedir = $this->basedir();

        if ($basedir === null || ! is_dir($basedir)) {
            return ['items' => [], 'truncated' => false];
        }

        $known = $this->knownRelativePaths();
        $orphans = [];
        $truncated = false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basedir, FilesystemIterator::SKIP_DOTS),
        );

        $scanned = 0;

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $scanned++;

            if ($scanned > self::MAX_FILES_SCANNED) {
                $truncated = true;
                break;
            }

            $relative = ltrim(str_replace($basedir, '', $fileInfo->getPathname()), '/');

            if (str_starts_with(basename($relative), '.')) {
                continue;
            }

            if (isset($known[$relative])) {
                continue;
            }

            $orphans[] = $relative;

            if (count($orphans) >= self::MAX_RESULTS) {
                $truncated = true;
                break;
            }
        }

        return ['items' => $orphans, 'truncated' => $truncated];
    }

    /**
     * Deletes an orphan file by its path relative to the uploads basedir.
     * Refuses anything that resolves outside the uploads directory.
     */
    public function deleteOrphanFile(string $relativePath): bool
    {
        $basedir = $this->basedir();

        if ($basedir === null) {
            return false;
        }

        $relativePath = ltrim($relativePath, '/');
        $full = realpath($basedir . '/' . $relativePath);

        if ($full === false || ! str_starts_with($full, $basedir . '/')) {
            return false;
        }

        return wp_delete_file($full) !== false || ! file_exists($full);
    }

    /**
     * @return array<string, true> relative-path => true, for O(1) lookups
     */
    private function knownRelativePaths(): array
    {
        global $wpdb;

        $known = [];

        $attachedFiles = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");

        foreach (is_array($attachedFiles) ? $attachedFiles : [] as $relative) {
            $known[ltrim((string) $relative, '/')] = true;
        }

        $metaRows = $wpdb->get_results(
            "SELECT pm.meta_value AS meta
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
                WHERE p.post_type = 'attachment'",
            ARRAY_A,
        );

        foreach (is_array($metaRows) ? $metaRows : [] as $row) {
            $meta = @unserialize((string) $row['meta'], ['allowed_classes' => false]);

            if (! is_array($meta) || ! isset($meta['sizes']) || ! is_array($meta['sizes'])) {
                continue;
            }

            $dir = isset($meta['file']) ? dirname((string) $meta['file']) : '.';

            foreach ($meta['sizes'] as $size) {
                if (! is_array($size) || ! isset($size['file'])) {
                    continue;
                }

                $relative = $dir !== '.' && $dir !== '' ? $dir . '/' . $size['file'] : (string) $size['file'];
                $known[$relative] = true;
            }
        }

        return $known;
    }

    private function basedir(): ?string
    {
        $uploadDir = wp_upload_dir();

        if (! is_array($uploadDir) || ! empty($uploadDir['error']) || empty($uploadDir['basedir'])) {
            return null;
        }

        return rtrim((string) $uploadDir['basedir'], '/');
    }
}
