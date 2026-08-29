<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp;

use JOOservices\WordPressMcp\Admin\AdminMenu;
use JOOservices\WordPressMcp\Database\Schema;
use JOOservices\WordPressMcp\Http\RestRegistrar;

final class Plugin
{
    public static function boot(): void
    {
        $plugin = new self();
        $plugin->registerHooks();
    }

    private function registerHooks(): void
    {
        register_activation_hook(JOOSERVICES_WORDPRESS_MCP_FILE, [Schema::class, 'install']);
        add_action('rest_api_init', [new RestRegistrar(), 'register']);
        add_action('admin_menu', [new AdminMenu(), 'register']);
    }
}
