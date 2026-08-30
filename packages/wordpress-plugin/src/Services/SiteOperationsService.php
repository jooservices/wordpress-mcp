<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use Automatic_Upgrader_Skin;
use Core_Upgrader;
use JOOservices\WordPressMcp\Support\ErrorCodes;

final class SiteOperationsService
{
    public const MAINTENANCE_OPTION = 'jooservices_mcp_maintenance_enabled';

    /** @return array<string, mixed> */
    public function health(): array
    {
        $rest = wp_remote_get(rest_url('/'), ['timeout' => 5]);
        return [
            'wordpress_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'https' => is_ssl(),
            'rest_api_reachable' => ! is_wp_error($rest) && wp_remote_retrieve_response_code($rest) < 500,
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'maintenance_enabled' => (bool) get_option(self::MAINTENANCE_OPTION, false),
            'disk_free_bytes' => @disk_free_space(ABSPATH) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    public function updates(): array
    {
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
        $core = get_core_updates();
        return [
            'core' => array_map(
                static fn($item): array => ['version' => (string) $item->current, 'response' => (string) $item->response],
                is_array($core) ? $core : [],
            ),
            'plugins' => (new PluginService())->list()['items'], 'themes' => (new ThemeService())->list()['items'],
        ];
    }

    /** @return array{maintenance_enabled: bool} */
    public function maintenance(bool $enabled): array
    {
        update_option(self::MAINTENANCE_OPTION, $enabled, false);
        return ['maintenance_enabled' => $enabled];
    }

    /** @return array{updated: bool, error: string|null} */
    public function updateCore(): array
    {
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $updates = get_core_updates();
        $offer = is_array($updates) ? ($updates[0] ?? null) : null;
        if (! is_object($offer) || ($offer->response ?? '') !== 'upgrade') {
            return ['updated' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $result = (new Core_Upgrader(new Automatic_Upgrader_Skin()))->upgrade($offer);
        return $result === true ? ['updated' => true, 'error' => null] : ['updated' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }
}
