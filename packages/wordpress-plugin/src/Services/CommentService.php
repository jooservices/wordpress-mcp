<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ContentTypes;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Comment;
use WP_Comment_Query;
use WP_User;

final class CommentService
{
    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function list(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 10)));

        $query = new WP_Comment_Query([
            'status' => sanitize_key((string) ($params['status'] ?? 'hold')),
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'search' => isset($params['q']) ? sanitize_text_field((string) $params['q']) : '',
        ]);

        $items = array_map($this->normalize(...), $query->comments);
        $total = (int) get_comments([
            'status' => sanitize_key((string) ($params['status'] ?? 'hold')),
            'count' => true,
        ]);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $comment = get_comment($id);

        return $comment instanceof WP_Comment ? $this->normalize($comment) : null;
    }

    /**
     * @return array{comment: array<string, mixed>|null, error: string|null}
     */
    public function create(int $postId, string $content, int $parentId, int $userId): array
    {
        $post = get_post($postId);
        $user = get_userdata($userId);
        if (
            ! $post instanceof \WP_Post
            || ! ContentTypes::isSupported($post->post_type)
            || ! $user instanceof WP_User
            || ! comments_open($postId)
            || trim($content) === ''
            || $parentId < 0
        ) {
            return ['comment' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        if ($parentId > 0) {
            $parent = get_comment($parentId);
            if (
                ! $parent instanceof WP_Comment
                || (int) $parent->comment_post_ID !== $postId
                || in_array((string) $parent->comment_approved, ['spam', 'trash'], true)
            ) {
                return ['comment' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
        }

        $commentId = wp_new_comment(wp_slash([
            'comment_post_ID' => $postId,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_author_url' => $user->user_url,
            'comment_content' => wp_kses_post($content),
            'comment_parent' => $parentId,
            'comment_type' => '',
            'user_id' => $userId,
        ]), true);
        if (is_wp_error($commentId) || ! is_int($commentId) || $commentId < 1) {
            return ['comment' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $comment = get_comment($commentId);

        return $comment instanceof WP_Comment
            ? ['comment' => $this->normalize($comment), 'error' => null]
            : ['comment' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }

    /**
     * @return array{comment: array<string, mixed>|null, error: string|null}
     */
    public function moderate(int $id, string $action): array
    {
        $comment = get_comment($id);

        if (! $comment instanceof WP_Comment) {
            return ['comment' => null, 'error' => 'COMMENT_NOT_FOUND'];
        }

        $result = match ($action) {
            'approve' => wp_set_comment_status($id, 'approve'),
            'hold' => wp_set_comment_status($id, 'hold'),
            'spam' => wp_set_comment_status($id, 'spam'),
            default => false,
        };

        if ($result === false) {
            return ['comment' => null, 'error' => 'WORDPRESS_ERROR'];
        }

        $updated = get_comment($id);

        return ['comment' => $updated instanceof WP_Comment ? $this->normalize($updated) : null, 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(WP_Comment $comment): array
    {
        return [
            'id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'parent_id' => (int) $comment->comment_parent,
            'author' => $comment->comment_author,
            'content' => $comment->comment_content,
            'status' => wp_get_comment_status($comment),
            'created_at' => mysql2date('c', $comment->comment_date_gmt, false),
        ];
    }
}
