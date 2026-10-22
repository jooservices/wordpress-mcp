<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('__return_false')) {
    function __return_false(): bool
    {
        return false;
    }
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
    define('OBJECT_K', 'OBJECT_K');
    define('ARRAY_A', 'ARRAY_A');
    define('ARRAY_N', 'ARRAY_N');
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}

if (! function_exists('sanitize_user')) {
    function sanitize_user(string $username, bool $strict = false): string
    {
        return strtolower(preg_replace('/[^a-z0-9_.-]/', '', $username) ?? '');
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ?: false;
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

    // adoptOrphan() require_once's these; the functions they'd define in
    // real WordPress (wp_generate_attachment_metadata, etc.) are stubbed
    // directly below instead, so these just need to exist and be empty.
    $wpAdminIncludes = $absPath . 'wp-admin/includes/';

    if (! is_dir($wpAdminIncludes)) {
        mkdir($wpAdminIncludes, 0777, true);
    }

    foreach (['file.php', 'media.php', 'image.php', 'user.php'] as $stubFile) {
        $stubPath = $wpAdminIncludes . $stubFile;

        if (! is_file($stubPath)) {
            file_put_contents($stubPath, "<?php\n");
        }
    }
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
    $GLOBALS['wp_test_option_updates'] = [];

    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['wp_test_options'][$name] ?? $default;
    }

    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['wp_test_options'][$name] = $value;
        $GLOBALS['wp_test_option_updates'][] = [$name, $value];

        return true;
    }

    function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
    {
        if (array_key_exists($name, $GLOBALS['wp_test_options'])) {
            return false;
        }

        $GLOBALS['wp_test_options'][$name] = $value;

        return true;
    }

    function delete_option(string $name): bool
    {
        unset($GLOBALS['wp_test_options'][$name]);

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

    function remove_filter(string $tag, callable $callback): bool
    {
        unset($GLOBALS['wp_test_filters'][$tag]);

        return true;
    }

    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        $callback = $GLOBALS['wp_test_filters'][$tag] ?? null;

        return is_callable($callback) ? $callback($value, ...$args) : $value;
    }
}

if (! function_exists('user_can')) {
    function user_can(int $userId, string $capability, mixed ...$args): bool
    {
        return (bool) ($GLOBALS['wp_test_user_caps'][$userId][$capability] ?? true);
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['wp_test_current_user_id'] ?? 1);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return user_can(get_current_user_id(), $capability, ...$args);
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

        public int $menu_order = 0;

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

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
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

if (! function_exists('wp_get_original_image_path')) {
    function wp_get_original_image_path(int $attachmentId): string|false
    {
        return $GLOBALS['wp_test_original_image_files'][$attachmentId] ?? false;
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
    function get_the_title(int|WP_Post $post): string
    {
        $postId = $post instanceof WP_Post ? $post->ID : $post;

        return (string) ($GLOBALS['wp_test_post_titles'][$postId] ?? '');
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int|WP_Post $post): string
    {
        $postId = $post instanceof WP_Post ? $post->ID : $post;

        return 'https://example.test/?p=' . $postId;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }
}

if (! function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $numWords = 55, string $more = '…'): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return count($words) <= $numWords ? implode(' ', $words) : implode(' ', array_slice($words, 0, $numWords)) . $more;
    }
}

if (! function_exists('get_userdata')) {
    if (! class_exists('WP_User')) {
        #[\AllowDynamicProperties]
        class WP_User
        {
            public int $ID = 0;

            public string $user_login = '';

            public string $user_email = '';

            public string $user_url = '';

            public string $user_registered = '';

            public string $display_name = '';

            public string $description = '';

            public string $first_name = '';

            public string $last_name = '';

            public string $user_pass = '';

            /** @var list<string> */
            public array $roles = [];

            public function __construct(int $userId = 0)
            {
                $this->ID = $userId;
                $source = $GLOBALS['wp_test_users'][$userId] ?? [];

                if ($source instanceof self && $source !== $this) {
                    foreach (get_object_vars($source) as $key => $value) {
                        $this->{$key} = $value;
                    }
                } elseif (is_array($source)) {
                    foreach ($source as $key => $value) {
                        $this->{$key} = $value;
                    }
                }
            }

            public function set_role(string $role): void
            {
                $this->roles = [$role];
                $GLOBALS['wp_test_users'][$this->ID] = $this;
            }
        }
    }

    function get_userdata(int $userId): mixed
    {
        return $GLOBALS['wp_test_users'][$userId] ?? null;
    }

    function wp_insert_user(array $userdata): int|WP_Error
    {
        $GLOBALS['wp_test_insert_user_calls'] = (int) ($GLOBALS['wp_test_insert_user_calls'] ?? 0) + 1;
        $userId = (int) ($GLOBALS['wp_test_next_user_id'] ?? 1000);
        $GLOBALS['wp_test_next_user_id'] = $userId + 1;
        $user = new WP_User($userId);

        foreach ($userdata as $key => $value) {
            if ($key === 'role') {
                $user->roles = [(string) $value];
            } elseif (property_exists($user, $key)) {
                $user->{$key} = (string) $value;
            }
        }

        $user->user_registered = '2026-01-01 00:00:00';
        $GLOBALS['wp_test_users'][$userId] = $user;

        return $userId;
    }

    function wp_update_user(array $userdata): int|WP_Error
    {
        $GLOBALS['wp_test_update_user_calls'] = (int) ($GLOBALS['wp_test_update_user_calls'] ?? 0) + 1;
        $userId = (int) ($userdata['ID'] ?? 0);
        $user = $GLOBALS['wp_test_users'][$userId] ?? null;

        if (! $user instanceof WP_User) {
            return new WP_Error('invalid_user', 'User not found.');
        }

        foreach ($userdata as $key => $value) {
            if ($key !== 'ID' && property_exists($user, $key)) {
                $user->{$key} = (string) $value;
            }
        }

        return $userId;
    }
}

if (! function_exists('wp_roles')) {
    function wp_roles(): object
    {
        return (object) ['roles' => $GLOBALS['wp_test_roles'] ?? ['subscriber' => []]];
    }
}

if (! function_exists('get_editable_roles')) {
    function get_editable_roles(): array
    {
        return $GLOBALS['wp_test_editable_roles_by_user'][get_current_user_id()]
            ?? $GLOBALS['wp_test_editable_roles']
            ?? wp_roles()->roles;
    }
}

if (! function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $userId): int
    {
        $GLOBALS['wp_test_current_user'] = $userId;

        return $userId;
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return '2026-01-01 00:00:00';
    }
}

if (! function_exists('get_the_terms')) {
    function get_the_terms(WP_Post $post, string $taxonomy): array|false
    {
        return $GLOBALS['wp_test_post_terms'][$post->ID][$taxonomy] ?? false;
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
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_tempnam')) {
    function wp_tempnam(string $filename = ''): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'wpmcp');
    }
}

if (! function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

if (! function_exists('media_handle_sideload')) {
    /** Sideload always fails in unit tests; records the call so tests can see it ran. */
    function media_handle_sideload(array $file, int $postId = 0): WP_Error
    {
        $GLOBALS['wp_test_sideload_calls'] = ($GLOBALS['wp_test_sideload_calls'] ?? 0) + 1;

        if (isset($GLOBALS['wp_test_sideload_hook'])) {
            ($GLOBALS['wp_test_sideload_hook'])();
        }

        return new WP_Error('sideload_failed', 'stub');
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
        ) {
        }
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

        public string $name = '';

        public string $slug;

        public function __construct(int $termId, string $slug)
        {
            $this->term_id = $termId;
            $this->slug = $slug;
        }
    }
}

if (! function_exists('wp_get_nav_menu_object')) {
    /** @var array<int, WP_Term> $GLOBALS['wp_test_nav_menus'] */
    $GLOBALS['wp_test_nav_menus'] = [];

    function wp_get_nav_menu_object(int $menuId): WP_Term|false
    {
        return $GLOBALS['wp_test_nav_menus'][$menuId] ?? false;
    }
}

if (! function_exists('wp_get_nav_menu_items')) {
    /** @return list<WP_Post> */
    function wp_get_nav_menu_items(int|WP_Term $menu): array
    {
        $menuId = $menu instanceof WP_Term ? $menu->term_id : $menu;
        $items = [];

        foreach ($GLOBALS['wp_test_posts'] ?? [] as $post) {
            if (
                $post instanceof WP_Post
                && $post->post_type === 'nav_menu_item'
                && has_term($menuId, 'nav_menu', $post->ID)
            ) {
                $items[] = $post;
            }
        }

        usort($items, static fn(WP_Post $left, WP_Post $right): int => $left->menu_order <=> $right->menu_order);

        return $items;
    }
}

if (! function_exists('wp_delete_post')) {
    /** @var list<array{post_id: int, force_delete: bool}> $GLOBALS['wp_test_delete_post_calls'] */
    $GLOBALS['wp_test_delete_post_calls'] = [];

    function wp_delete_post(int $postId, bool $forceDelete = false): WP_Post|false
    {
        $GLOBALS['wp_test_delete_post_calls'][] = ['post_id' => $postId, 'force_delete' => $forceDelete];

        return $GLOBALS['wp_test_posts'][$postId] ?? false;
    }
}

if (! function_exists('has_term')) {
    /** @var array<int, list<int>> $GLOBALS['wp_test_nav_menu_terms'] */
    $GLOBALS['wp_test_nav_menu_terms'] = [];

    function has_term(int $term, string $taxonomy, int $postId): bool
    {
        return in_array($term, $GLOBALS['wp_test_nav_menu_terms'][$postId] ?? [], true);
    }
}

if (! function_exists('wp_update_nav_menu_item')) {
    $GLOBALS['wp_test_nav_menu_update_calls'] = 0;

    function wp_update_nav_menu_item(int $menuId, int $menuItemDbId, array $menuItemData): int|WP_Error
    {
        $GLOBALS['wp_test_nav_menu_update_calls']++;

        if ($GLOBALS['wp_test_nav_menu_update_error'] ?? false) {
            return new WP_Error('menu_item_failed', 'Unable to save menu item.');
        }

        $id = $menuItemDbId > 0 ? $menuItemDbId : (int) ($GLOBALS['wp_test_next_nav_menu_item_id'] ?? 1);
        if ($menuItemDbId === 0) {
            $GLOBALS['wp_test_next_nav_menu_item_id'] = $id + 1;
        }

        $post = $GLOBALS['wp_test_posts'][$id] ?? new WP_Post($id, '', 'nav_menu_item');
        $post->post_type = 'nav_menu_item';
        $post->post_title = (string) ($menuItemData['menu-item-title'] ?? '');
        $post->menu_order = (int) ($menuItemData['menu-item-position'] ?? 0);
        $GLOBALS['wp_test_posts'][$id] = $post;
        $GLOBALS['wp_test_nav_menu_terms'][$id] = [$menuId];
        $GLOBALS['wp_test_postmeta'][$id]['_menu_item_type'] = 'custom';
        $GLOBALS['wp_test_postmeta'][$id]['_menu_item_url'] = (string) ($menuItemData['menu-item-url'] ?? '');
        $GLOBALS['wp_test_postmeta'][$id]['_menu_item_menu_item_parent'] = (string) ($menuItemData['menu-item-parent-id'] ?? 0);

        return $id;
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

if (! function_exists('wp_delete_term')) {
    $GLOBALS['wp_test_delete_term_result'] = true;
    $GLOBALS['wp_test_delete_term_calls'] = [];

    function wp_delete_term(int $termId, string $taxonomy): bool|int|WP_Error
    {
        $GLOBALS['wp_test_delete_term_calls'][] = ['term_id' => $termId, 'taxonomy' => $taxonomy];

        return $GLOBALS['wp_test_delete_term_result'] ?? true;
    }
}

if (! function_exists('set_post_thumbnail')) {
    function set_post_thumbnail(int $postId, int $thumbnailId): bool
    {
        if ($GLOBALS['wp_test_set_thumbnail_fails'] ?? false) {
            return false;
        }

        $GLOBALS['wp_test_thumbnails'][$postId] = $thumbnailId;

        return true;
    }
}

if (! function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image(int $attachmentId): bool
    {
        return str_starts_with((string) get_post_mime_type($attachmentId), 'image/');
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

if (! function_exists('get_post_time')) {
    function get_post_time(string $format, bool $gmt, int|WP_Post $post): string
    {
        return '2026-01-01T00:00:00+00:00';
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr): int|WP_Error
    {
        $GLOBALS['wp_test_post_update_calls'] = (int) ($GLOBALS['wp_test_post_update_calls'] ?? 0) + 1;
        if (($GLOBALS['wp_test_post_update_error_on_call'] ?? null) === $GLOBALS['wp_test_post_update_calls']) {
            return new WP_Error('post_update_failed', 'Unable to update post.');
        }

        $id = (int) ($postarr['ID'] ?? 0);
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return 0;
        }

        if (isset($postarr['post_title'])) {
            $submitted = (string) $postarr['post_title'];
            // Simulates WordPress core rewriting post_title on save (e.g.
            // wp_encode_emoji() on a legacy utf8 DB column) — tests set
            // $GLOBALS['wp_test_title_save_transform'][$submitted] to the
            // value WP would actually persist.
            $stored = $GLOBALS['wp_test_title_save_transform'][$submitted] ?? $submitted;
            $post->post_title = $stored;
            $GLOBALS['wp_test_post_titles'][$id] = $stored;
        }

        if (isset($postarr['post_excerpt'])) {
            $post->post_excerpt = (string) $postarr['post_excerpt'];
            $GLOBALS['wp_test_post_fields'][$id]['post_excerpt'] = $post->post_excerpt;
        }

        if (isset($postarr['post_content'])) {
            $post->post_content = (string) $postarr['post_content'];
            $GLOBALS['wp_test_post_fields'][$id]['post_content'] = $post->post_content;
        }

        if (isset($postarr['menu_order'])) {
            $post->menu_order = (int) $postarr['menu_order'];
        }

        return $id;
    }
}

if (! function_exists('wp_insert_attachment')) {
    /** @var int $GLOBALS['wp_test_next_attachment_id'] */
    $GLOBALS['wp_test_next_attachment_id'] = 9000;

    function wp_insert_attachment(array $postarr, string $file = ''): int
    {
        $id = $GLOBALS['wp_test_next_attachment_id']++;

        $post = new WP_Post($id, (string) ($postarr['post_content'] ?? ''), 'attachment');
        $post->post_title = (string) ($postarr['post_title'] ?? '');
        $post->post_status = (string) ($postarr['post_status'] ?? 'inherit');

        $GLOBALS['wp_test_posts'][$id] = $post;
        $GLOBALS['wp_test_post_titles'][$id] = $post->post_title;
        $GLOBALS['wp_test_attachment_mimes'][$id] = (string) ($postarr['post_mime_type'] ?? '');
        $GLOBALS['wp_test_attachment_files'][$id] = $file;

        $uploadDir = wp_upload_dir();
        $relative = ltrim(str_replace((string) $uploadDir['basedir'], '', $file), '/');
        $GLOBALS['wp_test_attachment_urls'][$id] = $uploadDir['baseurl'] . '/' . $relative;

        update_post_meta($id, '_wp_attached_file', $relative);

        return $id;
    }
}

if (! function_exists('wp_generate_attachment_metadata')) {
    /**
     * Real WordPress renames the attached file to a `-scaled` variant here
     * for oversized images. Tests simulate that by pre-registering a target
     * path in $GLOBALS['wp_test_scale_rename'][$file].
     */
    function wp_generate_attachment_metadata(int $attachmentId, string $file): array
    {
        $thresholdEnabled = apply_filters('big_image_size_threshold', 2560) !== false;
        $renameTo = $thresholdEnabled ? ($GLOBALS['wp_test_scale_rename'][$file] ?? null) : null;

        if (is_string($renameTo) && $renameTo !== '') {
            $uploadDir = wp_upload_dir();
            $relative = ltrim(str_replace((string) $uploadDir['basedir'], '', $renameTo), '/');

            $GLOBALS['wp_test_attachment_files'][$attachmentId] = $renameTo;
            $GLOBALS['wp_test_attachment_urls'][$attachmentId] = $uploadDir['baseurl'] . '/' . $relative;
            update_post_meta($attachmentId, '_wp_attached_file', $relative);
        }

        return [
            'width' => 100,
            'height' => 100,
            'sizes' => ['full' => ['file' => basename((string) ($GLOBALS['wp_test_attachment_files'][$attachmentId] ?? $file))]],
        ];
    }
}

if (! function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata(int $attachmentId, array $metadata): bool
    {
        $GLOBALS['wp_test_attachment_metadata'][$attachmentId] = $metadata;

        return true;
    }
}

if (! class_exists('wpdb')) {
    /**
     * Minimal stand-in for the exact query shape MediaService::adoptOrphan()
     * runs against `_wp_attached_file` postmeta — not a general SQL engine.
     * Reads from the same $GLOBALS['wp_test_postmeta'] store as
     * get_post_meta()/update_post_meta() so it can't drift out of sync.
     */
    class wpdb
    {
        public string $posts = 'wp_posts';

        public string $postmeta = 'wp_postmeta';

        public string $terms = 'wp_terms';

        public string $prefix = 'wp_';

        /** @var list<string> */
        public array $queries = [];

        /** @var list<array{table: string, data: array<string, mixed>, format: list<string>}> */
        public array $inserts = [];

        /**
         * @param array<string, mixed> $data
         * @param list<string> $format
         */
        public function insert(string $table, array $data, array $format = []): int|false
        {
            $this->inserts[] = ['table' => $table, 'data' => $data, 'format' => $format];

            return 1;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            $i = 0;

            return (string) preg_replace_callback('/%[sd]/', static function (array $m) use ($args, &$i): string {
                $value = $args[$i] ?? '';
                $i++;

                return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
            }, $query);
        }

        public function get_var(string $query): ?string
        {
            $this->queries[] = $query;

            if (preg_match('/SELECT term_id FROM wp_terms WHERE term_id = (\d+) FOR UPDATE/', $query, $menuMatch)) {
                $menuId = (int) $menuMatch[1];

                return isset($GLOBALS['wp_test_nav_menus'][$menuId]) ? (string) $menuId : null;
            }

            if (! preg_match("/meta_key = '([^']*)' AND meta_value = '(.*)' (?:ORDER BY post_id ASC )?LIMIT 1/s", $query, $m)) {
                return null;
            }

            [, $key, $value] = $m;
            $value = stripslashes($value);

            $matches = [];

            foreach ($GLOBALS['wp_test_postmeta'] ?? [] as $postId => $meta) {
                if (($meta[$key] ?? null) === $value) {
                    $matches[] = (int) $postId;
                }
            }

            if ($matches === []) {
                return null;
            }

            sort($matches);

            return (string) $matches[0];
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;

            if ($query === 'START TRANSACTION') {
                $GLOBALS['wp_test_transaction_snapshot'] = unserialize(serialize([
                    'posts' => $GLOBALS['wp_test_posts'] ?? [],
                    'postmeta' => $GLOBALS['wp_test_postmeta'] ?? [],
                    'terms' => $GLOBALS['wp_test_nav_menu_terms'] ?? [],
                ]));

                return 0;
            }

            if ($query === 'ROLLBACK') {
                $snapshot = $GLOBALS['wp_test_transaction_snapshot'] ?? [];
                $GLOBALS['wp_test_posts'] = $snapshot['posts'] ?? [];
                $GLOBALS['wp_test_postmeta'] = $snapshot['postmeta'] ?? [];
                $GLOBALS['wp_test_nav_menu_terms'] = $snapshot['terms'] ?? [];
                unset($GLOBALS['wp_test_transaction_snapshot']);

                return 0;
            }

            if ($query === 'COMMIT') {
                unset($GLOBALS['wp_test_transaction_snapshot']);

                return 0;
            }

            return false;
        }

        /**
         * Stands in for MediaOrphanScanner::knownRelativePaths()' first
         * query (all `_wp_attached_file` values). Tests that need known
         * attachments to exist set $GLOBALS['wp_test_wpdb_col_results'];
         * an empty default matches "no attachments registered yet".
         *
         * @return list<string>
         */
        public function get_col(string $query): array
        {
            return $GLOBALS['wp_test_wpdb_col_results'] ?? [];
        }

        /**
         * Stands in for both MediaOrphanScanner queries that return rows
         * (broken-attachments join, and `_wp_attachment_metadata` values).
         * Tests set $GLOBALS['wp_test_wpdb_row_results'] for whichever one
         * they're exercising; an empty default matches "nothing registered".
         *
         * @return list<array<string, mixed>>
         */
        public function get_results(string $query, mixed ...$args): array
        {
            return $GLOBALS['wp_test_wpdb_row_results'] ?? [];
        }
    }

    $GLOBALS['wpdb'] = new wpdb();
}
