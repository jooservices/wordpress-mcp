<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\PostTemplates;

use JOOservices\WordPressMcp\Support\ContentTypes;

final class PostTemplateAdmin
{
    private const META_BOX_ID = 'mcp_post_template_settings';

    public function register(): void
    {
        add_submenu_page(
            'jooservices',
            'Post Templates',
            'Post Templates',
            'manage_options',
            'edit.php?post_type=' . PostTemplateTypes::POST_TYPE,
        );

        add_action('add_meta_boxes', [$this, 'registerMetaBoxes']);
        add_action('save_post_' . PostTemplateTypes::POST_TYPE, [$this, 'saveMeta'], 10, 2);
    }

    public function registerMetaBoxes(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            'Template Settings',
            [$this, 'renderMetaBox'],
            PostTemplateTypes::POST_TYPE,
            'side',
            'default',
        );
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('mcp_save_post_template', 'mcp_post_template_nonce');

        $forType = sanitize_key((string) get_post_meta($post->ID, PostTemplateTypes::META_FOR_TYPE, true));
        $forType = ContentTypes::isSupported($forType) ? $forType : ContentTypes::POST;
        $isDefault = (string) get_post_meta($post->ID, PostTemplateTypes::META_IS_DEFAULT, true) === '1';
        $matchCategories = (string) get_post_meta($post->ID, PostTemplateTypes::META_MATCH_CATEGORY_SLUGS, true);
        $matchKeywords = (string) get_post_meta($post->ID, PostTemplateTypes::META_MATCH_TITLE_KEYWORDS, true);
        $defaultCategories = (string) get_post_meta($post->ID, PostTemplateTypes::META_DEFAULT_CATEGORIES, true);
        $defaultTags = (string) get_post_meta($post->ID, PostTemplateTypes::META_DEFAULT_TAGS, true);

        if ($matchCategories !== '' && str_starts_with(trim($matchCategories), '[')) {
            $decoded = json_decode($matchCategories, true);
            $matchCategories = is_array($decoded) ? implode(', ', $decoded) : $matchCategories;
        }

        if ($matchKeywords !== '' && str_starts_with(trim($matchKeywords), '[')) {
            $decoded = json_decode($matchKeywords, true);
            $matchKeywords = is_array($decoded) ? implode(', ', $decoded) : $matchKeywords;
        }

        if ($defaultCategories !== '' && str_starts_with(trim($defaultCategories), '[')) {
            $decoded = json_decode($defaultCategories, true);
            $defaultCategories = is_array($decoded) ? implode(', ', $decoded) : $defaultCategories;
        }

        if ($defaultTags !== '' && str_starts_with(trim($defaultTags), '[')) {
            $decoded = json_decode($defaultTags, true);
            $defaultTags = is_array($decoded) ? implode(', ', $decoded) : $defaultTags;
        }

        echo '<p><label for="mcp_template_for_type"><strong>For content type</strong></label><br />';
        echo '<select name="mcp_template_for_type" id="mcp_template_for_type">';
        echo '<option value="post"' . selected($forType, ContentTypes::POST, false) . '>Post</option>';
        echo '<option value="page"' . selected($forType, ContentTypes::PAGE, false) . '>Page</option>';
        echo '</select></p>';

        echo '<p><label><input type="checkbox" name="mcp_template_is_default" value="1"'
            . checked($isDefault, true, false)
            . ' /> Default template for this type</label></p>';

        echo '<p><label for="mcp_template_match_categories"><strong>Auto-match category slugs</strong></label><br />';
        echo '<input type="text" class="widefat" name="mcp_template_match_categories" id="mcp_template_match_categories" value="'
            . esc_attr($matchCategories)
            . '" placeholder="news, tutorials" /></p>';

        echo '<p><label for="mcp_template_match_keywords"><strong>Auto-match title keywords</strong></label><br />';
        echo '<input type="text" class="widefat" name="mcp_template_match_keywords" id="mcp_template_match_keywords" value="'
            . esc_attr($matchKeywords)
            . '" placeholder="review, guide" /></p>';

        echo '<p><label for="mcp_template_default_categories"><strong>Default category IDs</strong></label><br />';
        echo '<input type="text" class="widefat" name="mcp_template_default_categories" id="mcp_template_default_categories" value="'
            . esc_attr($defaultCategories)
            . '" placeholder="3, 7" /></p>';

        echo '<p><label for="mcp_template_default_tags"><strong>Default tags</strong></label><br />';
        echo '<input type="text" class="widefat" name="mcp_template_default_tags" id="mcp_template_default_tags" value="'
            . esc_attr($defaultTags)
            . '" placeholder="featured, mcp" /></p>';

        echo '<p class="description">Use placeholders in the editor: '
            . '<code>{{title}}</code>, <code>{{excerpt}}</code>, <code>{{content}}</code>.</p>';
    }

    public function saveMeta(int $postId, \WP_Post $post): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (
            ! isset($_POST['mcp_post_template_nonce'])
            || ! wp_verify_nonce(
                sanitize_text_field((string) $_POST['mcp_post_template_nonce']),
                'mcp_save_post_template',
            )
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $forType = sanitize_key((string) ($_POST['mcp_template_for_type'] ?? ContentTypes::POST));
        $forType = ContentTypes::isSupported($forType) ? $forType : ContentTypes::POST;

        update_post_meta($postId, PostTemplateTypes::META_FOR_TYPE, $forType);
        update_post_meta($postId, PostTemplateTypes::META_IS_DEFAULT, isset($_POST['mcp_template_is_default']) ? '1' : '');

        update_post_meta(
            $postId,
            PostTemplateTypes::META_MATCH_CATEGORY_SLUGS,
            $this->encodeList((string) ($_POST['mcp_template_match_categories'] ?? ''), true),
        );

        update_post_meta(
            $postId,
            PostTemplateTypes::META_MATCH_TITLE_KEYWORDS,
            $this->encodeList((string) ($_POST['mcp_template_match_keywords'] ?? ''), false),
        );

        update_post_meta(
            $postId,
            PostTemplateTypes::META_DEFAULT_CATEGORIES,
            wp_json_encode($this->parseIntList((string) ($_POST['mcp_template_default_categories'] ?? ''))) ?: '[]',
        );

        update_post_meta(
            $postId,
            PostTemplateTypes::META_DEFAULT_TAGS,
            wp_json_encode($this->parseStringList((string) ($_POST['mcp_template_default_tags'] ?? ''))) ?: '[]',
        );

        if (isset($_POST['mcp_template_is_default'])) {
            $this->clearOtherDefaults($postId, $forType);
        }
    }

    private function clearOtherDefaults(int $postId, string $forType): void
    {
        $query = new \WP_Query([
            'post_type' => PostTemplateTypes::POST_TYPE,
            'post_status' => PostTemplateTypes::ACTIVE_STATUSES,
            'posts_per_page' => -1,
            'post__not_in' => [$postId],
            'meta_query' => [
                [
                    'key' => PostTemplateTypes::META_FOR_TYPE,
                    'value' => $forType,
                ],
                [
                    'key' => PostTemplateTypes::META_IS_DEFAULT,
                    'value' => '1',
                ],
            ],
        ]);

        foreach ($query->posts as $other) {
            if ($other instanceof \WP_Post) {
                update_post_meta($other->ID, PostTemplateTypes::META_IS_DEFAULT, '');
            }
        }
    }

    private function encodeList(string $raw, bool $asSlug): string
    {
        $items = $this->parseStringList($raw);

        if ($asSlug) {
            $items = array_values(array_filter(array_map(sanitize_title(...), $items)));
        }

        return wp_json_encode($items) ?: '[]';
    }

    /**
     * @return list<string>
     */
    private function parseStringList(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];

        return array_values(array_filter(array_map(static fn(string $item): string => sanitize_text_field($item), $parts)));
    }

    /**
     * @return list<int>
     */
    private function parseIntList(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];

        return array_values(array_filter(array_map(intval(...), $parts)));
    }
}
