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
        mkdir(dirname($full), 0777, true);
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
}
