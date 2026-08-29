<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Auth;

use JOOservices\WordPressMcp\Database\Schema;
use JOOservices\WordPressMcp\Models\Connection;

final class ConnectionAuthenticator
{
    private static ?Connection $current = null;

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function current(): ?Connection
    {
        return self::$current;
    }

    public static function authenticateFromRequest(?string $authorizationHeader): ?Connection
    {
        $token = self::extractBearerToken($authorizationHeader);

        if ($token === null) {
            return null;
        }

        $connection = self::findByToken($token);

        if ($connection === null) {
            return null;
        }

        self::$current = $connection;
        wp_set_current_user($connection->userId);
        self::touchLastUsed($connection->id);

        return $connection;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function verifyToken(string $token, string $hash): bool
    {
        return hash_equals($hash, self::hashToken($token));
    }

    private static function extractBearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        if (! preg_match('/^Bearer\s+(\S+)$/i', trim($authorizationHeader), $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function findByToken(string $token): ?Connection
    {
        global $wpdb;

        $table = Schema::connectionsTable();
        $hash = self::hashToken($token);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s AND active = 1 LIMIT 1", $hash),
            ARRAY_A,
        );

        if (! is_array($row)) {
            return null;
        }

        return Connection::fromRow($row);
    }

    private static function touchLastUsed(int $connectionId): void
    {
        global $wpdb;

        $table = Schema::connectionsTable();
        $wpdb->update(
            $table,
            ['last_used_at' => current_time('mysql', true)],
            ['id' => $connectionId],
            ['%s'],
            ['%d'],
        );
    }
}
