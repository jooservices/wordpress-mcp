<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use Automatic_Upgrader_Skin;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use Theme_Upgrader;
use WP_Theme;

final class ThemeService
{
    /**
     * @return array{items: list<array<string, bool|string>>}
     */
    public function list(): array
    {
        $updates = get_site_transient('update_themes');
        $availableUpdates = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : [];
        $items = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            if ($theme instanceof WP_Theme) {
                $items[] = $this->normalize($theme, isset($availableUpdates[$stylesheet]));
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{theme: array<string, bool|string>|null, error: string|null}
     */
    public function install(string $slug): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return ['theme' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $this->loadUpgraderApi();
        $information = themes_api('theme_information', ['slug' => $slug]);

        if (is_wp_error($information) || ! isset($information->download_link)) {
            return ['theme' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());

        if ($upgrader->install((string) $information->download_link) !== true) {
            return ['theme' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get($slug);
    }

    /**
     * @return array{theme: array<string, bool|string>|null, error: string|null}
     */
    public function activate(string $stylesheet): array
    {
        if (! $this->exists($stylesheet) || switch_theme($stylesheet) !== true) {
            return ['theme' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        return $this->get($stylesheet);
    }

    /**
     * @return array{theme: array<string, bool|string>|null, error: string|null}
     */
    public function update(string $stylesheet): array
    {
        if (! $this->exists($stylesheet)) {
            return ['theme' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $this->loadUpgraderApi();
        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());

        if ($upgrader->upgrade($stylesheet) !== true) {
            return ['theme' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get($stylesheet);
    }

    /**
     * @return array{deleted: bool, error: string|null}
     */
    public function delete(string $stylesheet): array
    {
        if (! $this->exists($stylesheet) || get_stylesheet() === $stylesheet) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        if (! delete_theme($stylesheet)) {
            return ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return ['deleted' => true, 'error' => null];
    }

    /**
     * @return array{theme: array<string, bool|string>|null, error: string|null}
     */
    private function get(string $stylesheet): array
    {
        $theme = wp_get_theme($stylesheet);

        return $theme->exists()
            ? ['theme' => $this->normalize($theme, false), 'error' => null]
            : ['theme' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    private function exists(string $stylesheet): bool
    {
        return wp_get_theme($stylesheet)->exists();
    }

    /**
     * @return array<string, bool|string>
     */
    private function normalize(WP_Theme $theme, bool $updateAvailable): array
    {
        return [
            'stylesheet' => $theme->get_stylesheet(),
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'active' => $theme->get_stylesheet() === get_stylesheet(),
            'update_available' => $updateAvailable,
        ];
    }

    private function loadUpgraderApi(): void
    {
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
}
