<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\PostTemplates\PostTemplateTypes;
use JOOservices\WordPressMcp\Services\PostTemplateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class PostTemplateServiceTest extends TestCase
{
    private PostTemplateService $service;

    protected function setUp(): void
    {
        $this->service = new PostTemplateService();
    }

    #[Test]
    public function it_replaces_placeholders_in_template_content(): void
    {
        $result = $this->service->replacePlaceholders(
            '<h1>{{title}}</h1><p>{{content}}</p>',
            ['title' => 'Hello', 'content' => 'Body text'],
        );

        self::assertSame('<h1>Hello</h1><p>Body text</p>', $result);
    }

    #[Test]
    public function it_applies_template_defaults_when_request_fields_are_missing(): void
    {
        $template = new WP_Post(10, '<p>{{content}}</p>', PostTemplateTypes::POST_TYPE);
        $template->post_excerpt = '{{excerpt}}';
        $template->post_status = 'publish';

        $GLOBALS['wp_test_postmeta'][10] = [
            PostTemplateTypes::META_FOR_TYPE => 'post',
            PostTemplateTypes::META_DEFAULT_CATEGORIES => '[3,7]',
            PostTemplateTypes::META_DEFAULT_TAGS => '["featured","mcp"]',
        ];
        $GLOBALS['wp_test_thumbnails'][10] = 55;

        $merged = $this->service->applyTemplate(
            ['title' => 'Launch', 'content' => 'Main body'],
            $template,
        );

        self::assertSame('<p>Main body</p>', $merged['content']);
        self::assertSame([3, 7], $merged['categories']);
        self::assertSame(['featured', 'mcp'], $merged['tags']);
        self::assertSame(55, $merged['featured_media']);
    }
}
