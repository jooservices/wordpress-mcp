<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\PostTemplates\PostTemplateTypes;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Post;

final class PostTemplateResolver
{
    public function __construct(
        private readonly PostTemplateService $templates = new PostTemplateService(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{template: WP_Post|null, error: string|null}
     */
    public function resolve(array $data, string $contentType): array
    {
        if (array_key_exists('template_id', $data)) {
            $template = $this->templates->findById((int) $data['template_id']);

            if ($template === null || ! $this->templates->isValidForType($template, $contentType)) {
                return ['template' => null, 'error' => ErrorCodes::TEMPLATE_NOT_FOUND];
            }

            return ['template' => $template, 'error' => null];
        }

        if (array_key_exists('template_slug', $data)) {
            $template = $this->templates->findBySlug((string) $data['template_slug']);

            if ($template === null || ! $this->templates->isValidForType($template, $contentType)) {
                return ['template' => null, 'error' => ErrorCodes::TEMPLATE_NOT_FOUND];
            }

            return ['template' => $template, 'error' => null];
        }

        $mode = sanitize_key((string) ($data['use_template'] ?? ''));

        if ($mode === '') {
            return ['template' => null, 'error' => null];
        }

        if ($mode === 'default') {
            $template = $this->templates->findDefaultForType($contentType);

            return ['template' => $template, 'error' => null];
        }

        if ($mode !== 'auto') {
            return ['template' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $matched = $this->matchAuto($data, $contentType);

        if ($matched !== null) {
            return ['template' => $matched, 'error' => null];
        }

        return ['template' => $this->templates->findDefaultForType($contentType), 'error' => null];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function matchAuto(array $data, string $contentType): ?WP_Post
    {
        $bestScore = 0;
        $bestTemplate = null;

        foreach ($this->templates->candidatesForType($contentType) as $template) {
            $score = $this->scoreTemplate($template, $data);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTemplate = $template;
            }
        }

        return $bestScore > 0 ? $bestTemplate : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function scoreTemplate(WP_Post $template, array $data): int
    {
        $score = 0;
        $categorySlugs = $this->categorySlugsFromRequest($data);
        $matchSlugs = $this->readMatchCategorySlugs($template);

        foreach ($matchSlugs as $slug) {
            if (in_array($slug, $categorySlugs, true)) {
                ++$score;
            }
        }

        $title = strtolower(sanitize_text_field((string) ($data['title'] ?? '')));

        foreach ($this->readMatchTitleKeywords($template) as $keyword) {
            if ($keyword !== '' && str_contains($title, strtolower($keyword))) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function categorySlugsFromRequest(array $data): array
    {
        $slugs = [];

        if (isset($data['category_name'])) {
            $slugs[] = sanitize_title((string) $data['category_name']);
        }

        if (isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $categoryId) {
                $term = get_term((int) $categoryId, 'category');

                if ($term instanceof \WP_Term) {
                    $slugs[] = $term->slug;
                }
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * @return list<string>
     */
    private function readMatchCategorySlugs(WP_Post $template): array
    {
        $raw = (string) get_post_meta($template->ID, PostTemplateTypes::META_MATCH_CATEGORY_SLUGS, true);

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

    /**
     * @return list<string>
     */
    private function readMatchTitleKeywords(WP_Post $template): array
    {
        $raw = (string) get_post_meta($template->ID, PostTemplateTypes::META_MATCH_TITLE_KEYWORDS, true);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map(static fn(mixed $item): string => sanitize_text_field((string) $item), $decoded)));
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];

        return array_values(array_filter(array_map(static fn(string $item): string => sanitize_text_field($item), $parts)));
    }
}
