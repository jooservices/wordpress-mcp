<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Term;

final class NavigationService
{
    /** @return array{items: list<array<string, mixed>>, locations: array<string, int>} */
    public function list(): array
    {
        $items = [];
        foreach (wp_get_nav_menus() as $menu) {
            if ($menu instanceof WP_Term) {
                $items[] = $this->normalize($menu, false);
            }
        }

        /** @phpstan-ignore-next-line WordPress core navigation function is loaded at runtime. */
        return ['items' => $items, 'locations' => wp_get_nav_menu_locations()];
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function get(int $id): array
    {
        $menu = wp_get_nav_menu_object($id);

        return $menu instanceof WP_Term
            ? ['menu' => $this->normalize($menu, true), 'error' => null]
            : ['menu' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function create(string $name): array
    {
        $id = wp_create_nav_menu(sanitize_text_field($name));
        return is_wp_error($id) ? ['menu' => null, 'error' => ErrorCodes::WORDPRESS_ERROR] : $this->get((int) $id);
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function update(int $id, string $name): array
    {
        $result = wp_update_nav_menu_object($id, ['menu-name' => sanitize_text_field($name)]);
        return is_wp_error($result) ? ['menu' => null, 'error' => ErrorCodes::WORDPRESS_ERROR] : $this->get($id);
    }

    /** @return array{deleted: bool, error: string|null} */
    public function delete(int $id): array
    {
        return wp_delete_nav_menu($id)
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /** @param array<string, int> $locations */
    public function setLocations(array $locations): void
    {
        /** @phpstan-ignore-next-line WordPress core navigation function is loaded at runtime. */
        wp_set_nav_menu_locations($locations);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{item: array<string, mixed>|null, error: string|null}
     */
    public function saveItem(int $menuId, ?int $itemId, array $data): array
    {
        if (! wp_get_nav_menu_object($menuId) instanceof WP_Term) {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $args = [
            'menu-item-title' => sanitize_text_field((string) ($data['title'] ?? '')),
            'menu-item-url' => esc_url_raw((string) ($data['url'] ?? '')),
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => (int) ($data['parent_id'] ?? 0),
            'menu-item-position' => (int) ($data['position'] ?? 0),
        ];
        if ($args['menu-item-title'] === '' || $args['menu-item-url'] === '') {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $id = wp_update_nav_menu_item($menuId, $itemId ?? 0, $args);
        if (is_wp_error($id)) {
            return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }
        $item = get_post((int) $id);
        return $item instanceof \WP_Post
            ? [
                'item' => [
                    'id' => $item->ID, 'title' => $item->post_title,
                    'url' => get_post_meta($item->ID, '_menu_item_url', true),
                ],
                'error' => null,
            ]
            : ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }

    /** @return array{deleted: bool, error: string|null} */
    public function deleteItem(int $itemId): array
    {
        return wp_delete_post($itemId, true) instanceof \WP_Post
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /** @return array<string, mixed> */
    private function normalize(WP_Term $menu, bool $withItems): array
    {
        $result = ['id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug];
        if ($withItems) {
            $result['items'] = array_map(static fn($item): array => [
                'id' => (int) $item->ID, 'title' => $item->title, 'url' => $item->url,
                'parent_id' => (int) $item->menu_item_parent, 'position' => (int) $item->menu_order,
                'type' => $item->type, 'object_id' => (int) $item->object_id,
            ], wp_get_nav_menu_items($menu) ?: []);
        }
        return $result;
    }
}
