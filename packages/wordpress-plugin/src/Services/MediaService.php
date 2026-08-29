<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Query;

final class MediaService
{
    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function list(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 10)));

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $perPage,
            'paged' => $page,
            's' => isset($params['q']) ? sanitize_text_field((string) $params['q']) : '',
        ]);

        $items = [];

        foreach ($query->posts as $post) {
            $items[] = $this->normalize((int) $post->ID);
        }

        return [
            'items' => array_values(array_filter($items)),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        if (get_post_type($id) !== 'attachment') {
            return null;
        }

        return $this->normalize($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{media: array<string, mixed>|null, error: string|null}
     */
    public function upload(array $data): array
    {
        $fileName = sanitize_file_name((string) ($data['file_name'] ?? ''));
        $mimeType = sanitize_mime_type((string) ($data['mime_type'] ?? ''));
        $content = base64_decode((string) ($data['content_base64'] ?? ''), true);

        if ($fileName === '' || $mimeType === '' || $content === false) {
            return ['media' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $maxBytes = 10 * 1024 * 1024;

        if (strlen($content) > $maxBytes) {
            return ['media' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_upload_bits($fileName, null, $content);

        if ($upload['error'] !== '') {
            return ['media' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $title = isset($data['title'])
            ? sanitize_text_field((string) $data['title'])
            : sanitize_file_name(pathinfo($fileName, PATHINFO_FILENAME));

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mimeType,
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachmentId)) {
            return ['media' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $metadata = wp_generate_attachment_metadata((int) $attachmentId, $upload['file']);
        wp_update_attachment_metadata((int) $attachmentId, $metadata);

        $media = $this->normalize((int) $attachmentId);

        return ['media' => $media, 'error' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalize(int $id): ?array
    {
        $url = wp_get_attachment_url($id);

        if ($url === false) {
            return null;
        }

        return [
            'id' => $id,
            'title' => get_the_title($id),
            'url' => $url,
            'mime_type' => get_post_mime_type($id),
            'file_name' => basename((string) get_attached_file($id)),
            'created_at' => get_post_time('c', true, $id),
        ];
    }
}
