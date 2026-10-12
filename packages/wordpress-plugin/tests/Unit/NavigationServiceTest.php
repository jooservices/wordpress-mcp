<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use Faker\Factory;
use Faker\Generator;
use JOOservices\WordPressMcp\Services\NavigationService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;

final class NavigationServiceTest extends TestCase
{
    private Generator $faker;

    private int $menuId;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = Factory::create();
        $this->menuId = $this->faker->unique()->numberBetween(1000, 999999);
        $this->itemId = $this->faker->unique()->numberBetween(1000, 999999);

        $GLOBALS['wp_test_nav_menus'] = [$this->menuId => new WP_Term($this->menuId, $this->faker->slug())];
        $GLOBALS['wp_test_nav_menu_terms'] = [];
        $GLOBALS['wp_test_nav_menu_update_calls'] = 0;
        $GLOBALS['wp_test_delete_post_calls'] = [];
        $GLOBALS['wp_test_posts'] = [];
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_post_update_calls'] = 0;
        $GLOBALS['wp_test_post_update_error_on_call'] = null;
        $GLOBALS['wp_test_next_nav_menu_item_id'] = $this->itemId + 2;
        $GLOBALS['wpdb']->queries = [];
    }

    #[Test]
    public function it_rejects_deleting_menu_items_with_unsupported_types(): void
    {
        $GLOBALS['wp_test_posts'][$this->itemId] = new WP_Post($this->itemId, '', 'nav_menu_item');
        $GLOBALS['wp_test_nav_menu_terms'][$this->itemId] = [$this->menuId];
        $GLOBALS['wp_test_postmeta'][$this->itemId]['_menu_item_type'] = 'unsupported';

        $result = (new NavigationService())->deleteItem($this->menuId, $this->itemId);

        self::assertFalse($result['deleted']);
        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame([], $GLOBALS['wp_test_delete_post_calls']);
    }

    #[Test]
    public function it_deletes_post_and_taxonomy_menu_items(): void
    {
        foreach (['post_type', 'taxonomy'] as $type) {
            $GLOBALS['wp_test_posts'][$this->itemId] = new WP_Post($this->itemId, '', 'nav_menu_item');
            $GLOBALS['wp_test_nav_menu_terms'][$this->itemId] = [$this->menuId];
            $GLOBALS['wp_test_postmeta'][$this->itemId]['_menu_item_type'] = $type;

            $result = (new NavigationService())->deleteItem($this->menuId, $this->itemId);

            self::assertTrue($result['deleted'], $type);
            self::assertNull($result['error'], $type);
            self::assertSame(
                ['post_id' => $this->itemId, 'force_delete' => true],
                $GLOBALS['wp_test_delete_post_calls'][array_key_last($GLOBALS['wp_test_delete_post_calls'])],
            );
        }
    }

    #[Test]
    public function it_rejects_updates_to_non_custom_menu_items(): void
    {
        $GLOBALS['wp_test_posts'][$this->itemId] = new WP_Post($this->itemId, '', 'nav_menu_item');
        $GLOBALS['wp_test_nav_menu_terms'][$this->itemId] = [$this->menuId];
        $GLOBALS['wp_test_postmeta'][$this->itemId]['_menu_item_type'] = 'post_type';

        $result = (new NavigationService())->saveItem($this->menuId, $this->itemId, [
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
        ]);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame(0, $GLOBALS['wp_test_nav_menu_update_calls']);
    }

    #[Test]
    public function it_rejects_a_parent_that_would_create_a_cycle(): void
    {
        $parentId = $this->faker->unique()->numberBetween(1000, 999999);
        $GLOBALS['wp_test_posts'][$this->itemId] = new WP_Post($this->itemId, '', 'nav_menu_item');
        $GLOBALS['wp_test_posts'][$parentId] = new WP_Post($parentId, '', 'nav_menu_item');
        $GLOBALS['wp_test_nav_menu_terms'][$this->itemId] = [$this->menuId];
        $GLOBALS['wp_test_nav_menu_terms'][$parentId] = [$this->menuId];
        $GLOBALS['wp_test_postmeta'][$this->itemId]['_menu_item_type'] = 'custom';
        $GLOBALS['wp_test_postmeta'][$parentId]['_menu_item_menu_item_parent'] = (string) $this->itemId;

        $result = (new NavigationService())->saveItem($this->menuId, $this->itemId, [
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
            'parent_id' => $parentId,
        ]);

        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame(0, $GLOBALS['wp_test_nav_menu_update_calls']);
    }

    #[Test]
    public function it_appends_a_menu_item_at_position_zero_inside_a_locked_transaction(): void
    {
        $existingId = $this->itemId;
        $existing = new WP_Post($existingId, '', 'nav_menu_item');
        $existing->post_title = $this->faker->words(2, true);
        $existing->menu_order = 1;
        $GLOBALS['wp_test_posts'][$existingId] = $existing;
        $GLOBALS['wp_test_nav_menu_terms'][$existingId] = [$this->menuId];
        $GLOBALS['wp_test_postmeta'][$existingId]['_menu_item_type'] = 'custom';
        $GLOBALS['wp_test_postmeta'][$existingId]['_menu_item_menu_item_parent'] = '0';

        $result = (new NavigationService())->saveItem($this->menuId, null, [
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
            'position' => 0,
        ]);

        self::assertNull($result['error']);
        self::assertSame(1, $GLOBALS['wp_test_posts'][$existingId]->menu_order);
        self::assertSame(2, $GLOBALS['wp_test_posts'][$this->itemId + 2]->menu_order);
        self::assertSame('START TRANSACTION', $GLOBALS['wpdb']->queries[0]);
        self::assertStringContainsString('FOR UPDATE', $GLOBALS['wpdb']->queries[1]);
        self::assertSame('COMMIT', $GLOBALS['wpdb']->queries[2]);
    }

    #[Test]
    public function it_rolls_back_the_new_item_and_prior_reorders_when_an_order_write_fails(): void
    {
        foreach ([$this->itemId, $this->itemId + 1] as $index => $id) {
            $item = new WP_Post($id, '', 'nav_menu_item');
            $item->menu_order = $index + 1;
            $GLOBALS['wp_test_posts'][$id] = $item;
            $GLOBALS['wp_test_nav_menu_terms'][$id] = [$this->menuId];
            $GLOBALS['wp_test_postmeta'][$id]['_menu_item_type'] = 'custom';
            $GLOBALS['wp_test_postmeta'][$id]['_menu_item_menu_item_parent'] = '0';
        }
        $GLOBALS['wp_test_post_update_error_on_call'] = 2;

        $result = (new NavigationService())->saveItem($this->menuId, null, [
            'title' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
            'position' => 1,
        ]);

        self::assertSame(ErrorCodes::WORDPRESS_ERROR, $result['error']);
        self::assertSame(1, $GLOBALS['wp_test_posts'][$this->itemId]->menu_order);
        self::assertSame(2, $GLOBALS['wp_test_posts'][$this->itemId + 1]->menu_order);
        self::assertArrayNotHasKey($this->itemId + 2, $GLOBALS['wp_test_posts']);
        self::assertContains('ROLLBACK', $GLOBALS['wpdb']->queries);
        self::assertNotContains('COMMIT', $GLOBALS['wpdb']->queries);
    }
}
