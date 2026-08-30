<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use Automatic_Upgrader_Skin;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use Plugin_Upgrader;

final class PluginService
{
    /**
     * @return array{items: list<array<string, bool|string>>}
     */
    public function list(): array
    {
        $this->loadWordPressPluginApi();
        $updates = get_site_transient('update_plugins');
        $availableUpdates = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : [];
        $items = [];

        foreach (get_plugins() as $plugin => $metadata) {
            $items[] = [
                'plugin' => $plugin,
                'name' => (string) ($metadata['Name'] ?? $plugin),
                'version' => (string) ($metadata['Version'] ?? ''),
                'active' => is_plugin_active($plugin),
                'update_available' => isset($availableUpdates[$plugin]),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    public function install(string $slug): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return ['plugin' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $this->loadWordPressPluginApi();
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $information = plugins_api('plugin_information', [
            'slug' => $slug,
            'fields' => ['sections' => false, 'short_description' => false, 'downloaded' => false],
        ]);

        if (is_wp_error($information) || ! isset($information->download_link)) {
            return ['plugin' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());

        if ($upgrader->install((string) $information->download_link) !== true) {
            return ['plugin' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        foreach ($this->list()['items'] as $plugin) {
            if (str_starts_with((string) $plugin['plugin'], $slug . '/')) {
                return ['plugin' => $plugin, 'error' => null];
            }
        }

        return ['plugin' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    public function activate(string $plugin): array
    {
        if (! $this->exists($plugin)) {
            return ['plugin' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $result = activate_plugin($plugin, '', false, true);

        if (is_wp_error($result)) {
            return ['plugin' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get($plugin);
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    public function deactivate(string $plugin): array
    {
        if (! $this->exists($plugin)) {
            return ['plugin' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        deactivate_plugins($plugin, false, false);

        return $this->get($plugin);
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    public function setState(string $plugin, bool $enabled): array
    {
        return $enabled ? $this->activate($plugin) : $this->deactivate($plugin);
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    public function update(string $plugin): array
    {
        if (! $this->exists($plugin)) {
            return ['plugin' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());

        if ($upgrader->upgrade($plugin) !== true) {
            return ['plugin' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get($plugin);
    }

    /**
     * @return array{deleted: bool, error: string|null}
     */
    public function delete(string $plugin): array
    {
        if (! $this->exists($plugin) || is_plugin_active($plugin)) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        if (! delete_plugins([$plugin])) {
            return ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return ['deleted' => true, 'error' => null];
    }

    /**
     * @return array{plugin: array<string, bool|string>|null, error: string|null}
     */
    private function get(string $plugin): array
    {
        foreach ($this->list()['items'] as $item) {
            if ($item['plugin'] === $plugin) {
                return ['plugin' => $item, 'error' => null];
            }
        }

        return ['plugin' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    private function exists(string $plugin): bool
    {
        $this->loadWordPressPluginApi();

        return isset(get_plugins()[$plugin]);
    }

    private function loadWordPressPluginApi(): void
    {
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
}
