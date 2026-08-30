<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp;

use JOOservices\WordPressMcp\Admin\AdminMenu;
use JOOservices\WordPressMcp\Audit\AuditLogger;
use JOOservices\WordPressMcp\Database\Schema;
use JOOservices\WordPressMcp\Http\RestRegistrar;
use JOOservices\WordPressMcp\Services\SeoService;

final class Plugin
{
    private const PURGE_CRON_HOOK = 'jooservices_mcp_purge_audit_log';

    public static function boot(): void
    {
        $plugin = new self();
        $plugin->registerHooks();
    }

    public static function activate(): void
    {
        Schema::install();

        if (! wp_next_scheduled(self::PURGE_CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::PURGE_CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::PURGE_CRON_HOOK);
    }

    public static function purgeAuditLog(): void
    {
        (new AuditLogger())->purgeOlderThan();
    }

    private function registerHooks(): void
    {
        register_activation_hook(JOOSERVICES_WORDPRESS_MCP_FILE, [self::class, 'activate']);
        register_deactivation_hook(JOOSERVICES_WORDPRESS_MCP_FILE, [self::class, 'deactivate']);
        add_action('plugins_loaded', [Schema::class, 'maybeUpgrade']);
        add_action(self::PURGE_CRON_HOOK, [self::class, 'purgeAuditLog']);
        add_action('rest_api_init', [new RestRegistrar(), 'register']);
        $adminMenu = new AdminMenu();
        add_action('admin_menu', [$adminMenu, 'register']);
        add_action('admin_init', [$adminMenu, 'registerPostHandlers']);
        add_filter('robots_txt', [SeoService::class, 'filterRobotsTxt']);
    }
}
