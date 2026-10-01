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
    public function it_rejects_missing_or_malformed_bearer_headers(): void
    {
        ConnectionAuthenticator::reset();

        self::assertNull(ConnectionAuthenticator::authenticateFromRequest(null));
        self::assertNull(ConnectionAuthenticator::authenticateFromRequest('Basic invalid'));
        self::assertNull(ConnectionAuthenticator::current());
    }

    #[Test]
    public function it_authenticates_a_connection_and_updates_last_used(): void
    {
        $faker = Factory::create();
        $token = $faker->sha256();
        $hadWpdb = array_key_exists('wpdb', $GLOBALS);
        $previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class (ConnectionAuthenticator::hashToken($token)) {
            public string $prefix = 'wp_';

            public function __construct(private readonly string $hash)
            {
            }

            public function prepare(string $query, string $hash): string
            {
                return $query . $hash;
            }

            public function get_row(string $query, string $output): array
            {
                return [
                    'id' => '7',
                    'name' => 'Test connection',
                    'token_hash' => $this->hash,
                    'user_id' => '9',
                    'scopes' => '["posts.read"]',
                    'active' => '1',
                    'created_at' => '2026-01-01 00:00:00',
                    'last_used_at' => null,
                ];
            }

            public function update(string $table, array $data, array $where, array $format, array $whereFormat): int
            {
                return 1;
            }
        };

        try {
            $connection = ConnectionAuthenticator::authenticateFromRequest('Bearer ' . $token);

            self::assertInstanceOf(Connection::class, $connection);
            self::assertSame($connection, ConnectionAuthenticator::current());
            self::assertSame(9, $GLOBALS['wp_test_current_user']);
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $previousWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
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
