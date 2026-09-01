<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use FilesystemIterator;
use JOOservices\WordPressMcp\Support\UploadDirectory;
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
 * The filesystem walk is too slow/unbounded for a synchronous MCP tool call,
 * so it only ever runs from wp-admin (button click) or WP-Cron (daily); the
 * result is cached via {@see runScan()} and read back via {@see cachedResult()}.
 *
 * @phpstan-type BrokenAttachment array{id: int, title: string, attached_file: string, edit_url: string}
 * @phpstan-type OrphanFile array{path: string, url: string|null, size: int, mime: string|null, width: int|null, height: int|null}
 * @phpstan-type OrphanFiles array{items: list<OrphanFile>, truncated: bool}
 * @phpstan-type ScanResult array{scanned_at: string, broken_attachments: list<BrokenAttachment>, orphan_files: OrphanFiles}
 */
final class MediaOrphanScanner
{
    private const OPTION_KEY = 'jooservices_mcp_media_orphans';

    private const MAX_RESULTS = 500;

    private const MAX_FILES_SCANNED = 20000;

    /**
     * Runs both scans and persists the result so it can be read back cheaply
     * (wp-admin page load, MCP tool call) without repeating the filesystem walk.
     *
     * @return ScanResult
     */
    public function runScan(): array
    {
        $result = [
            'scanned_at' => gmdate('c'),
            'broken_attachments' => $this->findBrokenAttachments(),
            'orphan_files' => $this->findOrphanFiles(),
        ];

        update_option(self::OPTION_KEY, $result, false);

        return $result;
    }

    /**
     * @return ScanResult|null
     */
    public function cachedResult(): ?array
    {
        $stored = get_option(self::OPTION_KEY, null);

        return is_array($stored) ? $stored : null;
    }

    /**
     * @return list<BrokenAttachment>
     */
    public function findBrokenAttachments(): array
    {
        global $wpdb;

        $basedir = UploadDirectory::basedir();

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
     * @return OrphanFiles
     */
    public function findOrphanFiles(): array
    {
        $basedir = UploadDirectory::basedir();

        if ($basedir === null || ! is_dir($basedir)) {
            return ['items' => [], 'truncated' => false];
        }

        $baseurl = UploadDirectory::baseurl();
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

            $orphans[] = array_merge(
                [
                    'path' => $relative,
                    'url' => $baseurl !== null ? $baseurl . '/' . $relative : null,
                    'size' => (int) $fileInfo->getSize(),
                ],
                $this->inspectFile($fileInfo->getPathname()),
            );

            if (count($orphans) >= self::MAX_RESULTS) {
                $truncated = true;
                break;
            }
        }

        return ['items' => $orphans, 'truncated' => $truncated];
    }

    /**
     * Removes one path from the cached orphan-files list right after it's
     * adopted, so a stale cache (scan runs at most daily) doesn't keep
     * offering the same file for adoption before the next scan runs.
     */
    public function forgetOrphanFile(string $relativePath): void
    {
        $cached = $this->cachedResult();

        if ($cached === null) {
            return;
        }

        $items = array_values(array_filter(
            $cached['orphan_files']['items'],
            static fn(array $item): bool => $item['path'] !== $relativePath,
        ));

        if (count($items) === count($cached['orphan_files']['items'])) {
            return;
        }

        $cached['orphan_files']['items'] = $items;
        update_option(self::OPTION_KEY, $cached, false);
    }

    /**
     * Deletes an orphan file by its path relative to the uploads basedir.
     * Refuses anything that resolves outside the uploads directory.
     */
    public function deleteOrphanFile(string $relativePath): bool
    {
        $basedir = UploadDirectory::basedir();

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
     * Lightweight probe for the admin list table — `getimagesize()` only
     * reads the file header, not the full file, so this stays cheap even
     * for a few hundred orphans. Falls back to `mime_content_type()` for
     * non-images (dimensions stay null).
     *
     * @return array{mime: string|null, width: int|null, height: int|null}
     */
    private function inspectFile(string $fullPath): array
    {
        $info = @getimagesize($fullPath);

        if (is_array($info)) {
            return [
                'mime' => $info['mime'],
                'width' => (int) $info[0],
                'height' => (int) $info[1],
            ];
        }

        $mime = function_exists('mime_content_type') ? @mime_content_type($fullPath) : false;

        return ['mime' => is_string($mime) ? $mime : null, 'width' => null, 'height' => null];
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
}
