<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Audit\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnauthenticatedAuditThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        delete_transient('jooservices_mcp_unauth_denied_log_throttle');
        $GLOBALS['wpdb']->inserts = [];
    }

    protected function tearDown(): void
    {
        delete_transient('jooservices_mcp_unauth_denied_log_throttle');
    }

    #[Test]
    public function it_logs_at_most_one_unauthenticated_denial_per_throttle_window(): void
    {
        $logger = new AuditLogger();

        $logger->logUnauthenticatedDenied('request-one', '/wp-json/jooservices-mcp/v1/users');
        $logger->logUnauthenticatedDenied('request-two', '/wp-json/jooservices-mcp/v1/settings');

        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('request-one', $GLOBALS['wpdb']->inserts[0]['data']['request_id']);
        self::assertSame('denied', $GLOBALS['wpdb']->inserts[0]['data']['action']);
        self::assertSame('auth', $GLOBALS['wpdb']->inserts[0]['data']['resource_type']);
        self::assertSame(
            ['reason' => 'authentication_failed'],
            json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['metadata'], true),
        );
        self::assertSame(1, get_transient('jooservices_mcp_unauth_denied_log_throttle'));
    }
}
