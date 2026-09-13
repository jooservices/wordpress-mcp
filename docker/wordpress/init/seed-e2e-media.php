<?php

// Consumed by `wp eval-file`. Do not add declare(strict_types=1).

if (! defined('ABSPATH')) {
    exit("Run via wp eval-file\n");
}

require_once ABSPATH . 'wp-content/plugins/wordpress-chatgpt/vendor/autoload.php';

use JOOservices\WordPressMcp\Services\MediaOrphanScanner;

$upload = wp_upload_dir();
$basedir = rtrim((string) ($upload['basedir'] ?? ''), '/');
$baseurl = rtrim((string) ($upload['baseurl'] ?? ''), '/');
$relative = 'e2e/e2e-orphan.png';
$full = $basedir . '/' . $relative;
$source = '/init/fixtures/e2e-orphan.png';

if ($basedir === '') {
    echo "Uploads basedir missing\n";
    exit(1);
}

$claimed = get_posts([
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'posts_per_page' => -1,
    'meta_key' => '_wp_attached_file',
    'meta_value' => $relative,
    'fields' => 'ids',
]);

$claimedBySource = get_posts([
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'posts_per_page' => -1,
    'meta_key' => '_jooservices_orphan_source_path',
    'meta_value' => $relative,
    'fields' => 'ids',
]);

foreach (array_unique(array_map('intval', array_merge(
    is_array($claimed) ? $claimed : [],
    is_array($claimedBySource) ? $claimedBySource : [],
))) as $claimedId) {
    if ($claimedId > 0) {
        wp_delete_attachment($claimedId, true);
    }
}

if (! is_dir(dirname($full))) {
    mkdir(dirname($full), 0777, true);
}

if (! is_file($source) || ! copy($source, $full)) {
    echo "E2E orphan fixture missing at {$source}\n";
    exit(1);
}

$scanner = new MediaOrphanScanner();
$result = $scanner->runScan();
$orphanCount = count($result['orphan_files']['items'] ?? []);

$brokenId = 999001;
$src = $baseurl . '/' . $relative;
$content = '<p><img class="wp-image-' . $brokenId . '" src="' . esc_url($src) . '" alt="e2e broken" /></p>';

$postId = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'E2E broken media reference',
    'post_content' => $content,
], true);

if (is_wp_error($postId)) {
    echo 'Failed to insert broken-ref post: ' . $postId->get_error_message() . "\n";
    exit(1);
}

$commentId = wp_insert_comment([
    'comment_post_ID' => (int) $postId,
    'comment_author' => 'E2E',
    'comment_author_email' => 'e2e@example.com',
    'comment_content' => 'E2E seeded comment',
    'comment_approved' => 0,
]);

echo "E2E media seed complete orphans={$orphanCount} post={$postId} comment={$commentId}\n";
