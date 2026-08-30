<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Post;

/**
 * On-site SEO audit and fix — robots.txt, per-post metadata (title, meta
 * description, canonical, Open Graph, noindex), heading structure, missing
 * alt text, and broken internal links. Nothing here talks to Google or any
 * external API: everything is derived from this WordPress install alone.
 */
final class SeoService
{
    private const ROBOTS_OPTION = 'jooservices_mcp_robots_override';

    private const CORE_META_PREFIX = '_jooservices_seo_';

    /** @var list<string> */
    private const FIELDS = ['title', 'description', 'canonical', 'og_title', 'og_description', 'noindex'];

    public function detectProvider(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }

        if (class_exists('RankMath')) {
            return 'rank_math';
        }

        return 'core';
    }

    /**
     * @return array{content: string, source: string}
     */
    public function getRobots(): array
    {
        $path = $this->robotsFilePath();

        if ($path !== null) {
            return ['content' => (string) file_get_contents($path), 'source' => 'file'];
        }

        $public = (bool) get_option('blog_public', 1);
        $default = $public ? "User-agent: *\nDisallow:\n" : "User-agent: *\nDisallow: /\n";

        // Reads the override directly rather than relying on the `robots_txt`
        // filter having been registered — this must reflect `updateRobots()`
        // on its own, independent of WordPress's filter wiring.
        return ['content' => self::filterRobotsTxt((string) apply_filters('robots_txt', $default, $public)), 'source' => 'virtual'];
    }

    /**
     * @return array{content?: string, source?: string, error?: string}
     */
    public function updateRobots(string $content): array
    {
        $path = $this->robotsFilePath();

        if ($path !== null) {
            if (! is_writable($path)) {
                return ['error' => ErrorCodes::WORDPRESS_ERROR];
            }

            file_put_contents($path, $content);

            return ['content' => $content, 'source' => 'file'];
        }

        update_option(self::ROBOTS_OPTION, $content);

        return ['content' => $content, 'source' => 'virtual'];
    }

    /**
     * Registered on the `robots_txt` filter (see `Plugin::registerHooks()`)
     * so a virtual-site override actually takes effect when WordPress
     * serves /robots.txt, not just when read back through this service.
     */
    public static function filterRobotsTxt(string $output): string
    {
        $override = (string) get_option(self::ROBOTS_OPTION, '');

        return $override !== '' ? $override : $output;
    }

    /**
     * @return array{findings: list<array<string, mixed>>}|array{error: string}
     */
    public function audit(int $postId): array
    {
        $post = get_post($postId);

        if (! $post instanceof WP_Post) {
            return ['error' => ErrorCodes::POST_NOT_FOUND];
        }

        $findings = [];
        $metadata = $this->readMetadata($postId);

        if (trim((string) $metadata['title']) === '') {
            $findings[] = $this->finding('missing_title', 'medium', 'No SEO title set; falling back to the post title.');
        }

        if (trim((string) $metadata['description']) === '') {
            $findings[] = $this->finding('missing_description', 'medium', 'No meta description set.');
        }

        if ($metadata['noindex']) {
            $findings[] = $this->finding('noindex', 'high', 'This content is marked noindex and will not appear in search results.');
        }

        $content = (string) $post->post_content;
        $findings = array_merge($findings, $this->auditHeadings($content));
        $findings = array_merge($findings, $this->auditImages($content));
        $findings = array_merge($findings, $this->auditInternalLinks($content));

        return ['findings' => $findings];
    }

    /**
     * @return array<string, mixed>|array{error: string}
     */
    public function getSeoMetadata(int $postId): array
    {
        if (! get_post($postId) instanceof WP_Post) {
            return ['error' => ErrorCodes::POST_NOT_FOUND];
        }

        return array_merge(['id' => $postId, 'provider' => $this->detectProvider()], $this->readMetadata($postId));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|array{error: string}
     */
    public function updateSeoMetadata(int $postId, array $fields): array
    {
        if (! get_post($postId) instanceof WP_Post) {
            return ['error' => ErrorCodes::POST_NOT_FOUND];
        }

        $provider = $this->detectProvider();

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $this->writeField($postId, $provider, $field, $fields[$field]);
        }

        return $this->getSeoMetadata($postId);
    }

    /**
     * @return string|null Path to a writable/readable physical robots.txt, or null when WordPress serves it virtually.
     */
    private function robotsFilePath(): ?string
    {
        $path = ABSPATH . 'robots.txt';

        return file_exists($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(int $postId): array
    {
        $provider = $this->detectProvider();

        return [
            'title' => (string) $this->readField($postId, $provider, 'title'),
            'description' => (string) $this->readField($postId, $provider, 'description'),
            'canonical' => (string) $this->readField($postId, $provider, 'canonical'),
            'og_title' => (string) $this->readField($postId, $provider, 'og_title'),
            'og_description' => (string) $this->readField($postId, $provider, 'og_description'),
            'noindex' => (bool) $this->readField($postId, $provider, 'noindex'),
        ];
    }

    private function readField(int $postId, string $provider, string $field): mixed
    {
        $key = $this->metaKey($provider, $field);

        if ($field === 'noindex' && $provider === 'rank_math') {
            $robots = get_post_meta($postId, 'rank_math_robots', true);

            return is_array($robots) && in_array('noindex', $robots, true);
        }

        if ($field === 'noindex' && $provider === 'yoast') {
            return (string) get_post_meta($postId, $key, true) === '1';
        }

        return get_post_meta($postId, $key, true);
    }

    private function writeField(int $postId, string $provider, string $field, mixed $value): void
    {
        if ($field === 'noindex' && $provider === 'rank_math') {
            update_post_meta($postId, 'rank_math_robots', $value ? ['noindex'] : ['index']);

            return;
        }

        if ($field === 'noindex' && $provider === 'yoast') {
            update_post_meta($postId, $this->metaKey($provider, $field), $value ? '1' : '2');

            return;
        }

        $key = $this->metaKey($provider, $field);

        if ($field === 'noindex') {
            update_post_meta($postId, $key, $value ? '1' : '');

            return;
        }

        update_post_meta($postId, $key, sanitize_text_field((string) $value));
    }

    private function metaKey(string $provider, string $field): string
    {
        return match ($provider) {
            'yoast' => match ($field) {
                'title' => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
                'canonical' => '_yoast_wpseo_canonical',
                'og_title' => '_yoast_wpseo_opengraph-title',
                'og_description' => '_yoast_wpseo_opengraph-description',
                'noindex' => '_yoast_wpseo_meta-robots-noindex',
                default => self::CORE_META_PREFIX . $field,
            },
            'rank_math' => match ($field) {
                'title' => 'rank_math_title',
                'description' => 'rank_math_description',
                'canonical' => 'rank_math_canonical_url',
                'og_title' => 'rank_math_facebook_title',
                'og_description' => 'rank_math_facebook_description',
                default => self::CORE_META_PREFIX . $field,
            },
            default => self::CORE_META_PREFIX . $field,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditHeadings(string $content): array
    {
        $findings = [];

        preg_match_all('/<h([1-6])[^>]*>/i', $content, $matches);
        $levels = array_map('intval', $matches[1]);
        $h1Count = count(array_filter($levels, static fn(int $level): bool => $level === 1));

        if ($h1Count > 1) {
            $findings[] = $this->finding('multiple_h1', 'low', "Found {$h1Count} H1 headings; a page should have one.");
        }

        for ($i = 1, $count = count($levels); $i < $count; $i++) {
            if ($levels[$i] - $levels[$i - 1] > 1) {
                $findings[] = $this->finding(
                    'skipped_heading_level',
                    'low',
                    "Heading level jumps from H{$levels[$i - 1]} to H{$levels[$i]}.",
                );
                break;
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditImages(string $content): array
    {
        preg_match_all('/<img[^>]*>/i', $content, $matches);
        $missingAlt = 0;

        foreach ($matches[0] as $tag) {
            if (! preg_match('/\balt\s*=\s*(["\']).*?\1/i', $tag)) {
                $missingAlt++;
            }
        }

        if ($missingAlt === 0) {
            return [];
        }

        return [$this->finding(
            'missing_alt_text',
            'medium',
            "{$missingAlt} image(s) are missing alt text.",
        )];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditInternalLinks(string $content): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $matches);
        $home = home_url();
        $broken = 0;

        foreach ($matches[1] as $href) {
            if (! str_starts_with($href, $home) && ! str_starts_with($href, '/')) {
                continue;
            }

            if (url_to_postid($href) === 0 && ! $this->resolvesToKnownPath($href)) {
                $broken++;
            }
        }

        if ($broken === 0) {
            return [];
        }

        return [$this->finding(
            'possible_broken_internal_link',
            'medium',
            "{$broken} internal link(s) did not resolve to a known post, page, or path.",
        )];
    }

    private function resolvesToKnownPath(string $href): bool
    {
        $path = (string) wp_parse_url($href, PHP_URL_PATH);

        return $path === '' || $path === '/' || get_page_by_path(trim($path, '/')) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }
}
