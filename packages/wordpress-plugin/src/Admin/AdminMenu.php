<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Admin;

use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Database\Schema;

final class AdminMenu
{
    public function register(): void
    {
        add_menu_page(
            'JOOservices ChatGPT Connector',
            'ChatGPT',
            'manage_options',
            'chatgpt-connector',
            [$this, 'renderConnections'],
            'dashicons-admin-plugins',
            80,
        );

        add_submenu_page(
            'chatgpt-connector',
            'Audit Log',
            'Audit Log',
            'manage_options',
            'chatgpt-audit',
            [$this, 'renderAudit'],
        );
    }

    public function renderConnections(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->handlePostActions();
        $connections = (new ConnectionManager())->listAll();
        $scopes = ScopeChecker::ALL_SCOPES;
        $newToken = get_transient('chatgpt_new_token_' . get_current_user_id());

        if ($newToken !== false) {
            delete_transient('chatgpt_new_token_' . get_current_user_id());
        }

        include JOOSERVICES_WORDPRESS_MCP_PATH . 'templates/admin-connections.php';
    }

    public function renderAudit(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Schema::auditTable() . ' ORDER BY id DESC LIMIT 100',
            ARRAY_A,
        );

        include JOOSERVICES_WORDPRESS_MCP_PATH . 'templates/admin-audit.php';
    }

    private function handlePostActions(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $nonce = isset($_POST['chatgpt_nonce'])
            ? sanitize_text_field((string) $_POST['chatgpt_nonce'])
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'chatgpt_connector')) {
            return;
        }

        if (isset($_POST['create_connection'])) {
            $this->createConnection();
        }

        if (isset($_POST['revoke_connection'])) {
            (new ConnectionManager())->revoke((int) $_POST['connection_id']);
        }

        if (isset($_POST['delete_connection'])) {
            (new ConnectionManager())->deleteRevoked((int) $_POST['connection_id']);
        }
    }

    private function createConnection(): void
    {
        global $wpdb;

        $name = sanitize_text_field((string) ($_POST['connection_name'] ?? 'ChatGPT'));
        $scopes = array_values(array_intersect(
            array_map(sanitize_text_field(...), (array) ($_POST['scopes'] ?? [])),
            ScopeChecker::ALL_SCOPES,
        ));

        if ($scopes === []) {
            $scopes = ['site.read', 'posts.read'];
        }

        $token = bin2hex(random_bytes(32));
        $hash = ConnectionAuthenticator::hashToken($token);

        $wpdb->insert(
            Schema::connectionsTable(),
            [
                'name' => $name,
                'token_hash' => $hash,
                'user_id' => get_current_user_id(),
                'scopes' => wp_json_encode($scopes),
                'active' => 1,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s'],
        );

        set_transient('chatgpt_new_token_' . get_current_user_id(), $token, 300);
    }
}
