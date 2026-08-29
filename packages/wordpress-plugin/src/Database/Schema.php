<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Database;

final class Schema
{
    public static function install(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $connections = $wpdb->prefix . 'chatgpt_connections';
        $audit = $wpdb->prefix . 'chatgpt_audit_log';

        $sql = <<<SQL
CREATE TABLE {$connections} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    scopes LONGTEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY active (active),
    KEY token_hash (token_hash)
) {$charset};

CREATE TABLE {$audit} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(64) NULL,
    success TINYINT(1) NOT NULL,
    metadata LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY connection_id (connection_id),
    KEY created_at (created_at)
) {$charset};
SQL;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function connectionsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'chatgpt_connections';
    }

    public static function auditTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'chatgpt_audit_log';
    }
}
