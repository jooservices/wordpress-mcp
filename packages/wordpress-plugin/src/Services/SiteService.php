<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;

final class SiteService
{
    /** @return array<string, string|int> */
    public function limits(): array
    {
        return [
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'wp_max_upload_size_bytes' => (int) wp_max_upload_size(),
        ];
    }

    /**
     * @return array{core_update_available: bool, core_update_version: string|null}
     */
    public function coreUpdateSummary(): array
    {
        if (! function_exists('get_core_updates')) {
            /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        if (function_exists('wp_version_check')) {
            wp_version_check();
        }

        $updates = get_core_updates();
        $offer = is_array($updates) ? ($updates[0] ?? null) : null;
        $available = is_object($offer) && ($offer->response ?? '') === 'upgrade';

        return [
            'core_update_available' => $available,
            'core_update_version' => $available && is_object($offer) ? (string) $offer->current : null,
        ];
    }

    /**
     * @return array{stylesheet: string, name: string, version: string}|null
     */
    public function activeThemeSummary(): ?array
    {
        $theme = wp_get_theme();

        if (! $theme->exists()) {
            return null;
        }

        return [
            'stylesheet' => $theme->get_stylesheet(),
            'name' => (string) $theme->get('Name'),
            'version' => (string) $theme->get('Version'),
        ];
    }

    public function activePluginsCount(): int
    {
        if (! function_exists('get_plugins')) {
            /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $count = 0;

        foreach (array_keys(get_plugins()) as $plugin) {
            if (is_plugin_active($plugin)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Connection $connection): array
    {
        $core = $this->coreUpdateSummary();

        $payload = [
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
            'wordpress_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'supported_capabilities' => $connection->scopes,
            'limits' => $this->limits(),
            'core_update_available' => $core['core_update_available'],
            'core_update_version' => $core['core_update_version'],
            'maintenance_enabled' => (bool) get_option(SiteOperationsService::MAINTENANCE_OPTION, false),
            'is_multisite' => is_multisite(),
            'active_theme' => $this->activeThemeSummary(),
            'active_plugins_count' => $this->activePluginsCount(),
            'settings' => ScopeChecker::userCan($connection, 'settings.read')
                ? (new SettingsService())->get()
                : null,
            'health' => ScopeChecker::userCan($connection, 'site.health.read')
                ? (new SiteOperationsService())->health()
                : null,
            'updates' => ScopeChecker::userCan($connection, 'updates.read')
                ? (new SiteOperationsService())->updates()
                : null,
        ];

        return $payload;
    }
}
