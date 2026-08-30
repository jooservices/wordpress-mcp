<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\PostTemplates\PostTemplateTypes;
use JOOservices\WordPressMcp\Services\PostTemplateResolver;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class PostTemplateResolverTest extends TestCase
{
    private PostTemplateResolver $resolver;

    protected function setUp(): void
    {
        $GLOBALS['wp_test_posts'] = [];
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_terms'] = [];

        $this->resolver = new PostTemplateResolver();
    }

    #[Test]
    public function it_returns_no_template_when_template_params_are_omitted(): void
    {
        $result = $this->resolver->resolve(['title' => 'Hello'], 'post');

        self::assertNull($result['error']);
        self::assertNull($result['template']);
    }

    #[Test]
    public function it_returns_an_error_for_unknown_template_id(): void
    {
        $result = $this->resolver->resolve(['template_id' => 999], 'post');

        self::assertSame(ErrorCodes::TEMPLATE_NOT_FOUND, $result['error']);
        self::assertNull($result['template']);
    }

    #[Test]
    public function it_auto_matches_templates_by_category_and_title_keywords(): void
    {
        $newsTemplate = new WP_Post(1, '<p>{{content}}</p>', PostTemplateTypes::POST_TYPE);
        $newsTemplate->post_status = 'publish';
        $newsTemplate->post_name = 'news-template';

        $reviewTemplate = new WP_Post(2, '<p>{{content}}</p>', PostTemplateTypes::POST_TYPE);
        $reviewTemplate->post_status = 'publish';
        $reviewTemplate->post_name = 'review-template';

        $GLOBALS['wp_test_posts'] = [1 => $newsTemplate, 2 => $reviewTemplate];
        $GLOBALS['wp_test_postmeta'] = [
            1 => [
                PostTemplateTypes::META_FOR_TYPE => 'post',
                PostTemplateTypes::META_MATCH_CATEGORY_SLUGS => '["news"]',
                PostTemplateTypes::META_MATCH_TITLE_KEYWORDS => '[]',
            ],
            2 => [
                PostTemplateTypes::META_FOR_TYPE => 'post',
                PostTemplateTypes::META_MATCH_CATEGORY_SLUGS => '[]',
                PostTemplateTypes::META_MATCH_TITLE_KEYWORDS => '["review"]',
            ],
        ];
        $GLOBALS['wp_test_terms'][5] = new \WP_Term(5, 'news');

        $result = $this->resolver->resolve([
            'use_template' => 'auto',
            'title' => 'Product review',
        ], 'post');

        self::assertNull($result['error']);
        self::assertNotNull($result['template']);
        self::assertSame(2, $result['template']->ID);
    }

    #[Test]
    public function it_falls_back_to_default_template_in_auto_mode(): void
    {
        $defaultTemplate = new WP_Post(3, '<p>{{content}}</p>', PostTemplateTypes::POST_TYPE);
        $defaultTemplate->post_status = 'publish';

        $GLOBALS['wp_test_posts'] = [3 => $defaultTemplate];
        $GLOBALS['wp_test_postmeta'] = [
            3 => [
                PostTemplateTypes::META_FOR_TYPE => 'post',
                PostTemplateTypes::META_IS_DEFAULT => '1',
            ],
        ];

        $result = $this->resolver->resolve([
            'use_template' => 'auto',
            'title' => 'Generic post',
        ], 'post');

        self::assertNull($result['error']);
        self::assertSame(3, $result['template']?->ID);
    }
}
