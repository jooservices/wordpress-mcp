<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\PostTemplates\PostTemplateTypes;
use JOOservices\WordPressMcp\Support\ContentTypes;
use WP_Post;
use WP_Query;

final class PostTemplateService
{
    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function list(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 20)));
        $forType = sanitize_key((string) ($params['type'] ?? ''));

        $args = [
            'post_type' => PostTemplateTypes::POST_TYPE,
            'post_status' => PostTemplateTypes::ACTIVE_STATUSES,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ($forType !== '' && ContentTypes::isSupported($forType)) {
            $args['meta_query'] = [[
                'key' => PostTemplateTypes::META_FOR_TYPE,
                'value' => $forType,
            ]];
        }

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $this->summary($post);
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
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $post = $this->findById($id);

        if ($post === null) {
            return null;
        }

        return array_merge($this->summary($post), [
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'featured_media' => (int) get_post_thumbnail_id($post),
        ]);
    }

    public function findById(int $id): ?WP_Post
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post || $post->post_type !== PostTemplateTypes::POST_TYPE) {
            return null;
        }

        if (! in_array($post->post_status, PostTemplateTypes::ACTIVE_STATUSES, true)) {
            return null;
        }

        return $post;
    }

    public function findBySlug(string $slug): ?WP_Post
    {
        $slug = sanitize_title($slug);

        if ($slug === '') {
            return null;
        }

        $query = new WP_Query([
            'post_type' => PostTemplateTypes::POST_TYPE,
            'post_status' => PostTemplateTypes::ACTIVE_STATUSES,
            'name' => $slug,
            'posts_per_page' => 1,
        ]);

        $post = $query->posts[0] ?? null;

        return $post instanceof WP_Post ? $post : null;
    }

    public function isValidForType(?WP_Post $template, string $contentType): bool
    {
        if ($template === null) {
            return false;
        }

        return $this->forType($template) === $contentType;
    }

    /**
     * @return list<WP_Post>
     */
    public function candidatesForType(string $contentType): array
    {
        $query = new WP_Query([
            'post_type' => PostTemplateTypes::POST_TYPE,
            'post_status' => PostTemplateTypes::ACTIVE_STATUSES,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => PostTemplateTypes::META_FOR_TYPE,
                'value' => $contentType,
            ]],
        ]);

        $posts = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    public function findDefaultForType(string $contentType): ?WP_Post
    {
        foreach ($this->candidatesForType($contentType) as $template) {
            if ($this->isDefault($template)) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function applyTemplate(array $data, WP_Post $template): array
    {
        $merged = $data;

        if (! array_key_exists('content', $merged) || $merged['content'] === '') {
            $merged['content'] = $this->replacePlaceholders($template->post_content, $merged);
        } elseif ($this->containsPlaceholder($template->post_content, ['content', 'body'])) {
            $merged['content'] = $this->replacePlaceholders($template->post_content, $merged);
        }

        if (! array_key_exists('excerpt', $merged) || $merged['excerpt'] === '') {
            $merged['excerpt'] = $this->replacePlaceholders($template->post_excerpt, $merged);
        } elseif ($this->containsPlaceholder($template->post_excerpt, ['excerpt'])) {
            $merged['excerpt'] = $this->replacePlaceholders($template->post_excerpt, $merged);
        }

        if (
            (! array_key_exists('title', $merged) || $merged['title'] === '')
            && $this->containsPlaceholder($template->post_title, ['title'])
        ) {
            $merged['title'] = $this->replacePlaceholders($template->post_title, $merged);
        }

        if (! array_key_exists('categories', $merged)) {
            $defaults = $this->defaultCategories($template);

            if ($defaults !== []) {
                $merged['categories'] = $defaults;
            }
        }

        if (! array_key_exists('tags', $merged)) {
            $defaults = $this->defaultTags($template);

            if ($defaults !== []) {
                $merged['tags'] = $defaults;
            }
        }

        if (! array_key_exists('featured_media', $merged)) {
            $thumbnailId = (int) get_post_thumbnail_id($template);

            if ($thumbnailId > 0) {
                $merged['featured_media'] = $thumbnailId;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function replacePlaceholders(string $template, array $data): string
    {
        $replacements = [
            '{{title}}' => sanitize_text_field((string) ($data['title'] ?? '')),
            '{{excerpt}}' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
            '{{content}}' => (string) ($data['content'] ?? ''),
            '{{body}}' => (string) ($data['content'] ?? ''),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * @param list<string> $names
     */
    private function containsPlaceholder(string $template, array $names): bool
    {
        foreach ($names as $name) {
            if (str_contains($template, '{{' . $name . '}}')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'for_type' => $this->forType($post),
            'is_default' => $this->isDefault($post),
            'match_category_slugs' => $this->matchCategorySlugs($post),
            'match_title_keywords' => $this->matchTitleKeywords($post),
            'updated_at' => get_post_modified_time('c', true, $post),
        ];
    }

    private function forType(WP_Post $post): string
    {
        $value = sanitize_key((string) get_post_meta($post->ID, PostTemplateTypes::META_FOR_TYPE, true));

        return ContentTypes::isSupported($value) ? $value : ContentTypes::POST;
    }

    private function isDefault(WP_Post $post): bool
    {
        return (string) get_post_meta($post->ID, PostTemplateTypes::META_IS_DEFAULT, true) === '1';
    }

    /**
     * @return list<string>
     */
    private function matchCategorySlugs(WP_Post $post): array
    {
        return $this->decodeStringList((string) get_post_meta($post->ID, PostTemplateTypes::META_MATCH_CATEGORY_SLUGS, true));
    }

    /**
     * @return list<string>
     */
    private function matchTitleKeywords(WP_Post $post): array
    {
        return $this->decodeStringList((string) get_post_meta($post->ID, PostTemplateTypes::META_MATCH_TITLE_KEYWORDS, true));
    }

    /**
     * @return list<int>
     */
    private function defaultCategories(WP_Post $post): array
    {
        $raw = get_post_meta($post->ID, PostTemplateTypes::META_DEFAULT_CATEGORIES, true);

        if (! is_array($raw)) {
            $decoded = json_decode((string) $raw, true);

            if (! is_array($decoded)) {
                return [];
            }

            $raw = $decoded;
        }

        return array_values(array_filter(array_map(intval(...), $raw)));
    }

    /**
     * @return list<string>
     */
    private function defaultTags(WP_Post $post): array
    {
        $raw = get_post_meta($post->ID, PostTemplateTypes::META_DEFAULT_TAGS, true);

        if (! is_array($raw)) {
            $decoded = json_decode((string) $raw, true);

            if (! is_array($decoded)) {
                return $this->decodeStringList((string) $raw);
            }

            $raw = $decoded;
        }

        return array_values(array_filter(array_map(static fn(mixed $tag): string => sanitize_text_field((string) $tag), $raw)));
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map(static fn(mixed $item): string => sanitize_title((string) $item), $decoded)));
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];

        return array_values(array_filter(array_map(static fn(string $item): string => sanitize_title($item), $parts)));
    }
}
