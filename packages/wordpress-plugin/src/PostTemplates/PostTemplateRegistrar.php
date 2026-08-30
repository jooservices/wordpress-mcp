<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\PostTemplates;

final class PostTemplateRegistrar
{
    public function register(): void
    {
        register_post_type(PostTemplateTypes::POST_TYPE, [
            'labels' => [
                'name' => 'Post Templates',
                'singular_name' => 'Post Template',
                'add_new' => 'Add Template',
                'add_new_item' => 'Add Post Template',
                'edit_item' => 'Edit Post Template',
                'new_item' => 'New Post Template',
                'view_item' => 'View Post Template',
                'search_items' => 'Search Post Templates',
                'not_found' => 'No post templates found',
                'not_found_in_trash' => 'No post templates found in Trash',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }
}
