<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

final class TaxonomyService
{
    /**
     * @param array<string, mixed> $params
     * @return list<array{id: int, name: string, slug: string, taxonomy: string}>
     */
    public function list(array $params): array
    {
        $taxonomy = sanitize_key((string) ($params['taxonomy'] ?? 'category'));
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
}
