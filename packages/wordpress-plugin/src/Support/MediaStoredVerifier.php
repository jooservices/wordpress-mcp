<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class MediaStoredVerifier
{
    public const VERIFIED_META_KEY = '_mcp_media_verified';

    /**
     * @return array{verification: array<string, mixed>, step: string|null}
     */
    public static function verifyAttachment(int $attachmentId, ?string $sourceSha256 = null): array
    {
        $filePath = (string) get_attached_file($attachmentId);
        $inspection = MediaImageInspector::inspectFile($filePath);

        $verification = MediaVerificationResult::build([
            'source_bytes' => $inspection['bytes'],
            'stored_bytes' => is_file($filePath) ? filesize($filePath) : null,
            'sha256' => $sourceSha256 ?? $inspection['sha256'],
            'stored_sha256' => $inspection['sha256'],
            'sha256_match' => $sourceSha256 === null ? null : hash_equals($sourceSha256, $inspection['sha256']),
            'mime_detected' => $inspection['mime'],
            'mime_stored' => get_post_mime_type($attachmentId) ?: null,
            'width' => $inspection['width'],
            'height' => $inspection['height'],
            'decode_ok' => $inspection['ok'],
            'metadata_generated' => self::hasGeneratedMetadata($attachmentId),
        ]);

        if (! $inspection['ok']) {
            $verification['failed_step'] = $inspection['step'];

            return ['verification' => $verification, 'step' => $inspection['step']];
        }

        if ($sourceSha256 !== null && $verification['sha256_match'] !== true) {
            $verification['failed_step'] = 'post_validate.sha256_mismatch';

            return ['verification' => $verification, 'step' => 'post_validate.sha256_mismatch'];
        }

        if (is_int($verification['stored_bytes']) && $verification['stored_bytes'] !== $inspection['bytes']) {
            $verification['failed_step'] = 'post_validate.filesize_mismatch';

            return ['verification' => $verification, 'step' => 'post_validate.filesize_mismatch'];
        }

        $storedMime = (string) ($verification['mime_stored'] ?? '');

        if ($storedMime !== '' && $storedMime !== $inspection['mime']) {
            $verification['failed_step'] = 'post_validate.mime_mismatch';

            return ['verification' => $verification, 'step' => 'post_validate.mime_mismatch'];
        }

        if ($verification['metadata_generated'] !== true) {
            $verification['failed_step'] = 'post_validate.metadata';

            return ['verification' => $verification, 'step' => 'post_validate.metadata'];
        }

        $publicCheck = self::verifyPublicUrl((string) wp_get_attachment_url($attachmentId));

        $verification['public_url_ok'] = $publicCheck['ok'];
        $verification['public_url_status'] = $publicCheck['status'];

        if (! $publicCheck['ok']) {
            $verification['failed_step'] = $publicCheck['step'];

            return ['verification' => $verification, 'step' => $publicCheck['step']];
        }

        $verification['passed'] = true;
        $verification['failed_step'] = null;

        return ['verification' => $verification, 'step' => null];
    }

    /**
     * @param array<string, string|null> $expected
     * @return array{verification: array<string, mixed>, step: string|null}
     */
    public static function verifyMetadata(int $attachmentId, array $expected): array
    {
        $checks = [
            'metadata.title' => [get_the_title($attachmentId), $expected['title'] ?? null],
            'metadata.alt_text' => [(string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true), $expected['alt_text'] ?? null],
            'metadata.caption' => [(string) get_post_field('post_excerpt', $attachmentId), $expected['caption'] ?? null],
            'metadata.description' => [(string) get_post_field('post_content', $attachmentId), $expected['description'] ?? null],
        ];

        foreach ($checks as $step => [$actual, $wanted]) {
            if ($wanted === null) {
                continue;
            }

            if ($actual !== $wanted) {
                return [
                    'verification' => MediaVerificationResult::build([
                        'failed_step' => $step,
                        'metadata_mismatch' => ['field' => $step, 'expected' => $wanted, 'actual' => $actual],
                    ]),
                    'step' => $step,
                ];
            }
        }

        return [
            'verification' => MediaVerificationResult::build(['passed' => true]),
            'step' => null,
        ];
    }

    public static function markVerified(int $attachmentId): void
    {
        update_post_meta($attachmentId, self::VERIFIED_META_KEY, '1');
        update_post_meta($attachmentId, '_mcp_media_verified_at', gmdate('c'));
    }

    public static function isVerified(int $attachmentId): bool
    {
        return get_post_meta($attachmentId, self::VERIFIED_META_KEY, true) === '1';
    }

    /**
     * @return array{ok: bool, status: int|null, step: string|null}
     */
    public static function verifyPublicUrl(string $url): array
    {
        if ($url === '') {
            return ['ok' => false, 'status' => null, 'step' => 'public_url.missing'];
        }

        if ((bool) apply_filters('jooservices_mcp_skip_public_url_verify', false)) {
            return ['ok' => true, 'status' => 200, 'step' => null];
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => null, 'step' => 'public_url.request_failed'];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            return ['ok' => false, 'status' => $status, 'step' => 'public_url.http_status'];
        }

        $body = (string) wp_remote_retrieve_body($response);
        $inspection = MediaImageInspector::inspectBytes($body);

        if (! $inspection['ok']) {
            return ['ok' => false, 'status' => $status, 'step' => 'public_url.decode'];
        }

        return ['ok' => true, 'status' => $status, 'step' => null];
    }

    private static function hasGeneratedMetadata(int $attachmentId): bool
    {
        $metadata = wp_get_attachment_metadata($attachmentId);

        if (! is_array($metadata)) {
            return false;
        }

        $width = (int) ($metadata['width'] ?? 0);
        $height = (int) ($metadata['height'] ?? 0);
        $sizes = $metadata['sizes'] ?? null;

        return $width > 0 && $height > 0 && is_array($sizes) && $sizes !== [];
    }
}
