<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class MediaImageInspector
{
    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @return array{ok: bool, step: string|null, mime: string, width: int, height: int, sha256: string, bytes: int}
     */
    public static function inspectBytes(string $content): array
    {
        $bytes = strlen($content);

        if ($bytes === 0) {
            return self::failure('pre_validate.empty', $bytes);
        }

        $sha256 = hash('sha256', $content);
        $mime = self::detectMimeFromBytes($content);

        if ($mime === null) {
            return self::failure('pre_validate.mime', $bytes, $sha256);
        }

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return self::failure('pre_validate.mime_not_allowed', $bytes, $sha256, $mime);
        }

        $dimensions = self::decodeDimensions($content);

        if ($dimensions === null) {
            return self::failure('pre_validate.decode', $bytes, $sha256, $mime);
        }

        [$width, $height] = $dimensions;

        if ($width <= 0 || $height <= 0) {
            return self::failure('pre_validate.dimensions', $bytes, $sha256, $mime, $width, $height);
        }

        return [
            'ok' => true,
            'step' => null,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{ok: bool, step: string|null, mime: string, width: int, height: int, sha256: string, bytes: int}
     */
    public static function inspectFile(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return self::failure('post_validate.missing_file', 0);
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            return self::failure('post_validate.read_file', 0);
        }

        $result = self::inspectBytes($content);
        $result['step'] = $result['ok'] ? null : str_replace('pre_validate', 'post_validate', (string) $result['step']);

        return $result;
    }

    public static function detectMimeFromBytes(string $content): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $content);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return self::normalizeMime($detected);
                }
            }
        }

        return self::detectMimeFromSignature($content);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public static function decodeDimensions(string $content): ?array
    {
        if (! function_exists('getimagesizefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($content);

        if ($info === false) {
            return null;
        }

        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($content);

            if ($image === false) {
                return null;
            }

            imagedestroy($image);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        return [$width, $height];
    }

    private static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match ($mime) {
            'image/jpg' => 'image/jpeg',
            default => $mime,
        };
    }

    private static function detectMimeFromSignature(string $content): ?string
    {
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($content, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }

        if (
            strlen($content) >= 12
            && str_starts_with($content, 'RIFF')
            && substr($content, 8, 4) === 'WEBP'
        ) {
            return 'image/webp';
        }

        return null;
    }

    /**
     * @return array{ok: false, step: string, mime: string, width: int, height: int, sha256: string, bytes: int}
     */
    private static function failure(
        string $step,
        int $bytes,
        string $sha256 = '',
        string $mime = '',
        int $width = 0,
        int $height = 0,
    ): array {
        return [
            'ok' => false,
            'step' => $step,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
            'bytes' => $bytes,
        ];
    }
}
