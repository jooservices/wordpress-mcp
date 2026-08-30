<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;

final class RedirectService
{
    private const REDIRECTS_OPTION = 'jooservices_mcp_redirects';
    private const NOT_FOUND_OPTION = 'jooservices_mcp_404_log';

    /** @return array{items: list<array<string, mixed>>} */
    public function list(): array
    {
        return ['items' => array_values(get_option(self::REDIRECTS_OPTION, []))];
    }
    /** @return array{items: list<array<string, mixed>>} */
    public function notFound(): array
    {
        return ['items' => array_values(get_option(self::NOT_FOUND_OPTION, []))];
    }
    /** @return array{redirect: array<string, mixed>|null, error: string|null} */
    public function upsert(string $source, string $destination, int $status): array
    {
        $source = '/' . ltrim(wp_parse_url($source, PHP_URL_PATH) ?: '', '/');
        if ($source === '/' || ! in_array($status, [301, 302, 307, 308], true) || ! wp_http_validate_url($destination)) {
            return ['redirect' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $items = get_option(self::REDIRECTS_OPTION, []);
        $item = ['source' => $source, 'destination' => esc_url_raw($destination), 'status' => $status];
        $items[$source] = $item;
        update_option(self::REDIRECTS_OPTION, $items, false);
        return ['redirect' => $item, 'error' => null];
    }
    /** @return array{deleted: bool, error: string|null} */
    public function delete(string $source): array
    {
        $items = get_option(self::REDIRECTS_OPTION, []);
        if (! isset($items[$source])) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        } unset($items[$source]);
        update_option(self::REDIRECTS_OPTION, $items, false);
        return ['deleted' => true, 'error' => null];
    }
    public static function applyAndLog(): void
    {
        $path = '/' . ltrim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $redirects = get_option(self::REDIRECTS_OPTION, []);
        if (isset($redirects[$path])) {
            wp_redirect($redirects[$path]['destination'], (int) $redirects[$path]['status']);
            exit;
        }
        if (is_404()) {
            $items = get_option(self::NOT_FOUND_OPTION, []);
            $items[] = ['path' => $path, 'at' => current_time('c', true)];
            update_option(self::NOT_FOUND_OPTION, array_slice($items, -200), false);
        }
    }
}
