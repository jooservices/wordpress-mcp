<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\RateLimit\RateLimitSettings;
use PHPUnit\Framework\TestCase;

final class RateLimitSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wp_test_options'] = [];
    }

    public function test_defaults_when_options_are_missing(): void
    {
        $this->assertTrue(RateLimitSettings::isEnabled());
        $this->assertSame(120, RateLimitSettings::maxRequests());
        $this->assertSame(60, RateLimitSettings::windowSeconds());
    }

    public function test_reads_saved_options(): void
    {
        update_option(RateLimitSettings::ENABLED_OPTION, '0');
        update_option(RateLimitSettings::MAX_OPTION, 30);
        update_option(RateLimitSettings::WINDOW_OPTION, 120);

        $this->assertFalse(RateLimitSettings::isEnabled());
        $this->assertSame(30, RateLimitSettings::maxRequests());
        $this->assertSame(120, RateLimitSettings::windowSeconds());
    }

    public function test_snapshot_returns_current_values(): void
    {
        update_option(RateLimitSettings::ENABLED_OPTION, '1');
        update_option(RateLimitSettings::MAX_OPTION, 200);
        update_option(RateLimitSettings::WINDOW_OPTION, 30);

        $this->assertSame(
            ['enabled' => true, 'max' => 200, 'window_seconds' => 30],
            RateLimitSettings::snapshot(),
        );
    }
}
