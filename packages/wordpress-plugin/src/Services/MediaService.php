<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use JOOservices\WordPressMcp\Support\MediaFileNamer;
use JOOservices\WordPressMcp\Support\MediaImageInspector;
use JOOservices\WordPressMcp\Support\MediaStoredVerifier;
use JOOservices\WordPressMcp\Support\MediaVerificationResult;
use JOOservices\WordPressMcp\Support\UploadDirectory;
use WP_Query;

final class MediaService
{
    /**
     * Remembers the orphan-scan path an attachment was adopted from, so a
     * re-adopt of that same path still resolves to it even after WordPress
     * renames the attached file (e.g. `-scaled` for big images) — see
     * {@see findAttachmentBySourcePath()}.
     */
    private const SOURCE_PATH_META_KEY = '_jooservices_orphan_source_path';

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
     * Registers an existing orphan file (found by {@see MediaOrphanScanner})
     * as a real attachment without copying or re-uploading its bytes: WordPress
     * points the new attachment straight at the file already on disk, so no
     * duplicate file is ever created. Matches `path` or `url` against the
     * cached orphan scan first — the filesystem path we actually touch always
     * comes from that trusted scan result, never straight from caller input.
     *
     * Idempotent: if the file was already adopted (e.g. two broken posts
     * pointing at the same orphan), returns the existing attachment instead
     * of inserting a second record for the same file. Matched, in order:
     * {@see SOURCE_PATH_META_KEY} (adoptions made after this fix),
     * {@see findAttachmentByScaledVariant()} (adoptions made before it, via
     * WordPress's own `original_image` record), then `_wp_attached_file`
     * verbatim. A lookup keyed on `_wp_attached_file` alone misses a re-adopt
     * of the same original path — WordPress rewrites that meta to a
     * `-scaled` filename during metadata generation for big images — and
     * creates a duplicate attachment pointing at the same physical file.
     *
     * @param array<string, mixed> $data
     * @return array{media: array<string, mixed>|null, verification: array<string, mixed>|null, error: string|null, error_step: string|null}
     */
    public function adoptOrphan(array $data): array
    {
        $path = isset($data['path']) ? ltrim((string) $data['path'], '/') : '';
        $url = isset($data['url']) ? (string) $data['url'] : '';

        if ($path === '' && $url === '') {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.input');
        }

        $match = $this->resolveOrphanMatch($path, $url);

        if ($match === null) {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.not_orphan');
        }

        $relativePath = $match['path'];
        $basedir = UploadDirectory::basedir();
        $full = $basedir !== null ? realpath($basedir . '/' . $relativePath) : false;

        if ($basedir === null || $full === false || ! str_starts_with($full, $basedir . '/') || ! is_file($full)) {
            return $this->uploadFailure(ErrorCodes::MEDIA_NOT_FOUND, 'pre_validate.missing_file');
        }

        if (! $this->hasSafeExtension(basename($relativePath))) {
            return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.extension');
        }

        $preCheck = MediaImageInspector::inspectFile($full);

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

        $fallbackTitle = sanitize_file_name((string) pathinfo(basename($relativePath), PATHINFO_FILENAME));
        $existingId = $this->findAttachmentBySourcePath($relativePath)
            ?? $this->findAttachmentByScaledVariant($relativePath)
            ?? $this->findAttachmentByAttachedFile($relativePath);
        $isNewAdoption = $existingId === null;
        $title = trim(sanitize_text_field((string) ($data['title'] ?? '')));

        if ($title === '') {
            $title = $existingId !== null ? (get_the_title($existingId) ?: $fallbackTitle) : $fallbackTitle;
        }

        if ($isNewAdoption) {
            // No attachment claims the derived `-scaled` path (the lookups
            // above would have matched it via findAttachmentByScaledVariant()
            // otherwise), but a physical file can still sit there — e.g. its
            // owning attachment was deleted, or it's an unrelated orphan that
            // coincidentally shares the name (this site's own orphan scan has
            // both plain "-scaled" and WP-uniquified "-scaled-1" leftovers).
            // Whether WordPress's scaling then overwrites that file in place
            // or renames around it depends on core internals we don't
            // control; either outcome is a problem we can avoid entirely by
            // refusing up front instead of gambling on which one happens.
            $scaledRelative = $this->deriveScaledRelativePath($relativePath);

            if ($scaledRelative !== null && is_file($basedir . '/' . $scaledRelative)) {
                return $this->uploadFailure(ErrorCodes::INVALID_ARGUMENT, 'pre_validate.scaled_variant_exists');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $inserted = wp_insert_attachment([
                'post_mime_type' => $preCheck['mime'],
                'post_title' => $title,
                'post_content' => '',
                'post_status' => 'inherit',
            ], $full);

            if (is_wp_error($inserted) || (int) $inserted <= 0) {
                return $this->uploadFailure(ErrorCodes::WORDPRESS_ERROR, 'adopt.insert_failed');
            }

            $attachmentId = (int) $inserted;
            update_post_meta($attachmentId, self::SOURCE_PATH_META_KEY, $relativePath);

            // The file being adopted is itself already a `-scaled` derivative
            // (e.g. a genuine original was scaled once, then that original
            // was later orphaned and only the derivative remains). Without
            // this, WordPress's own big-image handling can scale it *again*,
            // producing "-scaled-scaled.ext" and leaving the single-scaled
            // file behind as fresh orphan bloat — seen for real on this site
            // (attachment 9312). Disable that one behavior for this call
            // only; normal thumbnail/medium/large subsizes still generate.
            $skipRescale = $this->isScaledFilename($relativePath);

            if ($skipRescale) {
                add_filter('big_image_size_threshold', '__return_false');
            }

            try {
                $metadata = wp_generate_attachment_metadata($attachmentId, $full);
            } finally {
                if ($skipRescale) {
                    remove_filter('big_image_size_threshold', '__return_false');
                }
            }

            wp_update_attachment_metadata($attachmentId, $metadata);
        } else {
            $attachmentId = $existingId;
        }

        $stored = MediaStoredVerifier::verifyAttachment($attachmentId);

        if ($stored['step'] !== null) {
            if ($isNewAdoption) {
                $this->detachFailedAdoption($attachmentId);
            }

            return $this->uploadFailure(ErrorCodes::MEDIA_VERIFY_FAILED, $stored['step'], $stored['verification']);
        }

        $naming = ['file_name' => basename($relativePath), 'slug_base' => '', 'image_type' => null, 'attachment_title' => $title];
        $metadataFields = $this->applyMetadata($attachmentId, $data, $naming);
        $metadataCheck = MediaStoredVerifier::verifyMetadata($attachmentId, $metadataFields);

        if ($metadataCheck['step'] !== null) {
            if ($isNewAdoption) {
                $this->detachFailedAdoption($attachmentId);
            }

            return $this->uploadFailure(
                ErrorCodes::MEDIA_VERIFY_FAILED,
                $metadataCheck['step'],
                array_merge($stored['verification'], ['failed_step' => $metadataCheck['step']]),
            );
        }

        $verification = $stored['verification'];
        $featuredSet = $this->maybeSetFeaturedImage($attachmentId, $data);

        if ($featuredSet['error'] !== null) {
            if ($isNewAdoption) {
                $this->detachFailedAdoption($attachmentId);
            }

            return $this->uploadFailure(
                $featuredSet['error'],
                $featuredSet['step'] ?? 'featured.set_failed',
                array_merge($verification, ['failed_step' => $featuredSet['step'] ?? 'featured.set_failed']),
            );
        }

        $verification['featured_set'] = $featuredSet['set'];
        $verification['passed'] = true;
        MediaStoredVerifier::markVerified($attachmentId);

        // Whether this call inserted a new attachment or resolved to an
        // existing one, the orphan-scan cache should stop offering this
        // path — it's mapped to an attachment either way.
        (new MediaOrphanScanner())->forgetOrphanFile($relativePath);

        $media = $this->normalize($attachmentId);
        $media['verification'] = $verification;
        $media['adopted_from'] = $relativePath;

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
     * @return array{path: string, url: string|null}|null
     */
    private function resolveOrphanMatch(string $path, string $url): ?array
    {
        $cached = (new MediaOrphanScanner())->cachedResult();
        $items = $cached['orphan_files']['items'] ?? [];

        foreach ($items as $item) {
            if ($path !== '' && $item['path'] === $path) {
                return $item;
            }

            if ($url !== '' && ($item['url'] ?? null) === $url) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Primary idempotency check: matches the path the file was originally
     * adopted from, which stays stable even after WordPress renames
     * `_wp_attached_file` to a `-scaled` variant during metadata generation.
     */
    private function findAttachmentBySourcePath(string $relativePath): ?int
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [
                ['key' => self::SOURCE_PATH_META_KEY, 'value' => $relativePath],
            ],
        ]);

        $post = $query->posts[0] ?? null;

        return $post instanceof \WP_Post ? (int) $post->ID : null;
    }

    /**
     * True when this path's own filename already ends in `-scaled` — i.e.
     * it's already a big-image derivative, not an original.
     */
    private function isScaledFilename(string $relativePath): bool
    {
        return str_ends_with(pathinfo($relativePath, PATHINFO_FILENAME), '-scaled');
    }

    /**
     * Deterministic `-scaled` filename WordPress's own big-image handling
     * would use for this path (`wp-admin/includes/image.php`'s
     * `WP_Image_Editor::generate_filename('scaled')`), which inserts the
     * suffix with no uniqueness check — null when the path has no extension.
     */
    private function deriveScaledRelativePath(string $relativePath): ?string
    {
        $info = pathinfo($relativePath);
        $extension = $info['extension'] ?? '';

        if ($extension === '') {
            return null;
        }

        $dir = $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';

        return $dir . $info['filename'] . '-scaled.' . $extension;
    }

    /**
     * Retroactive fallback for images WordPress already scaled *before*
     * {@see SOURCE_PATH_META_KEY} existed, so they never got that meta.
     * WordPress itself records the pre-scale filename as `original_image`
     * in `_wp_attachment_metadata` whenever it creates a `-scaled` variant
     * (see wp-admin/includes/image.php, _wp_image_meta_replace_original());
     * trust that WP-native breadcrumb rather than re-deriving the `-scaled`
     * suffix ourselves, which could otherwise match an unrelated attachment
     * that merely happens to share the same filename.
     */
    /** @phpstan-ignore-next-line return.unusedType The wordpress-stubs shape for wp_get_attachment_metadata() omits 'original_image', a real key WP core only adds for scaled attachments — PHPStan can't see the branch below ever matching. */
    private function findAttachmentByScaledVariant(string $relativePath): ?int
    {
        $scaledRelative = $this->deriveScaledRelativePath($relativePath);

        if ($scaledRelative === null) {
            return null;
        }

        $candidateId = $this->findAttachmentByAttachedFile($scaledRelative);

        if ($candidateId === null) {
            return null;
        }

        $metadata = wp_get_attachment_metadata($candidateId);
        $originalImage = is_array($metadata) ? ($metadata['original_image'] ?? null) : null;

        return $originalImage === basename($relativePath) ? $candidateId : null;
    }

    /**
     * Fallback for attachments adopted before {@see SOURCE_PATH_META_KEY}
     * existed. Only matches when `_wp_attached_file` still equals the
     * orphan-scan path verbatim, which WordPress's own big-image scaling
     * can silently rewrite — see {@see adoptOrphan()}. When duplicates
     * already share this value (the very bug this file fixes), picks the
     * lowest ID so repeated adopts converge on the same attachment.
     */
    private function findAttachmentByAttachedFile(string $relativePath): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
            $relativePath,
        ));

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * Removes an attachment post created during a failed adopt without touching
     * its file. wp_delete_attachment()/wp_delete_post() on an attachment always
     * deletes the underlying file too (WordPress core hooks wp_delete_attachment
     * onto delete_post), which would destroy the very orphan file adoptOrphan()
     * exists to preserve — unhooking it for this one call keeps the file intact.
     */
    private function detachFailedAdoption(int $attachmentId): void
    {
        remove_action('delete_post', 'wp_delete_attachment');
        wp_delete_post($attachmentId, true);
        add_action('delete_post', 'wp_delete_attachment');
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
