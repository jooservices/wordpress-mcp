<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Post;

final class RevisionService
{
    /** @return array{items: list<array<string, mixed>>, error: string|null} */
    public function list(int $postId): array
    {
        if (! get_post($postId) instanceof WP_Post) {
            return ['items' => [], 'error' => ErrorCodes::POST_NOT_FOUND];
        }
        $revisions = wp_get_post_revisions($postId, ['posts_per_page' => 50]) ?: [];
        return ['items' => array_map([$this, 'normalize'], $revisions), 'error' => null];
    }

    /** @return array{revision: array<string, mixed>|null, error: string|null} */
    public function get(int $revisionId): array
    {
        $revision = wp_get_post_revision($revisionId);
        return $revision instanceof WP_Post
            ? ['revision' => $this->normalize($revision), 'error' => null]
            : ['revision' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /** @return array{restored: bool, error: string|null} */
    public function restore(int $revisionId): array
    {
        $result = wp_restore_post_revision($revisionId);
        return $result === false ? ['restored' => false, 'error' => ErrorCodes::WORDPRESS_ERROR] : ['restored' => true, 'error' => null];
    }

    /** @return array<string, mixed> */
    private function normalize(WP_Post $revision): array
    {
        return [
            'id' => (int) $revision->ID, 'parent_id' => (int) $revision->post_parent,
            'title' => $revision->post_title, 'content' => $revision->post_content,
            'author_id' => (int) $revision->post_author, 'created_at' => get_post_time('c', true, $revision),
        ];
    }
}
