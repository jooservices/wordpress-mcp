<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim($text);
    }
}

if (! defined('ABSPATH')) {
    $absPath = sys_get_temp_dir() . '/jooservices-mcp-test-abspath/';

    if (! is_dir($absPath)) {
        mkdir($absPath, 0777, true);
    }

    define('ABSPATH', $absPath);
}

if (! function_exists('get_option')) {
    /** @var array<string, mixed> $GLOBALS['wp_test_options'] */
    $GLOBALS['wp_test_options'] = [];

    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['wp_test_options'][$name] ?? $default;
    }

    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['wp_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$_args): mixed
    {
        return $value;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, ?int $component = -1): mixed
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (! class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post
    {
        public int $ID;

        public string $post_content = '';

        public string $post_type = 'post';

        public function __construct(int $id, string $content = '', string $type = 'post')
        {
            $this->ID = $id;
            $this->post_content = $content;
            $this->post_type = $type;
        }
    }
}

if (! function_exists('get_post')) {
    /** @var array<int, WP_Post> $GLOBALS['wp_test_posts'] */
    $GLOBALS['wp_test_posts'] = [];

    function get_post(int $id): ?WP_Post
    {
        return $GLOBALS['wp_test_posts'][$id] ?? null;
    }

    function url_to_postid(string $url): int
    {
        return 0;
    }

    function get_page_by_path(string $path): ?WP_Post
    {
        return null;
    }
}

if (! function_exists('get_post_meta')) {
    /** @var array<int, array<string, mixed>> $GLOBALS['wp_test_postmeta'] */
    $GLOBALS['wp_test_postmeta'] = [];

    function get_post_meta(int $postId, string $key, bool $single = false): mixed
    {
        return $GLOBALS['wp_test_postmeta'][$postId][$key] ?? '';
    }

    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        $GLOBALS['wp_test_postmeta'][$postId][$key] = $value;

        return true;
    }
}

if (! function_exists('get_transient')) {
    /** @var array<string, mixed> $GLOBALS['wp_test_transients'] */
    $GLOBALS['wp_test_transients'] = [];

    function get_transient(string $transient): mixed
    {
        return $GLOBALS['wp_test_transients'][$transient] ?? false;
    }

    function set_transient(string $transient, mixed $value, int $expiration): bool
    {
        $GLOBALS['wp_test_transients'][$transient] = $value;

        return true;
    }

    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['wp_test_transients'][$transient]);

        return true;
    }
}
