<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory;
use JOOservices\WordPressMcp\Services\TaxonomyService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class TaxonomyServiceTest extends TestCase
{
    #[Test]
    public function it_reports_default_category_deletion_as_failed(): void
    {
        $faker = Factory::create();
        $termId = $faker->unique()->numberBetween(1000, 999999);
        $GLOBALS['wp_test_terms'] = [$termId => new WP_Term($termId, $faker->unique()->slug())];
        $GLOBALS['wp_test_delete_term_result'] = 0;
        $GLOBALS['wp_test_delete_term_calls'] = [];

        $result = (new TaxonomyService())->delete($termId, 'category');

        self::assertFalse($result['deleted']);
        self::assertSame(ErrorCodes::WORDPRESS_ERROR, $result['error']);
        self::assertSame([['term_id' => $termId, 'taxonomy' => 'category']], $GLOBALS['wp_test_delete_term_calls']);
    }
}
