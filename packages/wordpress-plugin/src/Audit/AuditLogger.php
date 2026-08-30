<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Audit;

use JOOservices\WordPressMcp\Database\Schema;

final class AuditLogger
{
    private const RETENTION_OPTION = 'jooservices_mcp_log_retention_days';

    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        ?int $connectionId,
        string $requestId,
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $success,
        ?array $metadata = null,
        ?int $durationMs = null,
    ): void {
        global $wpdb;

        if ($metadata !== null) {
            $metadata = $this->truncateMetadata($metadata);
        }

        $wpdb->insert(
            Schema::auditTable(),
            [
                'connection_id' => $connectionId,
                'request_id' => sanitize_text_field($requestId),
                'action' => sanitize_key($action),
                'resource_type' => sanitize_key($resourceType),
                'resource_id' => $resourceId !== null ? sanitize_text_field($resourceId) : null,
                'success' => $success ? 1 : 0,
                'metadata' => $metadata !== null ? wp_json_encode($metadata) : null,
                'duration_ms' => $durationMs,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s'],
        );
    }

    public static function retentionDays(): int
    {
        if (defined('MCP_LOG_RETENTION_DAYS')) {
            return max(1, (int) constant('MCP_LOG_RETENTION_DAYS'));
        }

        return max(1, (int) get_option(self::RETENTION_OPTION, self::DEFAULT_RETENTION_DAYS));
    }

    /**
     * Deletes audit log rows older than the retention window. Hooked to a
     * daily cron event (see `Plugin::registerHooks()`); never triggered from
     * the MCP-facing REST surface.
     */
    public function purgeOlderThan(?int $days = null): int
    {
        global $wpdb;

        $days = $days ?? self::retentionDays();
        $table = Schema::auditTable();
        $secondsPerDay = 86400;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * $secondsPerDay));

        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function truncateMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($value) && strlen($value) > 500) {
                $metadata[$key] = substr($value, 0, 500) . '…';
            }
        }

        return $metadata;
    }
}
