<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_transient('chatgpt_rl_99');
    }

    protected function tearDown(): void
    {
        delete_transient('chatgpt_rl_99');
        parent::tearDown();
    }

    public function test_allows_requests_under_limit(): void
    {
        $limiter = new RateLimiter(limit: 3, windowSeconds: 60);

        $this->assertTrue($limiter->isAllowed(99));
        $this->assertTrue($limiter->isAllowed(99));
        $this->assertTrue($limiter->isAllowed(99));
        $this->assertFalse($limiter->isAllowed(99));
    }
}
