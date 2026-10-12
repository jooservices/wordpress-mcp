<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class AuthAndSiteTest extends LiveTestCase
{
    #[Test]
    public function it_rejects_requests_without_a_bearer_token(): void
    {
        $response = $this->api('GET', '/site', null, false);

        self::assertSame(401, $response['status']);
    }

    #[Test]
    public function it_returns_site_info_with_upload_limits(): void
    {
        $response = $this->api('GET', '/site');

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['body']);
        self::assertArrayHasKey('limits', $response['body']);
        $limits = $response['body']['limits'];
        self::assertIsArray($limits);
        self::assertGreaterThan(0, (int) ($limits['wp_max_upload_size_bytes'] ?? 0));
    }

    #[Test]
    public function it_returns_health_and_update_status(): void
    {
        $health = $this->api('GET', '/site/health');
        self::assertSame(200, $health['status']);

        $updates = $this->api('GET', '/updates');
        self::assertSame(200, $updates['status']);
    }

    #[Test]
    public function it_reads_activity_stats_and_logs(): void
    {
        $stats = $this->api('GET', '/mcp/stats');
        self::assertSame(200, $stats['status']);

        $logs = $this->api('GET', '/mcp/logs?per_page=5');
        self::assertSame(200, $logs['status']);
    }
}
