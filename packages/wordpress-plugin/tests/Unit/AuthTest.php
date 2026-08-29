<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory;
use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    #[Test]
    public function it_hashes_and_verifies_tokens(): void
    {
        $faker = Factory::create();
        $token = $faker->sha256();

        $hash = ConnectionAuthenticator::hashToken($token);

        self::assertTrue(ConnectionAuthenticator::verifyToken($token, $hash));
        self::assertFalse(ConnectionAuthenticator::verifyToken('wrong', $hash));
    }

    #[Test]
    public function it_checks_scopes_on_connection(): void
    {
        $connection = new Connection(
            id: 1,
            name: 'Test',
            tokenHash: 'hash',
            userId: 1,
            scopes: ['posts.read', 'posts.create'],
            active: true,
            createdAt: '2026-01-01T00:00:00Z',
            lastUsedAt: null,
        );

        self::assertTrue(ScopeChecker::hasScope($connection, 'posts.read'));
        self::assertFalse(ScopeChecker::hasScope($connection, 'posts.delete'));
        self::assertTrue(ScopeChecker::canReadContent($connection, 'post'));
        self::assertTrue(ScopeChecker::canCreateContent($connection, 'page') === false);
    }

    #[Test]
    public function it_builds_machine_readable_errors(): void
    {
        $error = ErrorCodes::error(ErrorCodes::RATE_LIMITED, 'Too many requests', 429);

        self::assertSame('RATE_LIMITED', $error['code']);
        self::assertSame(429, $error['status']);
    }
}
