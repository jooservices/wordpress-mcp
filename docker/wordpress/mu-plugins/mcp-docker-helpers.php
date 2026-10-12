<?php

/**
 * Plugin Name: JOOservices MCP Docker helpers
 * Description: Soften rate limits for Compose; public-URL verify is skipped only when E2E_SKIP_PUBLIC_URL=1.
 */

declare(strict_types=1);

if (! defined('MCP_RATE_LIMIT_MAX')) {
    define('MCP_RATE_LIMIT_MAX', 10_000);
}

$skipPublicUrl = (string) (getenv('E2E_SKIP_PUBLIC_URL')
    ?: ($_ENV['E2E_SKIP_PUBLIC_URL'] ?? $_SERVER['E2E_SKIP_PUBLIC_URL'] ?? ''));

if ($skipPublicUrl === '1' || $skipPublicUrl === 'true') {
    add_filter('jooservices_mcp_skip_public_url_verify', static fn (): bool => true);
}
