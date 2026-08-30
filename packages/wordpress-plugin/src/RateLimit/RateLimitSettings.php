<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\RateLimit;

final class RateLimitSettings
{
    public const ENABLED_OPTION = 'jooservices_mcp_rate_limit_enabled';

    public const MAX_OPTION = 'jooservices_mcp_rate_limit_max';

    public const WINDOW_OPTION = 'jooservices_mcp_rate_limit_window_seconds';

    private const DEFAULT_MAX = 120;

    private const DEFAULT_WINDOW_SECONDS = 60;

    public static function isEnabled(): bool
    {
        if (defined('MCP_RATE_LIMIT_ENABLED')) {
            return (bool) constant('MCP_RATE_LIMIT_ENABLED');
        }

        $stored = get_option(self::ENABLED_OPTION, '1');

        return $stored === '1' || $stored === 1 || $stored === true;
    }

    public static function maxRequests(): int
    {
        if (defined('MCP_RATE_LIMIT_MAX')) {
            return max(1, (int) constant('MCP_RATE_LIMIT_MAX'));
        }

        return max(1, (int) get_option(self::MAX_OPTION, self::DEFAULT_MAX));
    }

    public static function windowSeconds(): int
    {
        if (defined('MCP_RATE_LIMIT_WINDOW_SECONDS')) {
            return max(1, (int) constant('MCP_RATE_LIMIT_WINDOW_SECONDS'));
        }

        return max(1, (int) get_option(self::WINDOW_OPTION, self::DEFAULT_WINDOW_SECONDS));
    }

    /**
     * @return array{enabled: bool, max: int, window_seconds: int}
     */
    public static function snapshot(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'max' => self::maxRequests(),
            'window_seconds' => self::windowSeconds(),
        ];
    }
}
