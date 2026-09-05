<?php

/**
 * Plugin Name: JOOservices MCP Docker helpers
 * Description: Soften verification and rate limits for Compose / E2E networking.
 */

declare(strict_types=1);

if (! defined('MCP_RATE_LIMIT_MAX')) {
    define('MCP_RATE_LIMIT_MAX', 10_000);
}

add_filter('jooservices_mcp_skip_public_url_verify', static fn (): bool => true);
