<?php

declare(strict_types=1);

/**
 * Plugin Name: JOOservices WordPress - MCP
 * Description: Scoped REST API for MCP integration with connection management, audit logging, and rate limiting.
 * Version: 1.3.0
 * Author: JOOservices
 * Requires at least: 6.4
 * Requires PHP: 8.3
 * Text Domain: wordpress-chatgpt
 */

if (! defined('ABSPATH')) {
    exit;
}

define('JOOSERVICES_WORDPRESS_MCP_VERSION', '1.3.0');
define('JOOSERVICES_WORDPRESS_MCP_FILE', __FILE__);
define('JOOSERVICES_WORDPRESS_MCP_PATH', plugin_dir_path(__FILE__));
define('JOOSERVICES_WORDPRESS_MCP_URL', plugin_dir_url(__FILE__));

$autoload = JOOSERVICES_WORDPRESS_MCP_PATH . 'vendor/autoload.php';

if (! file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>JOOservices WordPress - MCP: run <code>composer install</code> in the plugin directory.</p></div>';
    });

    return;
}

require_once $autoload;

JOOservices\WordPressMcp\Plugin::boot();
