<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

use WP_Post;

final class ContentNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function summary(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'url' => get_permalink($post),
            'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 40),
            'author' => $this->author($post),
            'updated_at' => get_post_modified_time('c', true, $post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function full(WP_Post $post): array
    {
        return array_merge($this->summary($post), [
            'content' => $post->post_content,
            'categories' => $this->terms($post, 'category'),
            'tags' => $this->terms($post, 'post_tag'),
            'created_at' => get_post_time('c', true, $post),
            'featured_media' => (int) get_post_thumbnail_id($post),
        ]);
    }

    /**
     * @return array{id: int, name: string}
     */
    private function author(WP_Post $post): array
    {
        $user = get_userdata((int) $post->post_author);

        return [
            'id' => (int) $post->post_author,
            'name' => $user instanceof \WP_User ? $user->display_name : 'Unknown',
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function terms(WP_Post $post, string $taxonomy): array
    {
        $terms = get_the_terms($post, $taxonomy);

        if (! is_array($terms)) {
            return [];
        }

        $result = [];

        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $result[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        return $result;
    }
}
