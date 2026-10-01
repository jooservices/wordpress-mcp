<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory;
use JOOservices\WordPressMcp\Support\ContentNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentNormalizerTest extends TestCase
{
    #[Test]
    public function it_builds_a_summary_with_an_unknown_author(): void
    {
        $faker = Factory::create();
        $post = new \WP_Post(11, '<p>' . $faker->sentence(8) . '</p>');
        $post->post_name = $faker->slug();
        $post->post_status = 'publish';
        $post->post_author = $faker->numberBetween(1, 100);
        $GLOBALS['wp_test_post_titles'][$post->ID] = $faker->sentence(3);

        $summary = (new ContentNormalizer())->summary($post);

        self::assertSame($post->ID, $summary['id']);
        self::assertSame($post->post_type, $summary['type']);
        self::assertSame($post->post_name, $summary['slug']);
        self::assertSame('Unknown', $summary['author']['name']);
    }

    #[Test]
    public function it_builds_full_content_with_terms_and_thumbnail(): void
    {
        $faker = Factory::create();
        $post = new \WP_Post(12, $faker->paragraphs(2, true));
        $post->post_excerpt = '';
        $post->post_name = $faker->slug();
        $post->post_author = $faker->numberBetween(1, 100);
        $GLOBALS['wp_test_post_titles'][$post->ID] = $faker->sentence(3);
        $category = new \WP_Term($faker->numberBetween(1, 100), $faker->slug());
        $category->name = $faker->word();
        $GLOBALS['wp_test_post_terms'][$post->ID]['category'] = [$category];
        $GLOBALS['wp_test_post_terms'][$post->ID]['post_tag'] = false;
        $GLOBALS['wp_test_thumbnails'][$post->ID] = $faker->numberBetween(1, 100);

        $full = (new ContentNormalizer())->full($post);

        self::assertSame($post->post_content, $full['content']);
        self::assertSame($category->name, $full['categories'][0]['name']);
        self::assertSame([], $full['tags']);
        self::assertSame($GLOBALS['wp_test_thumbnails'][$post->ID], $full['featured_media']);
    }
}
