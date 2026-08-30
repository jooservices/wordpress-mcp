<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Admin;

use JOOservices\WordPressMcp\Audit\AuditLogger;
use JOOservices\WordPressMcp\Database\Schema;

final class ConnectionManager
{
    public function revoke(int $id): bool
    {
        global $wpdb;

        $connection = $this->find($id);

        if ($connection === null || (int) $connection['active'] !== 1) {
            return false;
        }

        $updated = $wpdb->update(
            Schema::connectionsTable(),
            ['active' => 0],
            ['id' => $id],
            ['%d'],
            ['%d'],
        );

        if ($updated === false || $updated === 0) {
            return false;
        }

        (new AuditLogger())->log(
            $id,
            'admin-' . bin2hex(random_bytes(8)),
            'revoke',
            'connection',
            (string) $id,
            true,
            ['name' => $connection['name']],
        );

        return true;
    }

    public function deleteRevoked(int $id): bool
    {
        global $wpdb;

        $connection = $this->find($id);

        if ($connection === null || (int) $connection['active'] === 1) {
            return false;
        }

        $deleted = $wpdb->delete(
            Schema::connectionsTable(),
            ['id' => $id],
            ['%d'],
        );

        if ($deleted === false || $deleted === 0) {
            return false;
        }

        (new AuditLogger())->log(
            null,
            'admin-' . bin2hex(random_bytes(8)),
            'delete',
            'connection',
            (string) $id,
            true,
            ['name' => $connection['name']],
        );

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Schema::connectionsTable() . ' ORDER BY active DESC, id DESC',
            ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Schema::connectionsTable() . ' WHERE id = %d', $id),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }
}
