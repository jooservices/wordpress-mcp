<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;

final class SettingsService
{
    /** @var array<string, string> */
    private const OPTIONS = [
        'blogname' => 'text',
        'blogdescription' => 'text',
        'timezone_string' => 'timezone',
        'date_format' => 'text',
        'time_format' => 'text',
        'start_of_week' => 'int',
        'posts_per_page' => 'int',
        'blog_public' => 'bool',
        'default_comment_status' => 'comment_status',
        'default_ping_status' => 'comment_status',
        'permalink_structure' => 'permalink',
    ];

    /**
     * @return array<string, bool|int|string>
     */
    public function get(): array
    {
        $settings = [];

        foreach (array_keys(self::OPTIONS) as $option) {
            $settings[$option] = match (self::OPTIONS[$option]) {
                'bool' => (bool) get_option($option),
                'int' => (int) get_option($option),
                default => (string) get_option($option),
            };
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{settings: array<string, bool|int|string>|null, error: string|null}
     */
    public function update(array $data): array
    {
        foreach ($data as $option => $value) {
            if (! array_key_exists($option, self::OPTIONS)) {
                return ['settings' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }

            $sanitized = match (self::OPTIONS[$option]) {
                'bool' => (bool) $value,
                'int' => max(0, (int) $value),
                'timezone' => $this->timezone((string) $value),
                'comment_status' => in_array($value, ['open', 'closed'], true) ? $value : null,
                'permalink' => $this->permalink((string) $value),
                default => sanitize_text_field((string) $value),
            };

            if ($sanitized === null) {
                return ['settings' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }

            if (get_option($option) !== $sanitized && ! update_option($option, $sanitized)) {
                return ['settings' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
            }
        }

        if (array_key_exists('permalink_structure', $data)) {
            flush_rewrite_rules();
        }

        return ['settings' => $this->get(), 'error' => null];
    }

    private function timezone(string $timezone): ?string
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return null;
        }

        return $timezone;
    }

    private function permalink(string $structure): ?string
    {
        if ($structure === '') {
            return '';
        }

        return str_starts_with($structure, '/') && str_ends_with($structure, '/')
            ? $structure
            : null;
    }
}
