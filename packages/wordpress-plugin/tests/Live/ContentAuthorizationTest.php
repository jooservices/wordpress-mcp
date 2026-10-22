<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class ContentAuthorizationTest extends LiveTestCase
{
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private PluginRestClient $contributorClient;

    /** @var list<int> */
    private array $postIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->contributorClient = PluginRestClient::fromEnvironment('WORDPRESS_LIMITED_CONNECTION_TOKEN');
    }

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
    public function it_limits_content_reads_and_mutations_to_the_contributors_wordpress_capabilities(): void
    {
        $otherDraftId = $this->createAdminPost('draft');
        $otherPrivateId = $this->createAdminPost('private');
        $otherPublishedId = $this->createAdminPost('publish');

        $updatedOtherDraft = $this->api('PATCH', '/content/' . $otherDraftId, [
            'content' => $this->faker->paragraph(),
        ]);
        self::assertSame(200, $updatedOtherDraft['status']);
        $adminRevisions = $this->api('GET', '/content/' . $otherDraftId . '/revisions');
        self::assertSame(200, $adminRevisions['status']);
        self::assertNotEmpty($adminRevisions['body']['items'] ?? []);
        $revisions = array_values($adminRevisions['body']['items']);
        self::assertIsArray($revisions[0] ?? null);
        $revisionId = (int) $revisions[0]['id'];

        foreach (['private', 'future'] as $status) {
            $deniedCreate = $this->contributorClient->request('POST', '/content', [
                'type' => 'post',
                'title' => $this->faker->unique()->sentence(4),
                'content' => $this->faker->paragraph(),
                'status' => $status,
            ]);
            self::assertSame(403, $deniedCreate['status']);
        }

        $ownDraft = $this->contributorClient->request('POST', '/content', [
            'type' => 'post',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $this->faker->paragraph(),
            'status' => 'draft',
        ]);
        self::assertSame(201, $ownDraft['status'], (string) json_encode($ownDraft['body']));
        self::assertIsArray($ownDraft['body']);
        $ownDraftId = (int) $ownDraft['body']['id'];
        $this->postIds[] = $ownDraftId;

        $search = $this->contributorClient->request('GET', '/content?status=any&per_page=50');
        self::assertSame(200, $search['status']);
        self::assertIsArray($search['body']['items'] ?? null);
        $visibleIds = array_map(static fn(array $item): int => (int) $item['id'], $search['body']['items']);
        self::assertContains($otherPublishedId, $visibleIds);
        self::assertContains($ownDraftId, $visibleIds);
        self::assertNotContains($otherDraftId, $visibleIds);
        self::assertNotContains($otherPrivateId, $visibleIds);

        self::assertSame(403, $this->contributorClient->request('GET', '/content/' . $otherDraftId)['status']);
        self::assertSame(403, $this->contributorClient->request('GET', '/content/' . $otherPrivateId)['status']);
        self::assertSame(200, $this->contributorClient->request('GET', '/content/' . $ownDraftId)['status']);
        self::assertSame(403, $this->contributorClient->request('GET', '/content/' . $otherDraftId . '/revisions')['status']);
        self::assertSame(403, $this->contributorClient->request('GET', '/revisions/' . $revisionId)['status']);
        self::assertSame(403, $this->contributorClient->request('GET', '/seo/audit?id=' . $otherDraftId)['status']);
        self::assertSame(403, $this->contributorClient->request('GET', '/seo/metadata/' . $otherDraftId)['status']);

        $deniedSeoUpdate = $this->contributorClient->request('PATCH', '/seo/metadata/' . $otherDraftId, [
            'title' => $this->faker->unique()->sentence(4),
        ]);
        self::assertSame(403, $deniedSeoUpdate['status']);

        foreach (['private', 'future'] as $status) {
            $deniedStatusChange = $this->contributorClient->request('PATCH', '/content/' . $ownDraftId, [
                'status' => $status,
            ]);
            self::assertSame(403, $deniedStatusChange['status']);
        }

        $deniedUpdate = $this->contributorClient->request('PATCH', '/content/' . $otherPublishedId, [
            'title' => $this->faker->unique()->sentence(4),
        ]);
        self::assertSame(403, $deniedUpdate['status']);

        $deniedDelete = $this->contributorClient->request('DELETE', '/content/' . $otherPublishedId . '?force=1');
        self::assertSame(403, $deniedDelete['status']);

        $allowedUpdate = $this->contributorClient->request('PATCH', '/content/' . $ownDraftId, [
            'title' => $this->faker->unique()->sentence(4),
        ]);
        self::assertSame(200, $allowedUpdate['status']);

        $allowedDelete = $this->contributorClient->request('DELETE', '/content/' . $ownDraftId . '?force=1');
        self::assertSame(200, $allowedDelete['status']);
    }

    #[Test]
    public function it_limits_term_reads_to_supported_taxonomies(): void
    {
        $categories = $this->contributorClient->request('GET', '/terms?taxonomy=category');
        self::assertSame(200, $categories['status'], (string) json_encode($categories['body']));

        $navigationTerms = $this->contributorClient->request('GET', '/terms?taxonomy=nav_menu');
        self::assertSame(400, $navigationTerms['status'], (string) json_encode($navigationTerms['body']));
    }

    #[Test]
    public function it_does_not_allow_a_limited_uploader_to_set_another_users_featured_image(): void
    {
        $otherDraftId = $this->createAdminPost('draft');
        $before = $this->api('GET', '/content/' . $otherDraftId);
        self::assertSame(200, $before['status']);
        self::assertSame(0, (int) ($before['body']['featured_media'] ?? 0));

        $upload = $this->contributorClient->request('POST', '/media', [
            'title' => $this->faker->unique()->sentence(4),
            'image_type' => 'featured',
            'content_base64' => self::TINY_PNG,
            'post_id' => $otherDraftId,
            'set_featured' => true,
        ]);

        if ($upload['status'] === 201 && is_array($upload['body'])) {
            $this->mediaIds[] = (int) $upload['body']['id'];
        }

        self::assertSame(403, $upload['status'], (string) json_encode($upload['body']));

        $adopt = $this->contributorClient->request('POST', '/media/orphans/adopt', [
            'path' => 'e2e/e2e-orphan.png',
            'post_id' => $otherDraftId,
            'set_featured' => true,
        ]);
        self::assertSame(403, $adopt['status'], (string) json_encode($adopt['body']));

        $after = $this->api('GET', '/content/' . $otherDraftId);
        self::assertSame(200, $after['status']);
        self::assertSame(0, (int) ($after['body']['featured_media'] ?? 0));

        $ownDraft = $this->contributorClient->request('POST', '/content', [
            'type' => 'post',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $this->faker->paragraph(),
            'status' => 'draft',
        ]);
        self::assertSame(201, $ownDraft['status'], (string) json_encode($ownDraft['body']));
        self::assertIsArray($ownDraft['body']);
        $ownDraftId = (int) $ownDraft['body']['id'];
        $this->postIds[] = $ownDraftId;

        $ownUpload = $this->contributorClient->request('POST', '/media', [
            'title' => $this->faker->unique()->sentence(4),
            'image_type' => 'featured',
            'content_base64' => self::TINY_PNG,
            'post_id' => $ownDraftId,
            'set_featured' => true,
        ]);
        self::assertSame(201, $ownUpload['status'], (string) json_encode($ownUpload['body']));
        self::assertIsArray($ownUpload['body']);
        $this->mediaIds[] = (int) $ownUpload['body']['id'];
        $ownPost = $this->api('GET', '/content/' . $ownDraftId);
        self::assertSame(200, $ownPost['status']);
        self::assertSame((int) $ownUpload['body']['id'], (int) ($ownPost['body']['featured_media'] ?? 0));
    }

    #[Test]
    public function it_never_reuses_another_users_upload_with_the_same_bytes(): void
    {
        $bytes = file_get_contents(__DIR__ . '/fixtures/large-3000x2000.jpg');
        self::assertIsString($bytes);
        $payload = [
            'title' => $this->faker->unique()->sentence(4),
            'image_type' => 'inline',
            'content_base64' => base64_encode($bytes),
        ];

        $adminUpload = $this->api('POST', '/media', $payload);
        self::assertSame(201, $adminUpload['status'], (string) json_encode($adminUpload['body']));
        $this->mediaIds[] = (int) $adminUpload['body']['id'];

        $limitedUpload = $this->contributorClient->request('POST', '/media', $payload);
        self::assertSame(201, $limitedUpload['status'], (string) json_encode($limitedUpload['body']));
        $this->mediaIds[] = (int) $limitedUpload['body']['id'];

        self::assertNotSame((int) $adminUpload['body']['id'], (int) $limitedUpload['body']['id']);
        self::assertFalse((bool) ($limitedUpload['body']['verification']['reused'] ?? true));
    }

    #[Test]
    public function it_does_not_list_another_users_unpublished_templates(): void
    {
        $unpublishedTemplateIds = [
            $this->templateId('mcp-e2e-private-template'),
            $this->templateId('mcp-e2e-draft-template'),
        ];
        $listed = $this->contributorClient->request('GET', '/post-templates?type=post&per_page=50');

        self::assertSame(200, $listed['status'], (string) json_encode($listed['body']));
        self::assertIsArray($listed['body']['items'] ?? null);
        $visibleIds = array_map(static fn(array $template): int => (int) $template['id'], $listed['body']['items']);

        self::assertSame([], array_values(array_intersect($unpublishedTemplateIds, $visibleIds)));
    }

    #[Test]
    public function it_lists_a_draft_template_the_current_user_can_read(): void
    {
        $templateId = $this->templateId('mcp-e2e-contributor-draft-template');
        $listed = $this->contributorClient->request('GET', '/post-templates?type=post&per_page=50');

        self::assertSame(200, $listed['status'], (string) json_encode($listed['body']));
        self::assertIsArray($listed['body']['items'] ?? null);
        $visibleIds = array_map(static fn(array $template): int => (int) $template['id'], $listed['body']['items']);

        self::assertContains($templateId, $visibleIds);
    }

    #[Test]
    public function it_denies_direct_reads_of_another_users_unpublished_templates(): void
    {
        foreach (['mcp-e2e-private-template', 'mcp-e2e-draft-template'] as $slug) {
            $templateId = $this->templateId($slug);
            $result = $this->contributorClient->request('GET', '/post-templates/' . $templateId);

            self::assertSame(404, $result['status'], (string) json_encode($result['body']));
        }
    }

    #[Test]
    public function it_only_applies_templates_the_user_can_read_when_creating_content(): void
    {
        foreach (['mcp-e2e-private-template', 'mcp-e2e-draft-template'] as $slug) {
            $result = $this->contributorClient->request('POST', '/content', [
                'type' => 'post',
                'title' => $this->faker->unique()->sentence(4),
                'content' => $this->faker->paragraph(),
                'status' => 'draft',
                'template_id' => $this->templateId($slug),
            ]);

            self::assertSame(403, $result['status'], (string) json_encode($result['body']));
        }

        $content = $this->faker->paragraph();
        $publishedTemplateId = $this->templateId('mcp-e2e-published-template');
        $created = $this->contributorClient->request('POST', '/content', [
            'type' => 'post',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $content,
            'status' => 'draft',
            'template_id' => $publishedTemplateId,
        ]);

        self::assertSame(201, $created['status'], (string) json_encode($created['body']));
        self::assertIsArray($created['body']);
        $this->postIds[] = (int) $created['body']['id'];
        self::assertStringContainsString('MCP E2E published template content: ' . $content, (string) $created['body']['content']);
    }

    #[Test]
    public function it_denies_broken_media_reference_reads_for_private_posts_the_user_cannot_read(): void
    {
        $privatePosts = $this->api(
            'GET',
            '/content?type=post&status=any&per_page=50&q=' . rawurlencode('E2E private broken media reference'),
        );
        self::assertSame(200, $privatePosts['status'], (string) json_encode($privatePosts['body']));
        self::assertIsArray($privatePosts['body']['items'] ?? null);
        $privatePost = null;
        foreach ($privatePosts['body']['items'] as $item) {
            if (($item['slug'] ?? '') === 'e2e-private-broken-media-reference') {
                $privatePost = $item;
                break;
            }
        }
        self::assertIsArray($privatePost, 'The private broken-media fixture must be present.');
        $privatePostId = (int) $privatePost['id'];

        $scan = $this->contributorClient->request('GET', '/media/broken-references?post_id=' . $privatePostId);

        self::assertSame(403, $scan['status'], (string) json_encode($scan['body']));
    }

    private function createAdminPost(string $status): int
    {
        $created = $this->api('POST', '/content', [
            'type' => 'post',
            'title' => $this->faker->unique()->sentence(4),
            'content' => $this->faker->paragraph(),
            'status' => $status,
        ]);

        self::assertSame(201, $created['status'], (string) json_encode($created['body']));
        self::assertIsArray($created['body']);
        $id = (int) $created['body']['id'];
        $this->postIds[] = $id;

        return $id;
    }

    private function templateId(string $slug): int
    {
        $templates = $this->api('GET', '/post-templates?type=post&per_page=50');
        self::assertSame(200, $templates['status'], (string) json_encode($templates['body']));
        self::assertIsArray($templates['body']['items'] ?? null);

        foreach ($templates['body']['items'] as $template) {
            if (($template['slug'] ?? '') === $slug) {
                return (int) $template['id'];
            }
        }

        self::fail(sprintf('The seeded E2E template "%s" must be present for the administrator.', $slug));
    }
}
