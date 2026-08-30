<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Database\Schema;

/**
 * Reads aggregates and rows from the audit log for the observability tools
 * (`wordpress_get_mcp_stats` / `wordpress_get_mcp_request_log`). Never reads
 * or exposes prompt/token content — the audit log never stores it.
 */
final class StatsService
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function stats(array $params): array
    {
        global $wpdb;

        $table = Schema::auditTable();
        [$where, $bindings] = $this->buildWhere($params);

        $total = (int) $this->scalar($wpdb, "SELECT COUNT(*) FROM {$table} {$where}", $bindings);
        $success = (int) $this->scalar(
            $wpdb,
            "SELECT COUNT(*) FROM {$table} {$where} " . ($where === '' ? 'WHERE' : 'AND') . ' success = 1',
            $bindings,
        );
        $avgDuration = $this->scalar($wpdb, "SELECT AVG(duration_ms) FROM {$table} {$where}", $bindings);

        $byActionSql = "SELECT action, COUNT(*) as total, SUM(success) as success_count
            FROM {$table} {$where} GROUP BY action ORDER BY total DESC";
        $byAction = $wpdb->get_results($bindings === [] ? $byActionSql : $wpdb->prepare($byActionSql, $bindings), ARRAY_A);

        return [
            'total' => $total,
            'success' => $success,
            'error' => $total - $success,
            'avg_duration_ms' => $avgDuration !== null ? (float) $avgDuration : null,
            'by_action' => array_map(
                static fn(array $row): array => [
                    'action' => (string) $row['action'],
                    'total' => (int) $row['total'],
                    'success' => (int) $row['success_count'],
                ],
                $byAction ?: [],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function logs(array $params): array
    {
        global $wpdb;

        $table = Schema::auditTable();
        [$where, $bindings] = $this->buildWhere($params);

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->scalar($wpdb, "SELECT COUNT(*) FROM {$table} {$where}", $bindings);

        $rowsSql = "SELECT id, request_id, action, resource_type, resource_id, success, duration_ms, created_at
            FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rowsSql, [...$bindings, $perPage, $offset]), ARRAY_A);

        return [
            'items' => array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'request_id' => (string) $row['request_id'],
                    'action' => (string) $row['action'],
                    'resource_type' => (string) $row['resource_type'],
                    'resource_id' => $row['resource_id'],
                    'success' => (bool) $row['success'],
                    'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
                    'created_at' => (string) $row['created_at'],
                ],
                $rows ?: [],
            ),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /**
     * All rows matching the filters, for the admin CSV export — not paged,
     * capped at a hard ceiling so an unbounded export can't exhaust memory.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function exportRows(array $params): array
    {
        global $wpdb;

        $table = Schema::auditTable();
        [$where, $bindings] = $this->buildWhere($params);
        $cap = 10000;

        $sql = "SELECT id, connection_id, request_id, action, resource_type, resource_id, success, duration_ms, created_at
            FROM {$table} {$where} ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, [...$bindings, $cap]), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * @param list<mixed> $bindings
     */
    private function scalar(\wpdb $wpdb, string $sql, array $bindings): mixed
    {
        return $wpdb->get_var($bindings === [] ? $sql : $wpdb->prepare($sql, $bindings));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $params): array
    {
        $clauses = [];
        $bindings = [];

        if (! empty($params['action'])) {
            $clauses[] = 'action = %s';
            $bindings[] = sanitize_key((string) $params['action']);
        }

        if (! empty($params['resource_type'])) {
            $clauses[] = 'resource_type = %s';
            $bindings[] = sanitize_key((string) $params['resource_type']);
        }

        if (! empty($params['since'])) {
            $clauses[] = 'created_at >= %s';
            $bindings[] = sanitize_text_field((string) $params['since']);
        }

        if (! empty($params['until'])) {
            $clauses[] = 'created_at <= %s';
            $bindings[] = sanitize_text_field((string) $params['until']);
        }

        if ($clauses === []) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $clauses), $bindings];
    }
}
