<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Query;

final class MediaService
{
    /** @var list<string> */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
        'pht', 'phtm', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'exe', 'bat', 'cmd',
        'sh', 'bash', 'com', 'scr', 'vbs', 'htaccess',
    ];

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

        if (! $this->hasSafeExtension($fileName)) {
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

        if ($upload['error'] !== false) {
            return ['media' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        if (! $this->matchesContentType($upload['file'], $fileName, $mimeType)) {
            wp_delete_file($upload['file']);

            return ['media' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
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
     * @param array<string, mixed> $data
     * @return array{media: array<string, mixed>|null, error: string|null}
     */
    public function update(int $id, array $data): array
    {
        if (get_post_type($id) !== 'attachment') {
            return ['media' => null, 'error' => ErrorCodes::MEDIA_NOT_FOUND];
        }

        $allowed = ['title', 'caption', 'description'];
        $update = ['ID' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field === 'title' ? 'post_title' : ($field === 'caption' ? 'post_excerpt' : 'post_content')]
                    = sanitize_textarea_field((string) $data[$field]);
            }
        }

        if (count($update) > 1 && is_wp_error(wp_update_post($update, true))) {
            return ['media' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        if (array_key_exists('alt_text', $data)) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $data['alt_text']));
        }

        return ['media' => $this->normalize($id), 'error' => null];
    }

    /** @return array{deleted: bool, error: string|null} */
    public function delete(int $id): array
    {
        if (get_post_type($id) !== 'attachment') {
            return ['deleted' => false, 'error' => ErrorCodes::MEDIA_NOT_FOUND];
        }

        return wp_delete_attachment($id, true) instanceof \WP_Post
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
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
            'alt_text' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => (string) get_post_field('post_excerpt', $id),
            'description' => (string) get_post_field('post_content', $id),
            'created_at' => get_post_time('c', true, $id),
        ];
    }

    private function hasSafeExtension(string $fileName): bool
    {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension === '') {
            return false;
        }

        return ! in_array($extension, self::BLOCKED_EXTENSIONS, true);
    }

    private function matchesContentType(string $filePath, string $fileName, string $declaredMimeType): bool
    {
        $check = wp_check_filetype_and_ext($filePath, $fileName);

        if ($check['ext'] === false || $check['type'] === false) {
            return false;
        }

        if ($check['type'] === 'application/octet-stream') {
            return $declaredMimeType === 'application/octet-stream';
        }

        [$detectedFamily] = explode('/', (string) $check['type'], 2);
        [$declaredFamily] = explode('/', $declaredMimeType, 2);

        return $detectedFamily !== '' && $detectedFamily === $declaredFamily;
    }
}
