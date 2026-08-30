<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory as FakerFactory;
use JOOservices\WordPressMcp\Support\MediaImageInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaImageInspectorTest extends TestCase
{
    private const MINIMAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $validPng;

    protected function setUp(): void
    {
        $decoded = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($decoded);
        $this->validPng = $decoded;
    }

    #[Test]
    public function it_accepts_a_valid_png_and_returns_checksum_and_dimensions(): void
    {
        $result = MediaImageInspector::inspectBytes($this->validPng);

        self::assertTrue($result['ok']);
        self::assertSame('image/png', $result['mime']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame(strlen($this->validPng), $result['bytes']);
        self::assertSame(64, strlen($result['sha256']));
    }

    #[Test]
    public function it_rejects_corrupted_or_truncated_image_bytes(): void
    {
        $truncated = substr($this->validPng, 0, 16);
        $result = MediaImageInspector::inspectBytes($truncated);

        self::assertFalse($result['ok']);
        self::assertSame('pre_validate.decode', $result['step']);
    }

    #[Test]
    public function it_rejects_random_non_image_bytes(): void
    {
        $faker = FakerFactory::create();
        $result = MediaImageInspector::inspectBytes($faker->text(120));

        self::assertFalse($result['ok']);
        self::assertContains($result['step'], ['pre_validate.mime', 'pre_validate.mime_not_allowed', 'pre_validate.decode']);
    }

    #[Test]
    public function it_detects_mime_from_bytes_not_from_declared_extension(): void
    {
        $detected = MediaImageInspector::detectMimeFromBytes($this->validPng);

        self::assertSame('image/png', $detected);
    }

    #[Test]
    public function it_rejects_png_bytes_when_mime_family_does_not_match_allowed_types(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        self::assertNotFalse($gif);

        $result = MediaImageInspector::inspectBytes($gif);

        self::assertFalse($result['ok']);
        self::assertSame('pre_validate.mime_not_allowed', $result['step']);
    }

    #[Test]
    public function it_detects_checksum_changes_after_truncation(): void
    {
        $original = MediaImageInspector::inspectBytes($this->validPng);
        $truncated = MediaImageInspector::inspectBytes(substr($this->validPng, 0, 16));

        self::assertTrue($original['ok']);
        self::assertFalse($truncated['ok']);
        self::assertNotSame($original['sha256'], $truncated['sha256']);
    }

    #[Test]
    public function it_inspects_a_file_on_disk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-media-');
        self::assertNotFalse($path);
        file_put_contents($path, $this->validPng);

        $result = MediaImageInspector::inspectFile($path);
        unlink($path);

        self::assertTrue($result['ok']);
        self::assertSame('image/png', $result['mime']);
    }
}
