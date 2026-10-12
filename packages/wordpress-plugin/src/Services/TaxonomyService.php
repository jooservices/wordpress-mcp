<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;

final class TaxonomyService
{
    /** @var list<string> */
    public const SUPPORTED_TAXONOMIES = ['category', 'post_tag'];

    /**
     * @param array<string, mixed> $params
     * @return list<array{id: int, name: string, slug: string, taxonomy: string}>
     */
    public function list(array $params): array
    {
        $rawTaxonomy = $params['taxonomy'] ?? 'category';
        $taxonomy = is_string($rawTaxonomy) ? sanitize_key($rawTaxonomy) : '';
        if (! $this->isSupportedTaxonomy($taxonomy)) {
            return [];
        }

        $search = isset($params['q']) ? sanitize_text_field((string) $params['q']) : '';

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'search' => $search,
            'number' => min(50, max(1, (int) ($params['per_page'] ?? 20))),
        ]);

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        $result = [];

        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $result[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{term: array{id: int, name: string, slug: string, taxonomy: string}|null, error: string|null}
     */
    public function create(string $taxonomy, string $name, ?string $slug = null, int $parentId = 0): array
    {
        $name = \sanitize_text_field($name);
        if (! $this->isSupportedTaxonomy($taxonomy) || $name === '' || $parentId < 0) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        if ($taxonomy !== 'category' && $parentId > 0) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        if ($parentId > 0 && ! $this->isValidParent(0, $parentId)) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $args = [];
        if ($slug !== null && \trim($slug) !== '') {
            $sanitizedSlug = \sanitize_title($slug);
            if ($sanitizedSlug === '') {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $args['slug'] = $sanitizedSlug;
        }
        if ($parentId > 0) {
            $args['parent'] = $parentId;
        }

        $result = \wp_insert_term($name, $taxonomy, $args);
        if (\is_wp_error($result)) {
            return ['term' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get((int) $result['term_id'], $taxonomy);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{term: array{id: int, name: string, slug: string, taxonomy: string}|null, error: string|null}
     */
    public function update(int $id, string $taxonomy, array $data): array
    {
        if ($id < 1 || ! $this->isSupportedTaxonomy($taxonomy)) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $term = \get_term($id, $taxonomy);
        if (! $term instanceof \WP_Term) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $args = [];
        if (\array_key_exists('name', $data)) {
            if (! \is_string($data['name'])) {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $name = \sanitize_text_field($data['name']);
            if ($name === '') {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $args['name'] = $name;
        }
        if (\array_key_exists('slug', $data)) {
            if (! \is_string($data['slug']) || \trim($data['slug']) === '') {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $slug = \sanitize_title($data['slug']);
            if ($slug === '') {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $args['slug'] = $slug;
        }
        if (isset($data['parent'])) {
            if (
                $taxonomy !== 'category'
                || ! \is_int($data['parent'])
                || $data['parent'] < 0
                || ! $this->isValidParent($id, $data['parent'])
            ) {
                return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
            $args['parent'] = $data['parent'];
        }
        if ($args === []) {
            return ['term' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $result = \wp_update_term($id, $taxonomy, $args);
        if (\is_wp_error($result)) {
            return ['term' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get($id, $taxonomy);
    }

    /** @return array{deleted: bool, error: string|null} */
    public function delete(int $id, string $taxonomy): array
    {
        if ($id < 1 || ! $this->isSupportedTaxonomy($taxonomy) || ! \get_term($id, $taxonomy) instanceof \WP_Term) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $result = \wp_delete_term($id, $taxonomy);
        return $result !== true
            ? ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR]
            : ['deleted' => true, 'error' => null];
    }

    /**
     * @return array{term: array{id: int, name: string, slug: string, taxonomy: string}|null, error: string|null}
     */
    private function get(int $id, string $taxonomy): array
    {
        $term = \get_term($id, $taxonomy);
        if (! $term instanceof \WP_Term) {
            return ['term' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return [
            'term' => [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'taxonomy' => $term->taxonomy,
            ],
            'error' => null,
        ];
    }

    private function isSupportedTaxonomy(string $taxonomy): bool
    {
        return \in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true);
    }

    private function isValidParent(int $termId, int $parentId): bool
    {
        $ancestors = [];
        while ($parentId > 0) {
            if ($parentId === $termId || isset($ancestors[$parentId])) {
                return false;
            }
            $ancestors[$parentId] = true;

            $parent = \get_term($parentId, 'category');
            if (! $parent instanceof \WP_Term) {
                return false;
            }

            $parentId = (int) $parent->parent;
        }

        return true;
    }
}
