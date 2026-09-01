<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ContentTypes;
use JOOservices\WordPressMcp\Support\UploadDirectory;
use WP_Post;

/**
 * Finds `wp-image-{ID}` references inside published post/page content where
 * `{ID}` no longer resolves to a real attachment, then checks whether the
 * exact file the broken tag points at (parsed from its own `src`, not a
 * fuzzy filename guess) still exists among the cached orphan files from
 * {@see MediaOrphanScanner}. A match means the reference is safely
 * relinkable; no match means the source file itself is gone.
 *
 * Read-only and cheap enough for a synchronous MCP tool call — this scans
 * post content (a DB column, already indexed by post_status/post_type), not
 * the filesystem, so it does not need the wp-admin/cron caching that
 * MediaOrphanScanner's filesystem walk requires.
 *
 * @phpstan-type BrokenReference array{
 *     post_id: int,
 *     type: string,
 *     title: string,
 *     edit_url: string,
 *     broken_attachment_id: int,
 *     expected_path: string|null,
 *     matched_orphan_url: string|null,
 * }
 */
final class BrokenMediaReferenceScanner
{
    private const MAX_POSTS_SCANNED = 200;

    private const MAX_RESULTS = 500;

    /**
     * @return array{items: list<BrokenReference>, truncated: bool}
     */
    public function scan(?int $postId = null): array
    {
        $baseurl = UploadDirectory::baseurl();
        $known = $this->orphanFileUrls();

        $items = [];
        $truncated = false;

        foreach ($this->postsToScan($postId) as $post) {
            foreach ($this->extractImageReferences((string) $post->post_content) as $ref) {
                if (get_post_type($ref['id']) === 'attachment') {
                    continue;
                }

                $expectedPath = $this->relativePath($ref['src'], $baseurl);

                $items[] = [
                    'post_id' => (int) $post->ID,
                    'type' => (string) $post->post_type,
                    'title' => get_the_title($post->ID),
                    'edit_url' => admin_url('post.php?post=' . (int) $post->ID . '&action=edit'),
                    'broken_attachment_id' => $ref['id'],
                    'expected_path' => $expectedPath,
                    'matched_orphan_url' => $expectedPath !== null ? ($known[$expectedPath] ?? null) : null,
                ];

                if (count($items) >= self::MAX_RESULTS) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * @return list<WP_Post>
     */
    private function postsToScan(?int $postId): array
    {
        if ($postId !== null) {
            $post = get_post($postId);

            return $post instanceof WP_Post && in_array($post->post_type, ContentTypes::SUPPORTED, true)
                ? [$post]
                : [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(ContentTypes::SUPPORTED), '%s'));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
                ORDER BY ID DESC
                LIMIT %d",
            [...ContentTypes::SUPPORTED, self::MAX_POSTS_SCANNED],
        ));

        $posts = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $post = get_post((int) $id);

            if ($post instanceof WP_Post) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    /**
     * @return list<array{id: int, src: string}>
     */
    private function extractImageReferences(string $content): array
    {
        if (! preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
            return [];
        }

        $refs = [];

        foreach ($tags[0] as $tag) {
            if (! preg_match('/class="[^"]*\bwp-image-(\d+)\b[^"]*"/i', $tag, $classMatch)) {
                continue;
            }

            if (! preg_match('/src="([^"]+)"/i', $tag, $srcMatch)) {
                continue;
            }

            $refs[] = ['id' => (int) $classMatch[1], 'src' => $srcMatch[1]];
        }

        return $refs;
    }

    private function relativePath(string $url, ?string $baseurl): ?string
    {
        $path = strtok($url, '?');
        $path = $path === false ? $url : $path;

        if ($baseurl === null || ! str_starts_with($path, $baseurl . '/')) {
            return null;
        }

        return substr($path, strlen($baseurl) + 1);
    }

    /**
     * @return array<string, string> relative-path => public URL, from the cached orphan-file scan
     */
    private function orphanFileUrls(): array
    {
        $cached = (new MediaOrphanScanner())->cachedResult();

        if ($cached === null) {
            return [];
        }

        $map = [];

        foreach ($cached['orphan_files']['items'] as $item) {
            $map[$item['path']] = $item['url'] ?? '';
        }

        return $map;
    }
}
