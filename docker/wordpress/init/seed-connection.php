<?php

// Note: this file is consumed by `wp eval-file`, which evaluates the file
// content directly. Keep it free of `declare(strict_types=1)` (WP-CLI 2.12+
// no longer strips the opening `<?php` tag, and strict_types must be the
// first statement in an eval'd script).

if (! defined('ABSPATH')) {
    exit("Run via wp eval-file\n");
}

require_once ABSPATH . 'wp-content/plugins/wordpress-chatgpt/vendor/autoload.php';

use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Database\Schema;

Schema::install();

global $wpdb;

$table = Schema::connectionsTable();
$token = getenv('WP_DEV_TOKEN') ?: 'dev-wp-token-local-only';
$hash = ConnectionAuthenticator::hashToken($token);

$existing = $wpdb->get_var(
    $wpdb->prepare("SELECT id FROM {$table} WHERE token_hash = %s LIMIT 1", $hash),
);

$scopes = ScopeChecker::ALL_SCOPES;

if ($existing) {
    $wpdb->update(
        $table,
        ['scopes' => wp_json_encode($scopes)],
        ['id' => (int) $existing],
        ['%s'],
        ['%d'],
    );

    echo "Updated dev connection scopes (id={$existing})\n";

    return;
}

$admin = get_user_by('login', 'admin');
$userId = $admin instanceof WP_User ? (int) $admin->ID : 1;

$wpdb->insert(
    $table,
    [
        'name' => 'ChatGPT Dev',
        'token_hash' => $hash,
        'user_id' => $userId,
        'scopes' => wp_json_encode($scopes),
        'active' => 1,
        'created_at' => current_time('mysql', true),
    ],
    ['%s', '%s', '%d', '%s', '%d', '%s'],
);

echo "Dev connection created for user {$userId}\n";
