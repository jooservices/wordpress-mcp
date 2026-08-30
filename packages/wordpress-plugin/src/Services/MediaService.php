<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use JOOservices\WordPressMcp\Support\MediaFileNamer;
use JOOservices\WordPressMcp\Support\MediaImageInspector;
use JOOservices\WordPressMcp\Support\MediaStoredVerifier;
use JOOservices\WordPressMcp\Support\MediaVerificationResult;
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
    public function get(int $id, bool $verify = false): ?array
    {
        if (get_post_type($id) !== 'attachment') {
            return null;
        }

        $media = $this->normalize($id);

        if ($media === null) {
            return null;
        }

        if ($verify) {
            $stored = MediaStoredVerifier::verifyAttachment($id);
            $media['verification'] = $stored['verification'];

            if (MediaVerificationResult::passed($stored['verification'])) {
                MediaStoredVerifier::markVerified($id);
            }
        }

        return $media;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{media: array<string, mixed>|null, verification: array<string, mixed>|null, error: string|null, error_step: string|null}
     */
    public function upload(array $data): array
    {
        $content = base64_decode((string) ($data['content_base64'] ?? ''), true);

        if ($content === false) {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.input');
        }

        $limitFailure = $this->checkUploadLimits($content);

        if ($limitFailure !== null) {
            return $this->uploadFailure(ErrorCodes::MEDIA_UPLOAD_LIMIT_EXCEEDED, $limitFailure);
        }

        $preCheck = MediaImageInspector::inspectBytes($content);

        if (! $preCheck['ok']) {
            return $this->uploadFailure(
                ErrorCodes::MEDIA_VERIFY_FAILED,
                (string) $preCheck['step'],
                MediaVerificationResult::build([
                    'source_bytes' => $preCheck['bytes'],
                    'sha256' => $preCheck['sha256'],
                    'mime_detected' => $preCheck['mime'],
                    'width' => $preCheck['width'],
                    'height' => $preCheck['height'],
                    'decode_ok' => false,
                    'failed_step' => $preCheck['step'],
                ]),
            );
        }

        $naming = MediaFileNamer::resolve($data, $preCheck['mime']);

        if (isset($naming['error_step'])) {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, (string) $naming['error_step']);
        }

        $fileName = $naming['file_name'];

        if (! $this->hasSafeExtension($fileName)) {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.extension');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachmentId = $this->sideloadAttachment($fileName, $preCheck['mime'], $content);

        if ($attachmentId === null) {
            return $this->uploadFailure(ErrorCodes::WORDPRESS_ERROR, 'upload.sideload');
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, (string) get_attached_file($attachmentId));
        wp_update_attachment_metadata($attachmentId, $metadata);

        $stored = MediaStoredVerifier::verifyAttachment($attachmentId, $preCheck['sha256']);

        if ($stored['step'] !== null) {
            $this->cleanupAttachment($attachmentId);

            return $this->uploadFailure(
                ErrorCodes::MEDIA_VERIFY_FAILED,
                $stored['step'],
                $stored['verification'],
            );
        }

        $metadataFields = $this->applyMetadata($attachmentId, $data, $naming);
        $metadataCheck = MediaStoredVerifier::verifyMetadata($attachmentId, $metadataFields);

        if ($metadataCheck['step'] !== null) {
            $this->cleanupAttachment($attachmentId);

            return $this->uploadFailure(
                ErrorCodes::MEDIA_VERIFY_FAILED,
                $metadataCheck['step'],
                array_merge($stored['verification'], ['failed_step' => $metadataCheck['step']]),
            );
        }

        $verification = $stored['verification'];
        $featuredSet = $this->maybeSetFeaturedImage($attachmentId, $data);

        if ($featuredSet['error'] !== null) {
            $this->cleanupAttachment($attachmentId);

            return $this->uploadFailure(
                $featuredSet['error'],
                $featuredSet['step'] ?? 'featured.set_failed',
                array_merge($verification, ['failed_step' => $featuredSet['step'] ?? 'featured.set_failed']),
            );
        }

        $verification['featured_set'] = $featuredSet['set'];
        $verification['passed'] = true;
        MediaStoredVerifier::markVerified($attachmentId);

        $media = $this->normalize($attachmentId);
        $media['slug_base'] = $naming['slug_base'];
        $media['image_type'] = $naming['image_type'];
        $media['verification'] = $verification;

        return [
            'media' => $media,
            'verification' => $verification,
            'error' => null,
            'error_step' => null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{media: array<string, mixed>|null, error: string|null, error_step: string|null}
     */
    public function update(int $id, array $data): array
    {
        if (get_post_type($id) !== 'attachment') {
            return ['media' => null, 'error' => ErrorCodes::MEDIA_NOT_FOUND, 'error_step' => null];
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
            return ['media' => null, 'error' => ErrorCodes::WORDPRESS_ERROR, 'error_step' => 'metadata.update'];
        }

        if (array_key_exists('alt_text', $data)) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $data['alt_text']));
        }

        $expected = [
            'title' => array_key_exists('title', $data) ? sanitize_textarea_field((string) $data['title']) : null,
            'alt_text' => array_key_exists('alt_text', $data) ? sanitize_text_field((string) $data['alt_text']) : null,
            'caption' => array_key_exists('caption', $data) ? sanitize_textarea_field((string) $data['caption']) : null,
            'description' => array_key_exists('description', $data) ? sanitize_textarea_field((string) $data['description']) : null,
        ];

        $metadataCheck = MediaStoredVerifier::verifyMetadata($id, $expected);

        if ($metadataCheck['step'] !== null) {
            return [
                'media' => null,
                'error' => ErrorCodes::MEDIA_VERIFY_FAILED,
                'error_step' => $metadataCheck['step'],
            ];
        }

        $media = $this->normalize($id);

        return ['media' => $media, 'error' => null, 'error_step' => null];
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
     * Verify an existing attachment without uploading new bytes.
     *
     * @return array{media: array<string, mixed>|null, error: string|null, error_step: string|null}
     */
    public function verifyExisting(int $id): array
    {
        if (get_post_type($id) !== 'attachment') {
            return ['media' => null, 'error' => ErrorCodes::MEDIA_NOT_FOUND, 'error_step' => null];
        }

        $stored = MediaStoredVerifier::verifyAttachment($id);

        if ($stored['step'] !== null) {
            return [
                'media' => null,
                'error' => ErrorCodes::MEDIA_VERIFY_FAILED,
                'error_step' => $stored['step'],
            ];
        }

        MediaStoredVerifier::markVerified($id);
        $media = $this->normalize($id);
        $media['verification'] = $stored['verification'];

        return ['media' => $media, 'error' => null, 'error_step' => null];
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
            'slug_base' => (string) get_post_meta($id, '_mcp_media_slug_base', true) ?: null,
            'image_type' => (string) get_post_meta($id, '_mcp_media_image_type', true) ?: null,
            'alt_text' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => (string) get_post_field('post_excerpt', $id),
            'description' => (string) get_post_field('post_content', $id),
            'created_at' => get_post_time('c', true, $id),
            'verified' => MediaStoredVerifier::isVerified($id),
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

    private function checkUploadLimits(string $content): ?string
    {
        $bytes = strlen($content);
        $maxBytes = (int) wp_max_upload_size();

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            return 'pre_validate.upload_limit';
        }

        return null;
    }

    private function sideloadAttachment(string $fileName, string $mimeType, string $content): ?int
    {
        $tmpFile = wp_tempnam($fileName);

        if ($tmpFile === '' || file_put_contents($tmpFile, $content) === false) {
            if ($tmpFile !== '') {
                wp_delete_file($tmpFile);
            }

            return null;
        }

        $fileArray = [
            'name' => $fileName,
            'type' => $mimeType,
            'tmp_name' => $tmpFile,
            'error' => '0',
            'size' => (string) strlen($content),
        ];

        $attachmentId = media_handle_sideload($fileArray, 0);

        if (is_wp_error($attachmentId)) {
            wp_delete_file($tmpFile);

            return null;
        }

        return (int) $attachmentId;
    }

    /**
     * @param array{file_name: string, slug_base: string, image_type: string|null, attachment_title: string} $naming
     * @param array<string, mixed> $data
     * @return array<string, string|null>
     */
    private function applyMetadata(int $attachmentId, array $data, array $naming): array
    {
        $title = sanitize_text_field($naming['attachment_title']);
        $altText = array_key_exists('alt_text', $data) ? sanitize_text_field((string) $data['alt_text']) : null;
        $caption = array_key_exists('caption', $data) ? sanitize_textarea_field((string) $data['caption']) : null;
        $description = array_key_exists('description', $data) ? sanitize_textarea_field((string) $data['description']) : null;

        $update = [
            'ID' => $attachmentId,
            'post_title' => $title,
        ];

        if ($caption !== null) {
            $update['post_excerpt'] = $caption;
        }

        if ($description !== null) {
            $update['post_content'] = $description;
        }

        wp_update_post($update);

        if ($altText !== null) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
        }

        if ($naming['slug_base'] !== '') {
            update_post_meta($attachmentId, '_mcp_media_slug_base', $naming['slug_base']);
        }

        if ($naming['image_type'] !== null && $naming['image_type'] !== '') {
            update_post_meta($attachmentId, '_mcp_media_image_type', $naming['image_type']);
        }

        return [
            'title' => $title,
            'alt_text' => $altText,
            'caption' => $caption,
            'description' => $description,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{set: bool, error: string|null, step: string|null}
     */
    private function maybeSetFeaturedImage(int $attachmentId, array $data): array
    {
        $setFeatured = ($data['set_featured'] ?? false) === true;
        $postId = (int) ($data['post_id'] ?? 0);

        if (! $setFeatured || $postId <= 0) {
            return ['set' => false, 'error' => null, 'step' => null];
        }

        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return ['set' => false, 'error' => ErrorCodes::POST_NOT_FOUND, 'step' => 'featured.post_not_found'];
        }

        if (! wp_attachment_is_image($attachmentId)) {
            return ['set' => false, 'error' => ErrorCodes::INVALID_ARGUMENT, 'step' => 'featured.not_image'];
        }

        if (! set_post_thumbnail($postId, $attachmentId)) {
            return ['set' => false, 'error' => ErrorCodes::WORDPRESS_ERROR, 'step' => 'featured.set_failed'];
        }

        $current = (int) get_post_thumbnail_id($postId);

        if ($current !== $attachmentId) {
            return ['set' => false, 'error' => ErrorCodes::MEDIA_VERIFY_FAILED, 'step' => 'featured.verify_failed'];
        }

        return ['set' => true, 'error' => null, 'step' => null];
    }

    private function cleanupAttachment(int $attachmentId): void
    {
        wp_delete_attachment($attachmentId, true);
    }

    /**
     * @param array<string, mixed>|null $verification
     * @return array{media: null, verification: array<string, mixed>|null, error: string, error_step: string}
     */
    private function uploadFailure(string $error, string $step, ?array $verification = null): array
    {
        if (is_array($verification)) {
            $verification['passed'] = false;
            $verification['failed_step'] = $step;
        }

        return [
            'media' => null,
            'verification' => $verification,
            'error' => $error,
            'error_step' => $step,
        ];
    }
}
