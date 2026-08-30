<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ContentNormalizer;
use JOOservices\WordPressMcp\Support\ContentTypes;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use JOOservices\WordPressMcp\Support\MediaStoredVerifier;
use JOOservices\WordPressMcp\Support\MediaVerificationResult;
use WP_Post;
use WP_Query;

final class ContentService
{
    public function __construct(
        private readonly ContentNormalizer $normalizer = new ContentNormalizer(),
        private readonly PostTemplateResolver $templateResolver = new PostTemplateResolver(),
        private readonly PostTemplateService $templateService = new PostTemplateService(),
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function search(array $params): array
    {
        $args = $this->buildQueryArgs($params);
        $page = (int) ($args['paged'] ?? 1);
        $perPage = (int) ($args['posts_per_page'] ?? 10);

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $this->normalizer->summary($post);
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ];
    }

    /**
     * Maps search parameters to WP_Query arguments. Semantic filters:
     * - category_name / tag_name match by slug (instead of numeric ids)
     * - author_name matches by user display/login name
     * - meta_key + meta_value filter on a custom field (both required)
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function buildQueryArgs(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 10)));
        $type = sanitize_key((string) ($params['type'] ?? 'post'));

        if (! in_array($type, ['post', 'page'], true)) {
            $type = 'post';
        }

        $args = [
            'post_type' => $type,
            'post_status' => $this->parseStatuses($params['status'] ?? 'any'),
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => sanitize_key((string) ($params['orderby'] ?? 'date')),
            'order' => strtoupper((string) ($params['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            's' => isset($params['q']) ? sanitize_text_field((string) $params['q']) : '',
        ];

        if (! empty($params['author'])) {
            $args['author'] = (int) $params['author'];
        }

        if (! empty($params['author_name'])) {
            $args['author_name'] = sanitize_text_field((string) $params['author_name']);
        }

        if (! empty($params['category'])) {
            $args['cat'] = (int) $params['category'];
        }

        if (! empty($params['category_name'])) {
            $args['category_name'] = sanitize_text_field((string) $params['category_name']);
        }

        if (! empty($params['tag'])) {
            $args['tag_id'] = (int) $params['tag'];
        }

        if (! empty($params['tag_name'])) {
            $args['tag'] = sanitize_text_field((string) $params['tag_name']);
        }

        $metaKey = sanitize_key((string) ($params['meta_key'] ?? ''));

        // WordPress REST convention treats underscore-prefixed meta as
        // protected (hidden from external queries); an authenticated MCP
        // connection should not be able to use this filter as an oracle to
        // probe another plugin's protected fields.
        if ($metaKey !== '' && ! str_starts_with($metaKey, '_') && ! empty($params['meta_value'])) {
            $args['meta_query'] = [[
                'key' => $metaKey,
                'value' => sanitize_text_field((string) $params['meta_value']),
                'compare' => 'LIKE',
            ]];
        }

        if (! empty($params['date_from'])) {
            $args['date_query'][] = ['after' => sanitize_text_field((string) $params['date_from']), 'inclusive' => true];
        }

        if (! empty($params['date_to'])) {
            $args['date_query'][] = ['before' => sanitize_text_field((string) $params['date_to']), 'inclusive' => true];
        }

        return $args;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return null;
        }

        return $this->normalizer->full($post);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{post: array<string, mixed>|null, error: string|null}
     */
    public function create(array $data, bool $canPublish): array
    {
        $type = ContentTypes::normalize(sanitize_key((string) ($data['type'] ?? ContentTypes::POST)), ContentTypes::POST);

        if ($type === null) {
            return ['post' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $status = sanitize_key((string) ($data['status'] ?? 'draft'));

        if ($status === 'publish' && ! $canPublish) {
            return ['post' => null, 'error' => ErrorCodes::PERMISSION_DENIED];
        }

        if (! in_array($status, ['draft', 'pending', 'publish', 'private'], true)) {
            $status = 'draft';
        }

        $templateResolution = $this->templateResolver->resolve($data, $type);

        if ($templateResolution['error'] !== null) {
            return ['post' => null, 'error' => $templateResolution['error']];
        }

        if ($templateResolution['template'] instanceof WP_Post) {
            $data = $this->templateService->applyTemplate($data, $templateResolution['template']);
        }

        $data = $this->stripTemplateParams($data);

        $featuredMediaError = $this->validateFeaturedMedia($data);

        if ($featuredMediaError !== null) {
            return ['post' => null, 'error' => $featuredMediaError];
        }

        $postId = wp_insert_post([
            'post_type' => $type,
            'post_title' => sanitize_text_field((string) ($data['title'] ?? '')),
            'post_content' => wp_kses_post((string) ($data['content'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
            'post_status' => $status,
            'post_name' => isset($data['slug']) ? sanitize_title((string) $data['slug']) : '',
        ], true);

        if (is_wp_error($postId)) {
            return ['post' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $this->assignTaxonomies((int) $postId, $data);

        if (! $this->applyFeaturedMedia((int) $postId, $data)) {
            wp_delete_post((int) $postId, true);

            return ['post' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $post = get_post((int) $postId);

        return ['post' => $post instanceof WP_Post ? $this->normalizer->full($post) : null, 'error' => null];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{post: array<string, mixed>|null, error: string|null}
     */
    public function update(int $id, array $data, bool $canPublish): array
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return ['post' => null, 'error' => ErrorCodes::POST_NOT_FOUND];
        }

        $featuredMediaError = $this->validateFeaturedMedia($data);

        if ($featuredMediaError !== null) {
            return ['post' => null, 'error' => $featuredMediaError];
        }

        $update = ['ID' => $id];

        if (array_key_exists('title', $data)) {
            $update['post_title'] = sanitize_text_field((string) $data['title']);
        }

        if (array_key_exists('content', $data)) {
            $update['post_content'] = wp_kses_post((string) $data['content']);
        }

        if (array_key_exists('excerpt', $data)) {
            $update['post_excerpt'] = sanitize_textarea_field((string) $data['excerpt']);
        }

        if (array_key_exists('slug', $data)) {
            $update['post_name'] = sanitize_title((string) $data['slug']);
        }

        if (array_key_exists('status', $data)) {
            $status = sanitize_key((string) $data['status']);

            if ($status === 'publish' && ! $canPublish) {
                return ['post' => null, 'error' => ErrorCodes::PERMISSION_DENIED];
            }

            if (in_array($status, ['draft', 'pending', 'publish', 'private'], true)) {
                $update['post_status'] = $status;
            }
        }

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return ['post' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $this->assignTaxonomies($id, $data);

        if (! $this->applyFeaturedMedia($id, $data)) {
            return ['post' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $updated = get_post($id);

        return ['post' => $updated instanceof WP_Post ? $this->normalizer->full($updated) : null, 'error' => null];
    }

    /**
     * @return array{deleted: bool, error: string|null}
     */
    public function delete(int $id, bool $force): array
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return ['deleted' => false, 'error' => ErrorCodes::POST_NOT_FOUND];
        }

        if (! in_array($post->post_type, ['post', 'page'], true)) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $result = wp_delete_post($id, $force);

        if ($result === false || $result === null) {
            return ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return ['deleted' => true, 'error' => null];
    }

    /**
     * @return list<string>
     */
    private function parseStatuses(mixed $status): array
    {
        if ($status === 'any' || $status === null || $status === '') {
            return ['publish', 'draft', 'pending', 'private', 'future'];
        }

        if (is_string($status)) {
            return [sanitize_key($status)];
        }

        return ['publish'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assignTaxonomies(int $postId, array $data): void
    {
        if (isset($data['categories']) && is_array($data['categories'])) {
            wp_set_post_categories($postId, array_map(intval(...), $data['categories']));
        }

        if (isset($data['tags']) && is_array($data['tags'])) {
            wp_set_post_tags($postId, array_map(strval(...), $data['tags']));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateFeaturedMedia(array $data): ?string
    {
        if (! array_key_exists('featured_media', $data)) {
            return null;
        }

        $mediaId = $data['featured_media'];

        if (! is_int($mediaId) || $mediaId < 0) {
            return ErrorCodes::INVALID_ARGUMENT;
        }

        if ($mediaId === 0) {
            return null;
        }

        $attachment = get_post($mediaId);

        if (! $attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
            return ErrorCodes::MEDIA_NOT_FOUND;
        }

        if (! wp_attachment_is_image($mediaId)) {
            return ErrorCodes::INVALID_ARGUMENT;
        }

        if (MediaStoredVerifier::isVerified($mediaId)) {
            return null;
        }

        $stored = MediaStoredVerifier::verifyAttachment($mediaId);

        if (! MediaVerificationResult::passed($stored['verification'])) {
            return ErrorCodes::MEDIA_VERIFY_FAILED;
        }

        MediaStoredVerifier::markVerified($mediaId);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyFeaturedMedia(int $postId, array $data): bool
    {
        if (! array_key_exists('featured_media', $data)) {
            return true;
        }

        $mediaId = $data['featured_media'];

        if (! is_int($mediaId)) {
            return false;
        }

        $currentMediaId = (int) get_post_thumbnail_id($postId);

        if ($currentMediaId === $mediaId) {
            return true;
        }

        return (bool) ($mediaId === 0
            ? delete_post_thumbnail($postId)
            : set_post_thumbnail($postId, $mediaId));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function stripTemplateParams(array $data): array
    {
        unset($data['template_id'], $data['template_slug'], $data['use_template']);

        return $data;
    }
}
