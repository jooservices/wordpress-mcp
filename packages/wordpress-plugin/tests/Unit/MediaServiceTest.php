<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\MediaService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    private const MINIMAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $GLOBALS['wp_test_max_upload_size'] = 32;
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_posts'] = [];
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_post_titles'] = [];
        $GLOBALS['wp_test_post_fields'] = [];
        $GLOBALS['wp_test_attachment_files'] = [];
        $GLOBALS['wp_test_attachment_urls'] = [];
        $GLOBALS['wp_test_attachment_mimes'] = [];
        $GLOBALS['wp_test_attachment_metadata'] = [];
        $GLOBALS['wp_test_scale_rename'] = [];
        $GLOBALS['wp_test_title_save_transform'] = [];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_next_attachment_id'] = 9000;

        add_filter('jooservices_mcp_skip_public_url_verify', static fn(): bool => true);
    }

    private function writeMinimalPng(string $path): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $png);
    }

    #[Test]
    public function it_rejects_oversized_uploads_before_writing_files(): void
    {
        $service = new MediaService();
        $result = $service->upload([
            'file_name' => 'large.png',
            'content_base64' => base64_encode(str_repeat('a', 64)),
        ]);

        self::assertSame(ErrorCodes::MEDIA_UPLOAD_LIMIT_EXCEEDED, $result['error']);
        self::assertSame('pre_validate.upload_limit', $result['error_step']);
    }

    #[Test]
    public function it_rejects_corrupted_image_bytes_before_upload(): void
    {
        $service = new MediaService();
        $result = $service->upload([
            'file_name' => 'broken.png',
            'content_base64' => base64_encode('not-a-png'),
        ]);

        self::assertSame(ErrorCodes::MEDIA_VERIFY_FAILED, $result['error']);
        self::assertContains($result['error_step'], ['pre_validate.mime', 'pre_validate.mime_not_allowed']);
        self::assertNull($result['media']);
    }

    #[Test]
    public function it_rejects_truncated_png_bytes(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);

        $service = new MediaService();
        $result = $service->upload([
            'file_name' => 'truncated.png',
            'content_base64' => base64_encode(substr($png, 0, 16)),
        ]);

        self::assertSame(ErrorCodes::MEDIA_VERIFY_FAILED, $result['error']);
        self::assertSame('pre_validate.decode', $result['error_step']);
    }

    #[Test]
    public function it_returns_the_existing_attachment_when_the_same_file_is_uploaded_again_for_the_same_post(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $path = sys_get_temp_dir() . '/jooservices-mcp-test-uploads/replay-' . uniqid() . '.png';
        $this->writeMinimalPng($path);

        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        $GLOBALS['wp_test_posts'] = [
            70 => new \WP_Post(70, '', 'post'),
            9100 => new \WP_Post(9100, '', 'attachment'),
        ];
        $GLOBALS['wp_test_posts'][9100]->post_status = 'inherit';
        $GLOBALS['wp_test_attachment_files'][9100] = $path;
        $GLOBALS['wp_test_attachment_urls'][9100] = 'https://example.test/wp-content/uploads/replay.png';
        $GLOBALS['wp_test_attachment_mimes'][9100] = 'image/png';
        $GLOBALS['wp_test_attachment_metadata'][9100] = ['width' => 1, 'height' => 1, 'sizes' => []];
        $GLOBALS['wp_test_postmeta'][9100] = [
            '_mcp_media_upload_sha256' => hash('sha256', $png),
            '_mcp_media_upload_post_id' => '70',
        ];

        $result = (new MediaService())->upload([
            'file_name' => 'replay.png',
            'content_base64' => base64_encode($png),
            'post_id' => 70,
            'set_featured' => true,
        ]);

        self::assertNull($result['error']);
        self::assertSame(9100, $result['media']['id']);
        self::assertTrue($result['media']['idempotent_replay']);
        self::assertTrue($result['verification']['featured_set']);
        self::assertSame(9100, $GLOBALS['wp_test_thumbnails'][70]);
        self::assertCount(2, $GLOBALS['wp_test_posts'], 'A retry must not create a duplicate attachment.');

        unlink($path);
    }

    #[Test]
    public function it_returns_the_existing_attachment_when_the_same_file_is_retried_without_a_post(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $path = sys_get_temp_dir() . '/jooservices-mcp-test-uploads/nopost-replay-' . uniqid() . '.png';
        $this->writeMinimalPng($path);

        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        $GLOBALS['wp_test_sideload_calls'] = 0;
        $GLOBALS['wp_test_posts'][9100] = new \WP_Post(9100, '', 'attachment');
        $GLOBALS['wp_test_posts'][9100]->post_status = 'inherit';
        $GLOBALS['wp_test_attachment_files'][9100] = $path;
        $GLOBALS['wp_test_attachment_urls'][9100] = 'https://example.test/wp-content/uploads/nopost-replay.png';
        $GLOBALS['wp_test_attachment_mimes'][9100] = 'image/png';
        $GLOBALS['wp_test_attachment_metadata'][9100] = ['width' => 1, 'height' => 1, 'sizes' => []];
        $GLOBALS['wp_test_postmeta'][9100] = [
            '_mcp_media_upload_sha256' => hash('sha256', $png),
            '_mcp_media_upload_post_id' => '0',
        ];

        $result = (new MediaService())->upload(['file_name' => 'nopost.png', 'content_base64' => base64_encode($png)]);

        self::assertNull($result['error']);
        self::assertSame(9100, $result['media']['id']);
        self::assertTrue($result['media']['idempotent_replay']);
        self::assertSame(0, $GLOBALS['wp_test_sideload_calls'], 'A retry without a post must reuse the stored attachment.');
        self::assertCount(1, $GLOBALS['wp_test_posts'], 'A retry without a post must not create a duplicate attachment.');

        unlink($path);
    }

    #[Test]
    public function it_rejects_replay_when_the_stored_file_no_longer_matches_the_upload_key(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        $chunkData = 'changed';
        $chunk = pack('N', strlen($chunkData)) . 'tEXt' . $chunkData . pack('N', crc32('tEXt' . $chunkData));
        $changedPng = substr($png, 0, -12) . $chunk . substr($png, -12);
        $path = sys_get_temp_dir() . '/jooservices-mcp-test-uploads/replay-mutated-' . uniqid() . '.png';
        $this->writeMinimalPng($path);
        file_put_contents($path, $changedPng);

        $GLOBALS['wp_test_posts'][9100] = new \WP_Post(9100, '', 'attachment');
        $GLOBALS['wp_test_posts'][9100]->post_status = 'inherit';
        $GLOBALS['wp_test_attachment_files'][9100] = $path;
        $GLOBALS['wp_test_attachment_urls'][9100] = 'https://example.test/wp-content/uploads/replay-mutated.png';
        $GLOBALS['wp_test_attachment_mimes'][9100] = 'image/png';
        $GLOBALS['wp_test_attachment_metadata'][9100] = ['width' => 1, 'height' => 1, 'sizes' => []];
        $GLOBALS['wp_test_postmeta'][9100] = [
            '_mcp_media_upload_sha256' => hash('sha256', $png),
            '_mcp_media_upload_post_id' => '70',
        ];

        $result = (new MediaService())->upload([
            'file_name' => 'replay.png',
            'content_base64' => base64_encode($png),
            'post_id' => 70,
        ]);

        self::assertSame(ErrorCodes::MEDIA_VERIFY_FAILED, $result['error']);
        self::assertSame('post_validate.sha256_mismatch', $result['error_step']);
        self::assertFalse($result['verification']['sha256_match']);
        self::assertNull($result['media']);
        self::assertArrayHasKey(9100, $GLOBALS['wp_test_posts'], 'A replay checksum mismatch must not delete the stored attachment.');

        unlink($path);
    }

    #[Test]
    public function it_rejects_an_overlapping_retry_without_a_post_while_the_upload_key_is_reserved(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        add_filter('jooservices_mcp_upload_lock_wait_ms', static fn(): int => 0);

        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', hash('sha256', $png) . ':0'), 0, 40);
        $GLOBALS['wp_test_options'][$lockName] = (string) time();

        $result = (new MediaService())->upload([
            'file_name' => 'racing.png',
            'content_base64' => base64_encode($png),
        ]);

        self::assertNull($result['media']);
        self::assertSame(ErrorCodes::MEDIA_UPLOAD_IN_PROGRESS, $result['error']);
        self::assertSame('upload.in_progress', $result['error_step']);
        self::assertCount(0, $GLOBALS['wp_test_posts'], 'An overlapping retry must not create an attachment.');
        self::assertArrayHasKey($lockName, $GLOBALS['wp_test_options'], 'The holder keeps its lock.');
    }

    #[Test]
    public function it_takes_over_a_stale_upload_lock_and_releases_it_afterwards(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;

        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', hash('sha256', $png) . ':70'), 0, 40);
        $GLOBALS['wp_test_options'][$lockName] = (string) (time() - 3600);

        $GLOBALS['wp_test_sideload_calls'] = 0;

        $result = (new MediaService())->upload([
            'file_name' => 'stale.png',
            'post_id' => 70,
            'content_base64' => base64_encode($png),
        ]);

        self::assertSame('upload.sideload', $result['error_step']);
        self::assertSame(1, $GLOBALS['wp_test_sideload_calls'], 'A stale lock must not block the upload.');
        self::assertArrayNotHasKey($lockName, $GLOBALS['wp_test_options']);
    }

    #[Test]
    public function concurrent_stale_lock_contenders_allow_only_one_upload_to_claim_the_key(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        add_filter('jooservices_mcp_upload_lock_wait_ms', static fn(): int => 0);

        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', hash('sha256', $png) . ':70'), 0, 40);
        $staleValue = (string) (time() - 3600);
        $GLOBALS['wp_test_options'][$lockName] = $staleValue;
        $GLOBALS['wp_test_sideload_calls'] = 0;
        $payload = ['file_name' => 'stale-race.png', 'post_id' => 70, 'content_base64' => base64_encode($png)];
        $activeLockValue = 'second-owner|' . (time() + 300);
        $contender = null;

        // The outer request has already read the stale value when this hook
        // starts a second retry. Keep the second retry's claimed lock in place
        // to model it continuing through sideload while the first resumes.
        $GLOBALS['wp_test_get_option_hook'] = static function (
            string $name,
            mixed $observedValue,
        ) use ($lockName, $staleValue, $payload, $activeLockValue, &$contender): void {
            if ($name !== $lockName || $observedValue !== $staleValue) {
                return;
            }

            unset($GLOBALS['wp_test_get_option_hook']);
            $GLOBALS['wp_test_sideload_hook'] = static function () use ($lockName, $activeLockValue): void {
                unset($GLOBALS['wp_test_sideload_hook']);
                $GLOBALS['wp_test_options'][$lockName] = $activeLockValue;
            };
            $contender = (new MediaService())->upload($payload);
        };

        $outer = (new MediaService())->upload($payload);

        self::assertIsArray($contender);
        self::assertSame('upload.sideload', $contender['error_step']);
        self::assertSame(ErrorCodes::MEDIA_UPLOAD_IN_PROGRESS, $outer['error']);
        self::assertSame(1, $GLOBALS['wp_test_sideload_calls'], 'Only the stale-lock winner may sideload.');
        self::assertSame($activeLockValue, $GLOBALS['wp_test_options'][$lockName]);
    }

    #[Test]
    public function it_does_not_let_a_retry_take_over_the_lock_of_an_active_upload(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        add_filter('jooservices_mcp_upload_lock_wait_ms', static fn(): int => 0);
        $GLOBALS['wp_test_sideload_calls'] = 0;

        $payload = ['file_name' => 'active.png', 'post_id' => 70, 'content_base64' => base64_encode($png)];
        $retry = null;
        $GLOBALS['wp_test_sideload_hook'] = static function () use (&$retry, $payload): void {
            // The first request is mid-sideload with its idempotency meta not stored yet.
            $retry = (new MediaService())->upload($payload);
        };

        try {
            (new MediaService())->upload($payload);
        } finally {
            unset($GLOBALS['wp_test_sideload_hook']);
        }

        self::assertSame(ErrorCodes::MEDIA_UPLOAD_IN_PROGRESS, $retry['error']);
        self::assertSame('upload.in_progress', $retry['error_step']);
        self::assertSame(1, $GLOBALS['wp_test_sideload_calls'], 'Only one sideload may run.');
    }

    #[Test]
    public function it_only_releases_a_lock_it_still_owns(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;

        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', hash('sha256', $png) . ':70'), 0, 40);
        $foreign = 'other-owner|' . (time() + 300);
        $GLOBALS['wp_test_sideload_hook'] = static function () use ($lockName, $foreign): void {
            // A takeover replaced the lock while this request was still running.
            $GLOBALS['wp_test_options'][$lockName] = $foreign;
        };

        try {
            (new MediaService())->upload(['file_name' => 'owner.png', 'post_id' => 70, 'content_base64' => base64_encode($png)]);
        } finally {
            unset($GLOBALS['wp_test_sideload_hook']);
        }

        self::assertSame($foreign, $GLOBALS['wp_test_options'][$lockName] ?? null, 'Another owner\'s lock must survive.');
    }

    #[Test]
    public function a_request_that_loses_its_lock_during_metadata_generation_reconciles_to_a_real_retry(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $sha = hash('sha256', $png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        add_filter('jooservices_mcp_upload_lock_wait_ms', static fn(): int => 0);

        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', $sha . ':70'), 0, 40);
        $GLOBALS['wp_test_posts'] = [70 => new \WP_Post(70, '', 'post')];
        $GLOBALS['wp_test_deleted_attachments'] = [];
        $GLOBALS['wp_test_sideload_creates'] = true;
        $retryResult = null;
        $payload = ['file_name' => 'slow.png', 'post_id' => 70, 'content_base64' => base64_encode($png)];
        // Expire the first request's lease during metadata generation, then
        // run a real retry that claims the stale lock and stores the key.
        $GLOBALS['wp_test_metadata_hook'] = static function () use ($lockName, $payload, &$retryResult): void {
            unset($GLOBALS['wp_test_metadata_hook']);
            $GLOBALS['wp_test_options'][$lockName] = (string) (time() - 3600);
            $retryResult = (new MediaService())->upload($payload);
        };

        try {
            $result = (new MediaService())->upload($payload);
        } finally {
            unset($GLOBALS['wp_test_metadata_hook'], $GLOBALS['wp_test_sideload_creates']);
        }

        self::assertIsArray($retryResult);
        self::assertNull($retryResult['error']);
        $winnerId = (int) $retryResult['media']['id'];
        self::assertNull($result['error']);
        self::assertSame($winnerId, $result['media']['id']);
        self::assertTrue($result['media']['idempotent_replay']);
        self::assertCount(1, $GLOBALS['wp_test_deleted_attachments'], 'The stale request discards its own attachment.');
        self::assertNotContains($winnerId, $GLOBALS['wp_test_deleted_attachments']);
        $attachments = array_filter($GLOBALS['wp_test_posts'], static fn(mixed $p): bool => $p instanceof \WP_Post && $p->post_type === 'attachment');
        self::assertCount(1, $attachments, 'Only the retry\'s attachment remains.');
        self::assertArrayNotHasKey($lockName, $GLOBALS['wp_test_options'], 'The retry releases its lock.');

        foreach ($GLOBALS['wp_test_attachment_files'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function it_sizes_the_lock_lease_to_outlast_the_php_execution_limit(): void
    {
        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        $GLOBALS['wp_test_max_upload_size'] = 1_000_000;
        $lockName = 'jooservices_mcp_upload_lock_' . substr(hash('sha256', hash('sha256', $png) . ':70'), 0, 40);
        $seen = null;
        $GLOBALS['wp_test_sideload_hook'] = static function () use ($lockName, &$seen): void {
            $seen = (int) explode('|', (string) $GLOBALS['wp_test_options'][$lockName], 2)[1];
        };
        $previous = ini_set('max_execution_time', '900');

        try {
            (new MediaService())->upload(['file_name' => 'lease.png', 'post_id' => 70, 'content_base64' => base64_encode($png)]);
        } finally {
            unset($GLOBALS['wp_test_sideload_hook']);
            ini_set('max_execution_time', (string) $previous);
        }

        self::assertNotNull($seen);
        self::assertGreaterThanOrEqual(time() + 900, $seen);
    }

    #[Test]
    public function it_keeps_the_media_and_reports_when_setting_the_featured_image_fails(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/01/featured-' . uniqid() . '.png';
        $this->writeMinimalPng($basedir . '/' . $relative);

        $GLOBALS['wp_test_posts'][70] = new \WP_Post(70, '', 'post');
        $GLOBALS['wp_test_set_thumbnail_fails'] = true;
        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        try {
            $result = (new MediaService())->adoptOrphan([
                'path' => $relative,
                'post_id' => 70,
                'set_featured' => true,
            ]);
        } finally {
            unset($GLOBALS['wp_test_set_thumbnail_fails']);
        }

        self::assertNull($result['error']);
        self::assertNotNull($result['media']);
        self::assertTrue($result['verification']['passed']);
        self::assertFalse($result['verification']['featured_set']);
        self::assertSame(ErrorCodes::WORDPRESS_ERROR, $result['verification']['featured_error']);
        self::assertSame('featured.set_failed', $result['verification']['featured_step']);
        self::assertArrayHasKey((int) $result['media']['id'], $GLOBALS['wp_test_posts']);
    }

    #[Test]
    public function it_rejects_adopt_without_path_or_url(): void
    {
        $service = new MediaService();
        $result = $service->adoptOrphan([]);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame('pre_validate.input', $result['error_step']);
        self::assertNull($result['media']);
    }

    #[Test]
    public function it_rejects_adopt_for_a_path_the_orphan_scan_never_reported(): void
    {
        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => '2025/01/known.png', 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => '2025/01/never-scanned.png']);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame('pre_validate.not_orphan', $result['error_step']);
        self::assertNull($result['media']);
    }

    #[Test]
    public function it_rejects_adopt_when_the_matched_orphan_file_is_missing_on_disk(): void
    {
        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => '2025/01/gone.png', 'url' => 'https://example.test/wp-content/uploads/2025/01/gone.png']], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['url' => 'https://example.test/wp-content/uploads/2025/01/gone.png']);

        self::assertSame(ErrorCodes::MEDIA_NOT_FOUND, $result['error']);
        self::assertSame('pre_validate.missing_file', $result['error_step']);
        self::assertNull($result['media']);
    }

    #[Test]
    public function it_rejects_adopt_of_a_matched_orphan_whose_bytes_are_not_a_valid_image(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/01/corrupted-' . uniqid() . '.png';
        $full = $basedir . '/' . $relative;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, 'not-a-png');

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        unlink($full);

        self::assertSame(ErrorCodes::MEDIA_VERIFY_FAILED, $result['error']);
        self::assertContains($result['error_step'], ['post_validate.mime', 'post_validate.mime_not_allowed']);
        self::assertNull($result['media']);
    }

    #[Test]
    public function it_reuses_the_same_attachment_when_the_orphan_path_is_re_adopted_after_wordpress_renames_it_to_scaled(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/01/photo-' . uniqid() . '.png';
        $full = $basedir . '/' . $relative;
        $this->writeMinimalPng($full);

        $scaledRelative = '2025/01/photo-' . uniqid() . '-scaled.png';
        $scaledFull = $basedir . '/' . $scaledRelative;
        $this->writeMinimalPng($scaledFull);

        // Simulates WordPress's own big-image handling: generating metadata
        // for this file rewrites `_wp_attached_file` to the scaled variant.
        $GLOBALS['wp_test_scale_rename'][$full] = $scaledFull;

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $first = $service->adoptOrphan(['path' => $relative]);

        self::assertNull($first['error']);
        self::assertNotNull($first['media']);
        $firstId = $first['media']['id'];

        // The orphan cache only refreshes daily; simulate it still listing
        // the original (now stale) path when the adopt is retried.
        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans']['orphan_files']['items']
            = [['path' => $relative, 'url' => null]];

        $second = $service->adoptOrphan(['path' => $relative]);

        self::assertNull($second['error']);
        self::assertNotNull($second['media']);
        self::assertSame($firstId, $second['media']['id'], 'Re-adopting the same orphan path must return the existing attachment, not create a duplicate.');
        self::assertCount(1, $GLOBALS['wp_test_posts']);
    }

    #[Test]
    public function it_removes_the_adopted_path_from_the_orphan_cache_after_a_successful_adopt(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/01/photo-' . uniqid() . '.png';
        $full = $basedir . '/' . $relative;
        $this->writeMinimalPng($full);

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        self::assertNull($result['error']);

        $cached = $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'];
        self::assertSame([], $cached['orphan_files']['items']);

        $second = $service->adoptOrphan(['path' => $relative]);
        self::assertNull($second['error'], (string) $second['error_step']);
        self::assertSame($result['media']['id'], $second['media']['id']);
    }

    private function registerPreFixScaledAttachment(int $id, string $scaledRelative, ?string $originalImageBasename): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $full = $basedir . '/' . $scaledRelative;
        $this->writeMinimalPng($full);

        $GLOBALS['wp_test_posts'][$id] = new \WP_Post($id, '', 'attachment');
        $GLOBALS['wp_test_post_titles'][$id] = 'photo';
        $GLOBALS['wp_test_attachment_mimes'][$id] = 'image/png';
        $GLOBALS['wp_test_attachment_files'][$id] = $full;
        $GLOBALS['wp_test_attachment_urls'][$id] = 'https://example.test/wp-content/uploads/' . $scaledRelative;
        update_post_meta($id, '_wp_attached_file', $scaledRelative);

        $metadata = ['width' => 100, 'height' => 100, 'sizes' => ['full' => ['file' => basename($scaledRelative)]]];

        if ($originalImageBasename !== null) {
            $metadata['original_image'] = $originalImageBasename;
        }

        $GLOBALS['wp_test_attachment_metadata'][$id] = $metadata;
    }

    #[Test]
    public function it_matches_a_pre_fix_scaled_attachment_via_wordpress_own_original_image_record(): void
    {
        $relative = '2025/01/photo-' . uniqid() . '.png';
        $scaledRelative = '2025/01/' . pathinfo($relative, PATHINFO_FILENAME) . '-scaled.png';

        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $this->writeMinimalPng($basedir . '/' . $relative);

        // No SOURCE_PATH_META_KEY here — simulates an attachment adopted
        // before this fix existed, relying only on WordPress's own
        // `original_image` breadcrumb from when it scaled the file down.
        $this->registerPreFixScaledAttachment(5000, $scaledRelative, basename($relative));

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        self::assertNull($result['error']);
        self::assertNotNull($result['media']);
        self::assertSame(5000, $result['media']['id']);
        self::assertCount(1, $GLOBALS['wp_test_posts'], 'Must resolve to the pre-existing attachment, not insert a duplicate.');
    }

    #[Test]
    public function it_refuses_to_adopt_when_the_scaled_variant_path_belongs_to_an_unrelated_attachment(): void
    {
        $relative = '2025/01/photo-' . uniqid() . '.png';
        $scaledRelative = '2025/01/' . pathinfo($relative, PATHINFO_FILENAME) . '-scaled.png';

        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $this->writeMinimalPng($basedir . '/' . $relative);

        // Same _wp_attached_file naming coincidence as the real scaling
        // case, but this attachment's own original filename was something
        // else entirely — it must not be treated as the same media. Nor may
        // adoption proceed to a new attachment: WordPress's scaling would
        // overwrite this unrelated attachment's actual file on disk.
        $this->registerPreFixScaledAttachment(5001, $scaledRelative, 'unrelated-original.png');

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame('pre_validate.scaled_variant_exists', $result['error_step']);
        self::assertNull($result['media']);
        self::assertCount(1, $GLOBALS['wp_test_posts'], 'Must not insert a new attachment that would overwrite the unrelated one\'s file.');
    }

    #[Test]
    public function it_refuses_to_adopt_when_a_file_already_sits_at_the_scaled_variant_path(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/01/photo-' . uniqid() . '.png';
        $scaledRelative = '2025/01/' . pathinfo($relative, PATHINFO_FILENAME) . '-scaled.png';

        $this->writeMinimalPng($basedir . '/' . $relative);
        // No attachment claims this — it's an unrelated file (or an orphan
        // scaled leftover) sitting exactly where WordPress's own scaling
        // would write to. Adopting must not risk silently overwriting it.
        $this->writeMinimalPng($basedir . '/' . $scaledRelative);

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame('pre_validate.scaled_variant_exists', $result['error_step']);
        self::assertNull($result['media']);
        self::assertCount(0, $GLOBALS['wp_test_posts']);
    }

    #[Test]
    public function it_does_not_rescale_an_orphan_whose_own_filename_already_ends_in_scaled(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/09/photo-' . uniqid() . '-scaled.webp';
        $full = $basedir . '/' . $relative;
        $this->writeMinimalPng($full);

        $doubleScaledFull = $basedir . '/2025/09/photo-' . uniqid() . '-scaled-scaled.webp';

        // If the big-image threshold filter weren't disabled for this call,
        // WordPress would rescale straight into this double-suffixed path
        // (this happened for real on this site — attachment 9312).
        $GLOBALS['wp_test_scale_rename'][$full] = $doubleScaledFull;

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative]);

        self::assertNull($result['error']);
        self::assertNotNull($result['media']);
        self::assertSame(basename($relative), $result['media']['file_name']);
        self::assertStringNotContainsString('-scaled-scaled', $result['media']['file_name']);
    }

    #[Test]
    public function it_succeeds_when_wordpress_rewrites_the_title_on_save_instead_of_deleting_the_attachment(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2018/07/photo-' . uniqid() . '.jpg';
        $full = $basedir . '/' . $relative;
        $this->writeMinimalPng($full);

        $submittedTitle = 'Aimi Yoshikawa 😀';
        $storedTitle = 'Aimi Yoshikawa &#x1f600;';

        // Simulates wp_encode_emoji() rewriting an emoji to an HTML entity
        // when WordPress persists post_title on a legacy utf8 DB column —
        // this is a real WordPress core behavior, not something the plugin
        // controls.
        $GLOBALS['wp_test_title_save_transform'][$submittedTitle] = $storedTitle;

        $GLOBALS['wp_test_options']['jooservices_mcp_media_orphans'] = [
            'scanned_at' => '2026-01-01T00:00:00+00:00',
            'broken_attachments' => [],
            'orphan_files' => ['items' => [['path' => $relative, 'url' => null]], 'truncated' => false],
        ];

        $service = new MediaService();
        $result = $service->adoptOrphan(['path' => $relative, 'title' => $submittedTitle]);

        self::assertNull($result['error'], 'A cosmetic title mismatch must not fail the adoption.');
        self::assertNotNull($result['media']);
        self::assertCount(1, $GLOBALS['wp_test_posts'], 'The attachment must not be deleted over a title mismatch.');
        self::assertSame(
            ['field' => 'metadata.title', 'expected' => $submittedTitle, 'actual' => $storedTitle],
            $result['verification']['metadata_mismatch'] ?? null,
        );
    }
}
