<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory;
use JOOservices\WordPressMcp\Support\ContentMediaIds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentMediaIdsTest extends TestCase
{
    #[Test]
    public function it_ignores_gutenberg_block_ids_that_are_not_media(): void
    {
        $faker = Factory::create();
        $headingId = $faker->numberBetween(2, 99_999);

        $ids = ContentMediaIds::fromContent(
            sprintf('<!-- wp:heading {"id":%d,"level":2} --><h2>Heading</h2><!-- /wp:heading -->', $headingId),
        );

        self::assertSame([], $ids);
    }

    #[Test]
    public function it_extracts_wp_image_class_and_image_block_ids(): void
    {
        $faker = Factory::create();
        $imageId = $faker->numberBetween(2, 99_999);
        $galleryA = $imageId + 1;
        $galleryB = $imageId + 2;

        $content = sprintf(
            '<!-- wp:image {"id":%1$d} --><figure><img class="wp-image-%1$d" src="https://example.test/a.png" /></figure><!-- /wp:image -->'
            . '<!-- wp:gallery {"ids":[%2$d,%3$d]} --><figure></figure><!-- /wp:gallery -->',
            $imageId,
            $galleryA,
            $galleryB,
        );

        $ids = ContentMediaIds::fromContent($content);

        self::assertContains($imageId, $ids);
        self::assertContains($galleryA, $ids);
        self::assertContains($galleryB, $ids);
    }
}
