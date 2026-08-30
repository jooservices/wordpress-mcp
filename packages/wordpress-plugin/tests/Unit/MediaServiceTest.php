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
}
