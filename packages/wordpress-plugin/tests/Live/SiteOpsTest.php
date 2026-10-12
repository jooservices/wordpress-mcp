<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class SiteOpsTest extends LiveTestCase
{
    #[Test]
    public function it_reads_and_restores_site_settings(): void
    {
        $current = $this->api('GET', '/settings');
        self::assertSame(200, $current['status']);
        $original = (string) ($current['body']['blogdescription'] ?? '');
        $next = 'E2E ' . $this->faker->unique()->numerify('tag####');

        try {
            $updated = $this->api('PATCH', '/settings', ['blogdescription' => $next]);
            self::assertSame(200, $updated['status'], (string) json_encode($updated['body']));
        } finally {
            $restored = $this->api('PATCH', '/settings', ['blogdescription' => $original]);
            self::assertSame(200, $restored['status']);
        }
    }

    #[Test]
    public function it_reads_robots_and_updates_seo_on_a_draft(): void
    {
        $robots = $this->api('GET', '/seo/robots');
        self::assertSame(200, $robots['status']);
        $content = (string) ($robots['body']['content'] ?? "User-agent: *\nDisallow:\n");
        $saved = $this->api('POST', '/seo/robots', ['content' => $content]);
        self::assertContains($saved['status'], [200, 201]);

        $post = $this->api('POST', '/content', [
            'type' => 'post',
            'title' => 'SEO ' . $this->faker->unique()->numerify('####'),
            'status' => 'draft',
        ]);
        self::assertSame(201, $post['status']);
        $id = (int) $post['body']['id'];

        try {
            $meta = $this->api('GET', '/seo/metadata/' . $id);
            self::assertSame(200, $meta['status']);
            $patched = $this->api('PATCH', '/seo/metadata/' . $id, [
                'title' => 'SEO title',
                'description' => 'SEO description',
            ]);
            self::assertContains($patched['status'], [200, 201]);
        } finally {
            $deleted = $this->api('DELETE', '/content/' . $id . '?force=1');
            self::assertSame(200, $deleted['status']);
        }
    }

    #[Test]
    public function it_manages_a_navigation_menu_and_a_redirect(): void
    {
        $name = 'E2E Menu ' . $this->faker->unique()->numerify('####');
        $created = $this->api('POST', '/navigation/menus', ['name' => $name]);
        self::assertContains($created['status'], [200, 201], (string) json_encode($created['body']));
        $menuId = (int) ($created['body']['id'] ?? 0);
        self::assertGreaterThan(0, $menuId);
        $source = '/e2e-' . $this->faker->unique()->numerify('redir-####');

        try {
            $listed = $this->api('GET', '/navigation/menus');
            self::assertSame(200, $listed['status']);

            $upserted = $this->api('POST', '/redirects', [
                'source' => $source,
                'destination' => 'https://example.com/e2e',
                'status' => 301,
            ]);
            self::assertContains($upserted['status'], [200, 201], (string) json_encode($upserted['body']));

            $redirects = $this->api('GET', '/redirects');
            self::assertSame(200, $redirects['status']);
            $this->api('GET', '/redirects/not-found');
        } finally {
            $deletedMenu = $this->api('DELETE', '/navigation/menus/' . $menuId);
            self::assertSame(200, $deletedMenu['status']);
            $deletedRedirect = $this->api('DELETE', '/redirects/' . rawurlencode(ltrim($source, '/')));
            self::assertContains($deletedRedirect['status'], [200, 204]);
        }
    }

    #[Test]
    public function it_manages_terms_comments_and_existing_objects_in_a_navigation_menu(): void
    {
        $post = $this->api('POST', '/content', [
            'type' => 'post',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $this->faker->paragraph(),
            'status' => 'publish',
        ]);
        self::assertSame(201, $post['status'], (string) json_encode($post['body']));
        $postId = (int) $post['body']['id'];

        $page = $this->api('POST', '/content', [
            'type' => 'page',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $this->faker->paragraph(),
            'status' => 'publish',
        ]);
        self::assertSame(201, $page['status'], (string) json_encode($page['body']));
        $pageId = (int) $page['body']['id'];

        $createdCategory = $this->api('POST', '/terms', [
            'taxonomy' => 'category',
            'name' => $this->faker->unique()->words(2, true),
        ]);
        self::assertSame(201, $createdCategory['status'], (string) json_encode($createdCategory['body']));
        $categoryId = (int) $createdCategory['body']['id'];

        $createdTag = $this->api('POST', '/terms', [
            'taxonomy' => 'post_tag',
            'name' => $this->faker->unique()->words(2, true),
        ]);
        self::assertSame(201, $createdTag['status'], (string) json_encode($createdTag['body']));
        $tagId = (int) $createdTag['body']['id'];

        $createdMenu = $this->api('POST', '/navigation/menus', [
            'name' => 'E2E ' . $this->faker->unique()->numerify('native-menu-####'),
        ]);
        self::assertContains($createdMenu['status'], [200, 201], (string) json_encode($createdMenu['body']));
        $menuId = (int) $createdMenu['body']['id'];
        $childCategoryId = 0;

        try {
            $updatedCategory = $this->api('PATCH', '/terms/' . $categoryId, [
                'taxonomy' => 'category',
                'name' => $this->faker->unique()->words(2, true),
                'slug' => $this->faker->unique()->slug(2),
            ]);
            self::assertSame(200, $updatedCategory['status'], (string) json_encode($updatedCategory['body']));
            self::assertSame($categoryId, (int) $updatedCategory['body']['id']);

            $childCategory = $this->api('POST', '/terms', [
                'taxonomy' => 'category',
                'name' => $this->faker->unique()->words(2, true),
                'parent_id' => $categoryId,
            ]);
            self::assertSame(201, $childCategory['status'], (string) json_encode($childCategory['body']));
            $childCategoryId = (int) $childCategory['body']['id'];

            $cycle = $this->api('PATCH', '/terms/' . $categoryId, [
                'taxonomy' => 'category',
                'parent_id' => $childCategoryId,
            ]);
            self::assertSame(400, $cycle['status'], (string) json_encode($cycle['body']));

            $comment = $this->api('POST', '/comments', [
                'post_id' => $postId,
                'content' => $this->faker->sentence(),
            ]);
            self::assertSame(201, $comment['status'], (string) json_encode($comment['body']));
            $commentId = (int) $comment['body']['id'];
            self::assertSame($postId, (int) $comment['body']['post_id']);
            self::assertSame(0, (int) $comment['body']['parent_id']);

            $reply = $this->api('POST', '/comments', [
                'post_id' => $postId,
                'content' => $this->faker->sentence(),
                'parent_id' => $commentId,
            ]);
            self::assertSame(201, $reply['status'], (string) json_encode($reply['body']));
            self::assertSame($commentId, (int) $reply['body']['parent_id']);

            $pageItem = $this->api('POST', '/navigation/menus/' . $menuId . '/items/object', [
                'object_type' => 'page',
                'object_id' => $pageId,
            ]);
            self::assertSame(201, $pageItem['status'], (string) json_encode($pageItem['body']));
            self::assertSame($pageId, (int) $pageItem['body']['object_id']);
            self::assertNotEmpty($pageItem['body']['url']);

            $categoryItem = $this->api('POST', '/navigation/menus/' . $menuId . '/items/object', [
                'object_type' => 'category',
                'object_id' => $categoryId,
                'position' => 0,
            ]);
            self::assertSame(201, $categoryItem['status'], (string) json_encode($categoryItem['body']));
            self::assertSame($categoryId, (int) $categoryItem['body']['object_id']);
            self::assertSame('taxonomy', $categoryItem['body']['type']);

            $menu = $this->api('GET', '/navigation/menus/' . $menuId);
            self::assertSame(200, $menu['status']);
            self::assertCount(2, $menu['body']['items']);
        } finally {
            $deletedMenu = $this->api('DELETE', '/navigation/menus/' . $menuId);
            self::assertSame(200, $deletedMenu['status']);
            if ($childCategoryId > 0) {
                $deletedChild = $this->api('DELETE', '/terms/' . $childCategoryId . '?taxonomy=category');
                self::assertSame(200, $deletedChild['status']);
            }
            $deletedCategory = $this->api('DELETE', '/terms/' . $categoryId . '?taxonomy=category');
            self::assertSame(200, $deletedCategory['status']);
            $deletedTag = $this->api('DELETE', '/terms/' . $tagId . '?taxonomy=post_tag');
            self::assertSame(200, $deletedTag['status']);
            $this->api('DELETE', '/content/' . $pageId . '?force=1');
            $this->api('DELETE', '/content/' . $postId . '?force=1');
        }
    }

    #[Test]
    public function it_rejects_boolean_menu_location_ids_without_changing_assignments(): void
    {
        $before = $this->api('GET', '/navigation/menus');
        self::assertSame(200, $before['status']);
        self::assertIsArray($before['body']['locations'] ?? null);
        $originalLocations = $before['body']['locations'];

        $created = $this->api('POST', '/navigation/menus', [
            'name' => 'E2E ' . $this->faker->unique()->numerify('menu-####'),
        ]);
        self::assertContains($created['status'], [200, 201]);
        $menuId = (int) ($created['body']['id'] ?? 0);
        self::assertGreaterThan(0, $menuId);
        $location = 'mcp_e2e_' . $this->faker->unique()->numerify('location-####');

        try {
            $assigned = $this->api('PATCH', '/navigation/locations', [
                'locations' => [$location => $menuId],
            ]);
            self::assertSame(200, $assigned['status'], (string) json_encode($assigned['body']));

            $invalid = $this->api('PATCH', '/navigation/locations', [
                'locations' => [$location => false],
            ]);
            self::assertSame(400, $invalid['status'], (string) json_encode($invalid['body']));

            $invalid = $this->api('PATCH', '/navigation/locations', [
                'locations' => [$location => true],
            ]);
            self::assertSame(400, $invalid['status'], (string) json_encode($invalid['body']));
            self::assertSame(
                'Each location must have a non-empty name and a non-negative menu ID.',
                $invalid['body']['message'] ?? null,
            );

            $after = $this->api('GET', '/navigation/menus');
            self::assertSame(200, $after['status']);
            self::assertSame($menuId, (int) ($after['body']['locations'][$location] ?? 0));
        } finally {
            $restored = $this->api('PATCH', '/navigation/locations', ['locations' => $originalLocations]);
            self::assertSame(200, $restored['status']);
            $deleted = $this->api('DELETE', '/navigation/menus/' . $menuId);
            self::assertSame(200, $deleted['status']);
        }
    }

    #[Test]
    public function it_leaves_maintenance_mode_off(): void
    {
        $response = $this->api('PATCH', '/maintenance', ['enabled' => false]);
        self::assertContains($response['status'], [200, 201]);
    }
}
