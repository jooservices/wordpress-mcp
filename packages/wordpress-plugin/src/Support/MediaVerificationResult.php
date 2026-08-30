<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class MediaVerificationResult
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function build(array $overrides = []): array
    {
        $defaults = [
            'passed' => false,
            'source_bytes' => 0,
            'stored_bytes' => null,
            'sha256' => '',
            'stored_sha256' => null,
            'sha256_match' => null,
            'mime_detected' => '',
            'mime_stored' => null,
            'width' => 0,
            'height' => 0,
            'decode_ok' => false,
            'metadata_generated' => false,
            'public_url_ok' => null,
            'public_url_status' => null,
            'featured_set' => false,
            'failed_step' => null,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * @param array<string, mixed> $verification
     */
    public static function passed(array $verification): bool
    {
        return ($verification['passed'] ?? false) === true;
    }
}
