<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\ContentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    private ContentService $service;

    protected function setUp(): void
    {
        $this->service = new ContentService();
    }

    #[Test]
    public function it_maps_text_author_and_taxonomy_filters_to_query_args(): void
    {
        $args = $this->service->buildQueryArgs([
            'type' => 'post',
            'q' => 'hello',
            'category_name' => 'news',
            'tag_name' => 'featured',
            'author_name' => 'admin',
        ]);

        self::assertSame('post', $args['post_type']);
        self::assertSame('hello', $args['s']);
        self::assertSame('news', $args['category_name']);
        self::assertSame('featured', $args['tag']);
        self::assertSame('admin', $args['author_name']);
    }

    #[Test]
    public function it_maps_numeric_author_and_taxonomy_ids_to_query_args(): void
    {
        $args = $this->service->buildQueryArgs([
            'author' => '7',
            'category' => '3',
            'tag' => '9',
        ]);

        self::assertSame(7, $args['author']);
        self::assertSame(3, $args['cat']);
        self::assertSame(9, $args['tag_id']);
    }

    #[Test]
    public function it_builds_a_meta_query_only_when_key_and_value_are_both_present(): void
    {
        $args = $this->service->buildQueryArgs([
            'meta_key' => 'seo_priority',
            'meta_value' => 'high',
        ]);

        self::assertSame([[
            'key' => 'seo_priority',
            'value' => 'high',
            'compare' => 'LIKE',
        ]], $args['meta_query']);

        $incomplete = $this->service->buildQueryArgs(['meta_key' => 'seo_priority']);

        self::assertArrayNotHasKey('meta_query', $incomplete);
    }

    #[Test]
    public function it_drops_the_meta_filter_when_the_key_targets_protected_postmeta(): void
    {
        $args = $this->service->buildQueryArgs([
            'meta_key' => '_some_protected_key',
            'meta_value' => 'guess',
        ]);

        self::assertArrayNotHasKey('meta_query', $args);
    }

    #[Test]
    public function it_defaults_page_and_per_page_to_safe_values(): void
    {
        $args = $this->service->buildQueryArgs([]);

        self::assertSame(1, $args['paged']);
        self::assertSame(10, $args['posts_per_page']);

        $capped = $this->service->buildQueryArgs(['page' => '0', 'per_page' => '999']);

        self::assertSame(1, $capped['paged']);
        self::assertSame(50, $capped['posts_per_page']);
    }

    #[Test]
    public function it_falls_back_to_post_for_unsupported_types(): void
    {
        $args = $this->service->buildQueryArgs(['type' => 'product']);

        self::assertSame('post', $args['post_type']);
    }
}
