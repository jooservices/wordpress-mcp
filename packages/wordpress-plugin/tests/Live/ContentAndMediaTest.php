<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class ContentAndMediaTest extends LiveTestCase
{
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<int> */
    private array $postIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    protected function tearDown(): void
    {
        foreach ($this->postIds as $id) {
            $this->api('DELETE', '/content/' . $id . '?force=1');
        }

        foreach ($this->mediaIds as $id) {
            $this->api('DELETE', '/media/' . $id);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_creates_updates_and_deletes_a_draft_including_gutenberg_non_media_ids(): void
    {
        $title = 'Plugin E2E ' . $this->faker->unique()->numerify('post-####');
        $created = $this->api('POST', '/content', [
            'type' => 'post',
            'title' => $title,
            'content' => '<p>' . $this->faker->sentence() . '</p>',
            'status' => 'draft',
        ]);

        self::assertSame(201, $created['status'], (string) json_encode($created['body']));
        self::assertIsArray($created['body']);
        $id = (int) $created['body']['id'];
        $this->postIds[] = $id;
        self::assertSame('draft', $created['body']['status']);

        $list = $this->api('GET', '/content?per_page=5');
        self::assertSame(200, $list['status']);
        self::assertIsArray($list['body']['items'] ?? null);

        $fetched = $this->api('GET', '/content/' . $id);
        self::assertSame(200, $fetched['status']);

        $headingId = $this->faker->numberBetween(2, 99_999);
        $updated = $this->api('PATCH', '/content/' . $id, [
            'excerpt' => $this->faker->sentence(),
            'content' => sprintf(
                '<!-- wp:heading {"id":%d,"level":2} --><h2>Heading</h2><!-- /wp:heading -->',
                $headingId,
            ),
        ]);
        self::assertSame(200, $updated['status'], (string) json_encode($updated['body']));

        $templates = $this->api('GET', '/post-templates');
        self::assertSame(200, $templates['status']);

        $deleted = $this->api('DELETE', '/content/' . $id . '?force=1');
        self::assertSame(200, $deleted['status']);
        $this->postIds = array_values(array_filter($this->postIds, static fn(int $kept): bool => $kept !== $id));
    }

    #[Test]
    public function it_uploads_media_sets_featured_image_and_rejects_corrupt_bytes(): void
    {
        $stamp = $this->faker->unique()->numerify('####');
        $upload = $this->api('POST', '/media', [
            'title' => 'Featured image ' . $stamp,
            'image_type' => 'featured',
            'content_base64' => self::TINY_PNG,
            'alt_text' => 'Alt ' . $stamp,
        ]);

        self::assertSame(201, $upload['status'], (string) json_encode($upload['body']));
        self::assertIsArray($upload['body']);
        $mediaId = (int) $upload['body']['id'];
        $this->mediaIds[] = $mediaId;
        self::assertTrue((bool) ($upload['body']['verification']['passed'] ?? false));
        self::assertTrue((bool) ($upload['body']['verified'] ?? false));

        $listed = $this->api('GET', '/media?per_page=5');
        self::assertSame(200, $listed['status']);

        $got = $this->api('GET', '/media/' . $mediaId . '?verify=1');
        self::assertSame(200, $got['status']);

        $patched = $this->api('PATCH', '/media/' . $mediaId, [
            'alt_text' => 'Alt updated ' . $stamp,
        ]);
        self::assertSame(200, $patched['status']);

        $post = $this->api('POST', '/content', [
            'type' => 'post',
            'title' => 'Featured ' . $stamp,
            'status' => 'draft',
            'featured_media' => $mediaId,
        ]);
        self::assertSame(201, $post['status'], (string) json_encode($post['body']));
        $postId = (int) $post['body']['id'];
        $this->postIds[] = $postId;
        self::assertSame($mediaId, (int) $post['body']['featured_media']);

        $cleared = $this->api('PATCH', '/content/' . $postId, ['featured_media' => 0]);
        self::assertSame(200, $cleared['status']);
        self::assertSame(0, (int) $cleared['body']['featured_media']);

        $corrupt = $this->api('POST', '/media', [
            'title' => 'Broken ' . $stamp,
            'image_type' => 'inline',
            'content_base64' => base64_encode('not-an-image'),
        ]);
        self::assertSame(400, $corrupt['status']);
        self::assertSame('MEDIA_VERIFY_FAILED', $corrupt['body']['code'] ?? null);
    }

    #[Test]
    public function it_lists_orphans_broken_refs_and_adopts_the_seeded_file(): void
    {
        $orphans = $this->api('GET', '/media/orphans');
        self::assertSame(200, $orphans['status']);
        $items = $orphans['body']['orphan_files']['items'] ?? [];
        self::assertIsArray($items);
        $match = null;

        foreach ($items as $item) {
            if (is_array($item) && ($item['path'] ?? '') === 'e2e/e2e-orphan.png') {
                $match = $item;
                break;
            }
        }

        self::assertNotNull($match, 'Seeded e2e/e2e-orphan.png must appear in the orphan scan cache.');

        $broken = $this->api('GET', '/media/broken-references');
        self::assertSame(200, $broken['status']);

        $missing = $this->api('POST', '/media/orphans/adopt', ['path' => 'e2e/does-not-exist.png']);
        self::assertSame(400, $missing['status']);

        $adopted = $this->api('POST', '/media/orphans/adopt', ['path' => 'e2e/e2e-orphan.png']);
        self::assertSame(201, $adopted['status'], (string) json_encode($adopted['body']));
        $id = (int) $adopted['body']['id'];
        $this->mediaIds[] = $id;

        $again = $this->api('POST', '/media/orphans/adopt', ['path' => 'e2e/e2e-orphan.png']);
        self::assertContains($again['status'], [200, 201]);
        self::assertSame($id, (int) $again['body']['id']);
    }
}
