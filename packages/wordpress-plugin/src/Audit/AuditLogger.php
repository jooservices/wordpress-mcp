<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Audit;

use JOOservices\WordPressMcp\Database\Schema;

final class AuditLogger
{
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
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
        );
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
