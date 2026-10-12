<?php
declare(strict_types=1);

// This file is consumed by `wp eval-file --use-include` so strict types apply.

if (! defined('ABSPATH')) {
    exit("Run via wp eval-file\n");
}

require_once ABSPATH . 'wp-content/plugins/wordpress-chatgpt/vendor/autoload.php';

use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Database\Schema;
use JOOservices\WordPressMcp\PostTemplates\PostTemplateTypes;

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
} else {
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
}

$limitedToken = getenv('WP_LIMITED_TOKEN') ?: '';
if ($limitedToken !== '') {
    $limitedLogin = 'mcp_e2e_contributor';
    $limitedUser = get_user_by('login', $limitedLogin);
    $limitedUserId = $limitedUser instanceof WP_User
        ? (int) $limitedUser->ID
        : wp_insert_user([
            'user_login' => $limitedLogin,
            'user_pass' => wp_generate_password(32, true),
            'user_email' => 'mcp-e2e-contributor@example.test',
            'role' => 'contributor',
        ]);

    if (is_wp_error($limitedUserId)) {
        throw new RuntimeException('Unable to create the live E2E contributor user.');
    }

    $limitedUser = new WP_User((int) $limitedUserId);
    $limitedUser->set_role('contributor');
    // Model a narrowly privileged uploader: upload_files is needed to exercise
    // media authorization without granting edit_others_posts.
    $limitedUser->add_cap('upload_files');
    $limitedHash = ConnectionAuthenticator::hashToken($limitedToken);
    $existingLimited = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$table} WHERE token_hash = %s LIMIT 1", $limitedHash),
    );
    $limitedScopes = [
        'posts.read',
        'posts.templates.read',
        'posts.create',
        'posts.update',
        'posts.delete',
        'posts.revisions.read',
        'terms.read',
        'media.read',
        'media.upload',
    ];

    $adminUser = get_user_by('login', 'admin');
    $templateAuthorId = $adminUser instanceof WP_User ? (int) $adminUser->ID : 1;
    $templateFixtures = [
        ['slug' => 'mcp-e2e-private-template', 'status' => 'private', 'title' => 'MCP E2E private template', 'author_id' => $templateAuthorId],
        ['slug' => 'mcp-e2e-draft-template', 'status' => 'draft', 'title' => 'MCP E2E draft template', 'author_id' => $templateAuthorId],
        ['slug' => 'mcp-e2e-published-template', 'status' => 'publish', 'title' => 'MCP E2E published template', 'author_id' => $templateAuthorId],
        ['slug' => 'mcp-e2e-contributor-draft-template', 'status' => 'draft', 'title' => 'MCP E2E contributor draft template', 'author_id' => (int) $limitedUserId],
    ];

    foreach ($templateFixtures as $fixture) {
        $existingTemplate = get_page_by_path($fixture['slug'], OBJECT, PostTemplateTypes::POST_TYPE);

        if ($existingTemplate instanceof WP_Post) {
            $templateId = wp_update_post([
                'ID' => $existingTemplate->ID,
                'post_status' => $fixture['status'],
                'post_author' => $fixture['author_id'],
            ], true);
        } else {
            $templateId = wp_insert_post([
                'post_type' => PostTemplateTypes::POST_TYPE,
                'post_status' => $fixture['status'],
                'post_title' => $fixture['title'],
                'post_name' => $fixture['slug'],
                'post_content' => $fixture['title'] . ' content: {{content}}',
                'post_author' => $fixture['author_id'],
            ], true);
        }

        if (is_wp_error($templateId) || (int) $templateId <= 0) {
            throw new RuntimeException('Unable to create an E2E post template.');
        }

        update_post_meta((int) $templateId, PostTemplateTypes::META_FOR_TYPE, 'post');
    }

    if ($existingLimited) {
        $wpdb->update(
            $table,
            ['user_id' => (int) $limitedUserId, 'scopes' => wp_json_encode($limitedScopes), 'active' => 1],
            ['id' => (int) $existingLimited],
            ['%d', '%s', '%d'],
            ['%d'],
        );

        echo "Updated limited E2E connection (id={$existingLimited})\n";
    } else {
        $wpdb->insert(
            $table,
            [
                'name' => 'MCP E2E Contributor',
                'token_hash' => $limitedHash,
                'user_id' => (int) $limitedUserId,
                'scopes' => wp_json_encode($limitedScopes),
                'active' => 1,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s'],
        );

        echo "Created limited E2E connection for user {$limitedUserId}\n";
    }
}
