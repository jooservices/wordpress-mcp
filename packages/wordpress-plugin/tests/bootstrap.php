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

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        if ($show === 'name') {
            return (string) ($GLOBALS['wp_test_bloginfo']['name'] ?? 'Test Site');
        }

        if ($show === 'version') {
            return (string) ($GLOBALS['wp_test_bloginfo']['version'] ?? '6.8');
        }

        return '';
    }
}

if (! function_exists('wp_timezone_string')) {
    function wp_timezone_string(): string
    {
        return (string) ($GLOBALS['wp_test_timezone'] ?? 'UTC');
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['wp_test_is_multisite'] ?? false);
    }
}

if (! function_exists('ini_get')) {
    function ini_get(string $option): string|false
    {
        return $GLOBALS['wp_test_ini'][$option] ?? '';
    }
}

if (! function_exists('wp_version_check')) {
    function wp_version_check(): void
    {
    }
}

if (! function_exists('get_core_updates')) {
    function get_core_updates(): array
    {
        return $GLOBALS['wp_test_core_updates'] ?? [];
    }
}

if (! class_exists('WP_Theme')) {
    class WP_Theme
    {
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function exists(): bool
        {
            return true;
        }

        public function get_stylesheet(): string
        {
            return (string) ($this->data['stylesheet'] ?? '');
        }

        public function get(string $key): string
        {
            return (string) ($this->data[$key] ?? '');
        }
    }
}

if (! function_exists('wp_get_theme')) {
    function wp_get_theme(): WP_Theme
    {
        return new WP_Theme($GLOBALS['wp_test_theme'] ?? [
            'stylesheet' => 'twentytwentyfive',
            'Name' => 'Twenty Twenty-Five',
            'Version' => '1.0',
        ]);
    }
}

if (! function_exists('get_plugins')) {
    function get_plugins(): array
    {
        return $GLOBALS['wp_test_plugins'] ?? [];
    }
}

if (! function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool
    {
        return in_array($plugin, $GLOBALS['wp_test_active_plugins'] ?? [], true);
    }
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
    /** @var array<string, callable> $GLOBALS['wp_test_filters'] */
    $GLOBALS['wp_test_filters'] = [];

    function add_filter(string $tag, callable $callback): void
    {
        $GLOBALS['wp_test_filters'][$tag] = $callback;
    }

    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        $callback = $GLOBALS['wp_test_filters'][$tag] ?? null;

        return is_callable($callback) ? $callback($value, ...$args) : $value;
    }
}

if (! function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        return (bool) ($GLOBALS['wp_test_user_caps'][$userId][$capability] ?? true);
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

        public string $post_excerpt = '';

        public string $post_title = '';

        public string $post_name = '';

        public string $post_status = 'publish';

        public string $post_type = 'post';

        public function __construct(int $id, string $content = '', string $type = 'post')
        {
            $this->ID = $id;
            $this->post_content = $content;
            $this->post_type = $type;
        }
    }
}

if (! function_exists('get_post_type')) {
    function get_post_type(int $postId): string|false
    {
        $post = get_post($postId);

        return $post instanceof WP_Post ? $post->post_type : false;
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

        if (
            ($GLOBALS['wp_test_yoast_clears_metadesc_on_title'] ?? false)
            && $key === '_yoast_wpseo_title'
        ) {
            unset($GLOBALS['wp_test_postmeta'][$postId]['_yoast_wpseo_metadesc']);
        }

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

if (! function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string
    {
        return $filename;
    }
}

if (! function_exists('sanitize_mime_type')) {
    function sanitize_mime_type(string $mime): string
    {
        return $mime;
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $text): string
    {
        return $text;
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9\-]+/', '-', $title) ?? '';
        $title = trim($title, '-');

        return $title;
    }
}

if (! function_exists('wp_max_upload_size')) {
    function wp_max_upload_size(): int
    {
        return (int) ($GLOBALS['wp_test_max_upload_size'] ?? 10 * 1024 * 1024);
    }
}

if (! function_exists('wp_upload_dir')) {
    $wpTestUploadBasedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';

    if (! is_dir($wpTestUploadBasedir)) {
        mkdir($wpTestUploadBasedir, 0777, true);
    }

    function wp_upload_dir(): array
    {
        return $GLOBALS['wp_test_upload_dir'] ?? [
            'basedir' => sys_get_temp_dir() . '/jooservices-mcp-test-uploads',
            'baseurl' => 'https://example.test/wp-content/uploads',
            'error' => false,
        ];
    }
}

if (! function_exists('get_attached_file')) {
    function get_attached_file(int $attachmentId): string
    {
        return (string) ($GLOBALS['wp_test_attachment_files'][$attachmentId] ?? '');
    }
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachmentId): string|false
    {
        return $GLOBALS['wp_test_attachment_urls'][$attachmentId] ?? false;
    }
}

if (! function_exists('get_post_mime_type')) {
    function get_post_mime_type(int $postId): string|false
    {
        return $GLOBALS['wp_test_attachment_mimes'][$postId] ?? false;
    }
}

if (! function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata(int $attachmentId): array|false
    {
        return $GLOBALS['wp_test_attachment_metadata'][$attachmentId] ?? false;
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $postId): string
    {
        return (string) ($GLOBALS['wp_test_post_titles'][$postId] ?? '');
    }
}

if (! function_exists('get_post_field')) {
    function get_post_field(string $field, int $postId): string
    {
        return (string) ($GLOBALS['wp_test_post_fields'][$postId][$field] ?? '');
    }
}

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array
    {
        $body = $GLOBALS['wp_test_remote_get'][$url] ?? '';

        return [
            'response' => ['code' => 200],
            'body' => $body,
        ];
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $response): string
    {
        return (string) ($response['body'] ?? '');
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return false;
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var list<WP_Post> */
        public array $posts = [];

        public int $found_posts = 0;

        public int $max_num_pages = 1;

        /** @param array<string, mixed> $args */
        public function __construct(array $args = [])
        {
            $posts = array_values(array_filter(
                $GLOBALS['wp_test_posts'] ?? [],
                static fn(mixed $post): bool => $post instanceof WP_Post,
            ));

            if (isset($args['post_type'])) {
                $posts = array_values(array_filter(
                    $posts,
                    static fn(WP_Post $post): bool => $post->post_type === $args['post_type'],
                ));
            }

            if (isset($args['post_status'])) {
                $statuses = is_array($args['post_status']) ? $args['post_status'] : [$args['post_status']];
                $posts = array_values(array_filter(
                    $posts,
                    static fn(WP_Post $post): bool => in_array($post->post_status, $statuses, true),
                ));
            }

            if (isset($args['name'])) {
                $posts = array_values(array_filter(
                    $posts,
                    static fn(WP_Post $post): bool => $post->post_name === $args['name'],
                ));
            }

            if (isset($args['post__not_in']) && is_array($args['post__not_in'])) {
                $posts = array_values(array_filter(
                    $posts,
                    static fn(WP_Post $post): bool => ! in_array($post->ID, $args['post__not_in'], true),
                ));
            }

            if (isset($args['meta_query']) && is_array($args['meta_query'])) {
                $posts = array_values(array_filter($posts, static function (WP_Post $post) use ($args): bool {
                    foreach ($args['meta_query'] as $clause) {
                        if (! is_array($clause)) {
                            continue;
                        }

                        $meta = get_post_meta($post->ID, (string) ($clause['key'] ?? ''), true);

                        if ((string) $meta !== (string) ($clause['value'] ?? '')) {
                            return false;
                        }
                    }

                    return true;
                }));
            }

            usort($posts, static fn(WP_Post $a, WP_Post $b): int => $a->ID <=> $b->ID);

            $this->found_posts = count($posts);
            $perPage = (int) ($args['posts_per_page'] ?? 10);

            if ($perPage === -1) {
                $this->posts = $posts;
                $this->max_num_pages = 1;

                return;
            }

            $page = max(1, (int) ($args['paged'] ?? 1));
            $offset = ($page - 1) * $perPage;
            $this->posts = array_slice($posts, $offset, $perPage);
            $this->max_num_pages = max(1, (int) ceil($this->found_posts / max(1, $perPage)));
        }
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id;

        public string $slug;

        public function __construct(int $termId, string $slug)
        {
            $this->term_id = $termId;
            $this->slug = $slug;
        }
    }
}

if (! function_exists('get_term')) {
    /** @var array<int, WP_Term> $GLOBALS['wp_test_terms'] */
    $GLOBALS['wp_test_terms'] = [];

    function get_term(int $termId, string $taxonomy): WP_Term|false
    {
        return $GLOBALS['wp_test_terms'][$termId] ?? false;
    }
}

if (! function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(int|\WP_Post $post): int
    {
        $postId = $post instanceof WP_Post ? $post->ID : $post;

        return (int) ($GLOBALS['wp_test_thumbnails'][$postId] ?? 0);
    }
}

if (! function_exists('get_post_modified_time')) {
    function get_post_modified_time(string $format, bool $gmt, WP_Post $post): string
    {
        return '2026-01-01T00:00:00+00:00';
    }
}
