<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class MediaFileNamer
{
    private const TYPE_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,31})?$/';

    public static function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{file_name: string, slug_base: string, image_type: string|null, attachment_title: string}|array{error_step: string}
     */
    public static function resolve(array $data, string $detectedMime): array
    {
        $title = trim(sanitize_text_field((string) ($data['title'] ?? '')));
        $type = self::normalizeType($data);

        if ($title !== '' && $type !== null) {
            return self::resolveFromTitleAndType($title, $type, $detectedMime);
        }

        $fileName = sanitize_file_name((string) ($data['file_name'] ?? ''));

        if ($fileName === '') {
            return ['error_step' => 'pre_validate.input'];
        }

        $basename = pathinfo($fileName, PATHINFO_FILENAME);
        $attachmentTitle = $title !== '' ? $title : sanitize_file_name($basename);

        return [
            'file_name' => $fileName,
            'slug_base' => sanitize_title($basename !== '' ? $basename : $fileName),
            'image_type' => $type,
            'attachment_title' => $attachmentTitle,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function normalizeType(array $data): ?string
    {
        $raw = $data['image_type'] ?? $data['type'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $type = sanitize_key($raw);

        return $type !== '' ? $type : null;
    }

    /**
     * @return array{file_name: string, slug_base: string, image_type: string, attachment_title: string}|array{error_step: string}
     */
    private static function resolveFromTitleAndType(string $title, string $type, string $detectedMime): array
    {
        if (! preg_match(self::TYPE_PATTERN, $type)) {
            return ['error_step' => 'pre_validate.image_type'];
        }

        $extension = self::extensionForMime($detectedMime);

        if ($extension === null) {
            return ['error_step' => 'pre_validate.extension'];
        }

        $titleSlug = sanitize_title($title);
        $typeSlug = sanitize_title($type);

        if ($titleSlug === '' || $typeSlug === '') {
            return ['error_step' => 'pre_validate.slug'];
        }

        $slugBase = $titleSlug . '-' . $typeSlug;

        return [
            'file_name' => sanitize_file_name($slugBase . '.' . $extension),
            'slug_base' => $slugBase,
            'image_type' => $type,
            'attachment_title' => $title,
        ];
    }
}
