<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Term;

final class NavigationService
{
    /**
     * WordPress 6.x exposes wp_get_nav_menu_locations(); 7.x renamed it to
     * get_nav_menu_locations().
     *
     * @return array<string, int>
     */
    private function locations(): array
    {
        if (\function_exists('wp_get_nav_menu_locations')) {
            /** @var array<string, int> $locations */
            $locations = \wp_get_nav_menu_locations();

            return $locations;
        }

        /** @var array<string, int> $locations */
        $locations = \get_nav_menu_locations();

        return $locations;
    }

    /** @param array<string, int> $locations */
    private function persistLocations(array $locations): void
    {
        if (\function_exists('wp_set_nav_menu_locations')) {
            \wp_set_nav_menu_locations($locations);

            return;
        }

        \set_theme_mod('nav_menu_locations', $locations);
    }

    /** @return array{items: list<array<string, mixed>>, locations: array<string, int>} */
    public function list(): array
    {
        $items = [];
        foreach (\wp_get_nav_menus() as $menu) {
            if ($menu instanceof WP_Term) {
                $items[] = $this->normalize($menu, false);
            }
        }

        return ['items' => $items, 'locations' => $this->locations()];
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function get(int $id): array
    {
        $menu = \wp_get_nav_menu_object($id);

        return $menu instanceof WP_Term
            ? ['menu' => $this->normalize($menu, true), 'error' => null]
            : ['menu' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function create(string $name): array
    {
        $id = \wp_create_nav_menu(\sanitize_text_field($name));
        return \is_wp_error($id) ? ['menu' => null, 'error' => ErrorCodes::WORDPRESS_ERROR] : $this->get((int) $id);
    }

    /** @return array{menu: array<string, mixed>|null, error: string|null} */
    public function update(int $id, string $name): array
    {
        $result = \wp_update_nav_menu_object($id, ['menu-name' => \sanitize_text_field($name)]);
        return \is_wp_error($result) ? ['menu' => null, 'error' => ErrorCodes::WORDPRESS_ERROR] : $this->get($id);
    }

    /** @return array{deleted: bool, error: string|null} */
    public function delete(int $id): array
    {
        return \wp_delete_nav_menu($id)
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /**
     * @param array<string, int> $locations
     * @return array<string, int>
     */
    public function setLocations(array $locations): array
    {
        $this->persistLocations($locations);

        return $this->locations();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{item: array<string, mixed>|null, error: string|null}
     */
    public function saveItem(int $menuId, ?int $itemId, array $data): array
    {
        $touchedItemIds = [];

        return $this->withinMenuTransaction(
            $menuId,
            function () use ($menuId, $itemId, $data, &$touchedItemIds): array {
                return $this->saveItemInTransaction($menuId, $itemId, $data, $touchedItemIds);
            },
            $touchedItemIds,
        );
    }

    /**
     * @return array{item: array<string, mixed>|null, error: string|null}
     */
    public function addObjectItem(
        int $menuId,
        string $objectType,
        int $objectId,
        int $parentId,
        int $position,
        ?string $title = null,
    ): array {
        $touchedItemIds = [];

        return $this->withinMenuTransaction(
            $menuId,
            function () use ($menuId, $objectType, $objectId, $parentId, $position, $title, &$touchedItemIds): array {
                if (! \wp_get_nav_menu_object($menuId) instanceof WP_Term || $objectId < 1 || $position < 0) {
                    return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
                }

                $taxonomy = $objectType === 'category' ? 'category' : null;
                if ($taxonomy === null && $objectType !== 'page') {
                    return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
                }

                if ($objectType === 'page') {
                    $page = \get_post($objectId);
                    if (! $page instanceof \WP_Post || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                        return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
                    }
                } elseif (! \get_term($objectId, $taxonomy) instanceof WP_Term) {
                    return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
                }

                if (! $this->isValidParent($menuId, $parentId, null)) {
                    return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
                }

                $orderedItemIds = \array_map(
                    static fn($item): int => (int) $item->ID,
                    \wp_get_nav_menu_items($menuId) ?: [],
                );
                /** @var list<int> $orderedItemIds */
                $touchedItemIds = $orderedItemIds;

                $itemId = \wp_update_nav_menu_item($menuId, 0, [
                    'menu-item-title' => \sanitize_text_field($title ?? ''),
                    'menu-item-type' => $taxonomy === null ? 'post_type' : 'taxonomy',
                    'menu-item-object' => $objectType,
                    'menu-item-object-id' => $objectId,
                    'menu-item-status' => 'publish',
                    'menu-item-parent-id' => $parentId,
                    'menu-item-position' => $position,
                ]);
                if (\is_wp_error($itemId)) {
                    return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
                }

                $savedItemId = (int) $itemId;
                $touchedItemIds[] = $savedItemId;
                if (! $this->reorderItems($orderedItemIds, $savedItemId, $position, $touchedItemIds)) {
                    return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
                }

                foreach (\wp_get_nav_menu_items($menuId) ?: [] as $menuItem) {
                    if ((int) $menuItem->ID === $savedItemId) {
                        return [
                            'item' => [
                                'id' => $savedItemId,
                                'title' => (string) $menuItem->title,
                                'url' => (string) $menuItem->url,
                                'type' => (string) $menuItem->type,
                                'object_id' => (int) $menuItem->object_id,
                            ],
                            'error' => null,
                        ];
                    }
                }

                return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
            },
            $touchedItemIds,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int> $touchedItemIds
     * @param-out list<int> $touchedItemIds
     * @return array{item: array<string, mixed>|null, error: string|null}
     */
    private function saveItemInTransaction(int $menuId, ?int $itemId, array $data, array &$touchedItemIds): array
    {
        if (! \wp_get_nav_menu_object($menuId) instanceof WP_Term) {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        if ($itemId !== null) {
            $existing = \get_post($itemId);
            if (
                ! $existing instanceof \WP_Post
                || $existing->post_type !== 'nav_menu_item'
                || ! \has_term($menuId, 'nav_menu', $itemId)
                || \get_post_meta($itemId, '_menu_item_type', true) !== 'custom'
            ) {
                return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }
        }

        $parentId = \array_key_exists('parent_id', $data)
            ? (int) $data['parent_id']
            : ($itemId !== null ? (int) \get_post_meta($itemId, '_menu_item_menu_item_parent', true) : 0);
        if (! $this->isValidParent($menuId, $parentId, $itemId)) {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $orderedItemIds = \array_map(
            static fn($item): int => (int) $item->ID,
            \wp_get_nav_menu_items($menuId) ?: [],
        );
        /** @var list<int> $orderedItemIds */
        $touchedItemIds = $orderedItemIds;
        $currentPosition = $itemId !== null ? \array_search($itemId, $orderedItemIds, true) : false;
        $position = \array_key_exists('position', $data)
            ? (int) $data['position']
            : ($currentPosition !== false ? $currentPosition + 1 : 0);
        if ($position < 0) {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $args = [
            'menu-item-title' => \sanitize_text_field((string) ($data['title'] ?? '')),
            'menu-item-url' => \esc_url_raw((string) ($data['url'] ?? '')),
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => $parentId,
            'menu-item-position' => $position,
        ];
        if ($args['menu-item-title'] === '' || $args['menu-item-url'] === '') {
            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }
        $id = \wp_update_nav_menu_item($menuId, $itemId ?? 0, $args);
        if (\is_wp_error($id)) {
            return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }
        $savedItemId = (int) $id;
        $touchedItemIds[] = $savedItemId;
        if (! $this->reorderItems($orderedItemIds, $savedItemId, $position, $touchedItemIds)) {
            return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $item = \get_post($savedItemId);
        return $item instanceof \WP_Post
            ? [
                'item' => [
                    'id' => $item->ID, 'title' => $item->post_title,
                    'url' => \get_post_meta($item->ID, '_menu_item_url', true),
                ],
                'error' => null,
            ]
            : ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }

    /** @return array{deleted: bool, error: string|null} */
    public function deleteItem(int $menuId, int $itemId): array
    {
        $touchedItemIds = [$itemId];
        $result = $this->withinMenuTransaction(
            $menuId,
            function () use ($menuId, $itemId): array {
                $deleted = $this->deleteItemInTransaction($menuId, $itemId);

                return [
                    'item' => $deleted['deleted'] ? ['deleted' => true] : null,
                    'error' => $deleted['error'],
                ];
            },
            $touchedItemIds,
        );

        return $result['error'] === null
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => $result['error']];
    }

    /** @return array{deleted: bool, error: string|null} */
    private function deleteItemInTransaction(int $menuId, int $itemId): array
    {
        $item = \get_post($itemId);
        if (
            ! $item instanceof \WP_Post
            || $item->post_type !== 'nav_menu_item'
            || ! \has_term($menuId, 'nav_menu', $itemId)
            || \get_post_meta($itemId, '_menu_item_type', true) !== 'custom'
        ) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        return \wp_delete_post($itemId, true) instanceof \WP_Post
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    private function isValidParent(int $menuId, int $parentId, ?int $itemId): bool
    {
        if ($parentId < 0) {
            return false;
        }
        $ancestorId = $parentId;
        $ancestors = [];
        while ($ancestorId > 0) {
            if (($itemId !== null && $ancestorId === $itemId) || isset($ancestors[$ancestorId])) {
                return false;
            }
            $ancestors[$ancestorId] = true;

            $ancestor = \get_post($ancestorId);
            if (
                ! $ancestor instanceof \WP_Post
                || $ancestor->post_type !== 'nav_menu_item'
                || ! \has_term($menuId, 'nav_menu', $ancestorId)
            ) {
                return false;
            }

            $ancestorId = (int) \get_post_meta($ancestorId, '_menu_item_menu_item_parent', true);
        }

        return true;
    }

    /**
     * @param list<int> $orderedItemIds
     * @param list<int> $touchedItemIds
     * @param-out list<int> $touchedItemIds
     */
    private function reorderItems(array $orderedItemIds, int $savedItemId, int $position, array &$touchedItemIds): bool
    {
        $orderedItemIds = \array_values(\array_filter(
            $orderedItemIds,
            static fn(int $existingId): bool => $existingId !== $savedItemId,
        ));
        $targetPosition = $position === 0
            ? \count($orderedItemIds) + 1
            : \min($position, \count($orderedItemIds) + 1);
        \array_splice($orderedItemIds, $targetPosition - 1, 0, [$savedItemId]);
        $touchedItemIds = \array_values(\array_unique([...$touchedItemIds, ...$orderedItemIds]));

        foreach ($orderedItemIds as $index => $orderedItemId) {
            $orderedItem = \get_post($orderedItemId);
            if (! $orderedItem instanceof \WP_Post) {
                return false;
            }
            if ((int) $orderedItem->menu_order === $index + 1) {
                continue;
            }

            $updatedOrder = \wp_update_post(['ID' => $orderedItemId, 'menu_order' => $index + 1], true);
            if (\is_wp_error($updatedOrder)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(): array{item: array<string, mixed>|null, error: string|null} $operation
     * @param list<int> $touchedItemIds
     * @return array{item: array<string, mixed>|null, error: string|null}
     */
    private function withinMenuTransaction(int $menuId, callable $operation, array &$touchedItemIds): array
    {
        global $wpdb;

        if ($wpdb->query('START TRANSACTION') === false) {
            return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $lockedMenuId = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE",
            $menuId,
        ));
        if ((int) $lockedMenuId !== $menuId) {
            $wpdb->query('ROLLBACK');

            return ['item' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        try {
            $result = $operation();
            if ($result['error'] !== null) {
                $wpdb->query('ROLLBACK');
                $this->clearCaches($menuId, $touchedItemIds);

                return $result;
            }

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $this->clearCaches($menuId, $touchedItemIds);

                return ['item' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
            }

            return $result;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            $this->clearCaches($menuId, $touchedItemIds);

            throw $exception;
        }
    }

    /** @param list<int> $itemIds */
    private function clearCaches(int $menuId, array $itemIds): void
    {
        foreach (\array_unique($itemIds) as $itemId) {
            if (\function_exists('clean_post_cache')) {
                \clean_post_cache($itemId);
            }
        }

        if (\function_exists('clean_term_cache')) {
            \clean_term_cache($menuId, 'nav_menu');
        }
    }

    /** @return array<string, mixed> */
    private function normalize(WP_Term $menu, bool $withItems): array
    {
        $result = ['id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug];
        if ($withItems) {
            $result['items'] = \array_map(static fn($item): array => [
                'id' => (int) $item->ID, 'title' => $item->title, 'url' => $item->url,
                'parent_id' => (int) $item->menu_item_parent, 'position' => (int) $item->menu_order,
                'type' => $item->type, 'object_id' => (int) $item->object_id,
            ], \wp_get_nav_menu_items($menu) ?: []);
        }
        return $result;
    }
}
