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
}
