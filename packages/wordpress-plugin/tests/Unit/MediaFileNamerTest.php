<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Support\MediaFileNamer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaFileNamerTest extends TestCase
{
    #[Test]
    public function it_builds_a_slug_filename_from_title_and_image_type(): void
    {
        $result = MediaFileNamer::resolve([
            'title' => 'Sunset over Hanoi',
            'image_type' => 'gallery',
        ], 'image/png');

        self::assertSame('sunset-over-hanoi-gallery.png', $result['file_name']);
        self::assertSame('sunset-over-hanoi-gallery', $result['slug_base']);
        self::assertSame('gallery', $result['image_type']);
        self::assertSame('Sunset over Hanoi', $result['attachment_title']);
    }

    #[Test]
    public function it_accepts_type_alias_for_image_type(): void
    {
        $result = MediaFileNamer::resolve([
            'title' => 'Hero banner',
            'type' => 'featured',
        ], 'image/webp');

        self::assertSame('hero-banner-featured.webp', $result['file_name']);
        self::assertSame('featured', $result['image_type']);
    }

    #[Test]
    public function it_maps_detected_mime_to_the_correct_extension(): void
    {
        $result = MediaFileNamer::resolve([
            'title' => 'Portrait',
            'image_type' => 'inline',
        ], 'image/jpeg');

        self::assertSame('portrait-inline.jpg', $result['file_name']);
    }

    #[Test]
    public function it_rejects_invalid_image_type_values(): void
    {
        $result = MediaFileNamer::resolve([
            'title' => 'Bad type',
            'image_type' => str_repeat('a', 33),
        ], 'image/png');

        self::assertSame(['error_step' => 'pre_validate.image_type'], $result);
    }

    #[Test]
    public function it_falls_back_to_legacy_file_name_when_title_or_type_is_missing(): void
    {
        $result = MediaFileNamer::resolve([
            'file_name' => 'legacy-upload.png',
        ], 'image/png');

        self::assertSame('legacy-upload.png', $result['file_name']);
        self::assertSame('legacy-upload', $result['slug_base']);
        self::assertNull($result['image_type']);
    }

    #[Test]
    public function it_requires_either_title_and_type_or_legacy_file_name(): void
    {
        $result = MediaFileNamer::resolve([
            'title' => 'Only title',
        ], 'image/png');

        self::assertSame(['error_step' => 'pre_validate.input'], $result);
    }
}
