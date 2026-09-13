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
            $this->api('DELETE', '/content/' . $id . '?force=1');
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
            $this->api('DELETE', '/navigation/menus/' . $menuId);
            $this->api('DELETE', '/redirects/' . rawurlencode(ltrim($source, '/')));
        }
    }

    #[Test]
    public function it_leaves_maintenance_mode_off(): void
    {
        $response = $this->api('PATCH', '/maintenance', ['enabled' => false]);
        self::assertContains($response['status'], [200, 201]);
    }
}
