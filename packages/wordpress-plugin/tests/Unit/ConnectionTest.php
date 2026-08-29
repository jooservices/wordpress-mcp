<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Models\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    #[Test]
    public function it_builds_from_database_row(): void
    {
        $connection = Connection::fromRow([
            'id' => '5',
            'name' => 'ChatGPT',
            'token_hash' => 'abc',
            'user_id' => '1',
            'scopes' => '["posts.read","posts.create"]',
            'active' => '1',
            'created_at' => '2026-01-01 00:00:00',
            'last_used_at' => null,
        ]);

        self::assertSame(5, $connection->id);
        self::assertSame(['posts.read', 'posts.create'], $connection->scopes);
        self::assertTrue($connection->active);
    }
}
