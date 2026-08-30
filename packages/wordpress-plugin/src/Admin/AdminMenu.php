<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Admin;

use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Database\Schema;
use JOOservices\WordPressMcp\RateLimit\RateLimitSettings;
use JOOservices\WordPressMcp\Services\StatsService;

final class AdminMenu
{
    private const EXPORT_ACTION = 'chatgpt_export_audit_log';

    public function register(): void
    {
        add_menu_page(
            'JOOservices',
            'JOOservices',
            'manage_options',
            'jooservices',
            [$this, 'renderDashboard'],
            'dashicons-admin-generic',
            80,
        );

        add_submenu_page(
            'jooservices',
            'MCP',
            'MCP',
            'manage_options',
            'chatgpt-connector',
            [$this, 'renderConnections'],
        );

        add_submenu_page(
            'jooservices',
            'Audit Log',
            'Audit Log',
            'manage_options',
            'chatgpt-audit',
            [$this, 'renderAudit'],
        );

        add_submenu_page(
            'jooservices',
            'Settings',
            'Settings',
            'manage_options',
            'chatgpt-settings',
            [$this, 'renderSettings'],
        );
    }

    public function renderDashboard(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        include JOOSERVICES_WORDPRESS_MCP_PATH . 'templates/admin-dashboard.php';
    }

    public function registerPostHandlers(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'exportAuditLog']);
        add_action('admin_post_chatgpt_save_settings', [$this, 'saveSettings']);
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

        $filters = $this->auditFilters();
        $service = new StatsService();
        $logs = $service->logs($filters);
        $stats = $service->stats($filters);
        $rows = $logs['items'];
        $exportUrl = wp_nonce_url(
            add_query_arg(array_merge($filters, ['action' => self::EXPORT_ACTION]), admin_url('admin-post.php')),
            self::EXPORT_ACTION,
        );

        include JOOSERVICES_WORDPRESS_MCP_PATH . 'templates/admin-audit.php';
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = RateLimitSettings::snapshot();
        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === '1';

        include JOOSERVICES_WORDPRESS_MCP_PATH . 'templates/admin-settings.php';
    }

    public function saveSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('chatgpt_save_settings');

        if (defined('MCP_RATE_LIMIT_ENABLED') || defined('MCP_RATE_LIMIT_MAX') || defined('MCP_RATE_LIMIT_WINDOW_SECONDS')) {
            wp_safe_redirect(add_query_arg('settings-error', 'constants', admin_url('admin.php?page=chatgpt-settings')));
            exit;
        }

        $enabled = isset($_POST['rate_limit_enabled']) ? '1' : '0';
        $max = max(1, (int) ($_POST['rate_limit_max'] ?? RateLimitSettings::maxRequests()));
        $window = max(1, (int) ($_POST['rate_limit_window_seconds'] ?? RateLimitSettings::windowSeconds()));

        update_option(RateLimitSettings::ENABLED_OPTION, $enabled);
        update_option(RateLimitSettings::MAX_OPTION, $max);
        update_option(RateLimitSettings::WINDOW_OPTION, $window);

        wp_safe_redirect(add_query_arg('settings-updated', '1', admin_url('admin.php?page=chatgpt-settings')));
        exit;
    }

    public function exportAuditLog(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer(self::EXPORT_ACTION);

        $rows = (new StatsService())->exportRows($this->auditFilters());

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mcp-audit-log.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'id', 'connection_id', 'request_id', 'action',
            'resource_type', 'resource_id', 'success', 'duration_ms', 'created_at',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['connection_id'] ?? '',
                $row['request_id'],
                $row['action'],
                $row['resource_type'],
                $row['resource_id'] ?? '',
                (int) $row['success'] === 1 ? 'yes' : 'no',
                $row['duration_ms'] ?? '',
                $row['created_at'],
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * @return array<string, string>
     */
    private function auditFilters(): array
    {
        $filters = [];

        foreach (['action', 'resource_type', 'since', 'until'] as $key) {
            $value = isset($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : '';

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
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
