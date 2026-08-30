<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Auth;

use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Support\ContentTypes;

final class ScopeChecker
{
    /** @var list<string> */
    public const ALL_SCOPES = [
        'site.read',
        'seo.robots.update',
        'site.health.read',
        'site.maintenance',
        'settings.read',
        'settings.update',
        'plugins.read',
        'plugins.install',
        'plugins.activate',
        'plugins.deactivate',
        'plugins.update',
        'plugins.delete',
        'themes.read',
        'themes.install',
        'themes.activate',
        'themes.update',
        'themes.delete',
        'appearance.read',
        'appearance.update',
        'posts.revisions.read',
        'posts.revisions.restore',
        'pages.revisions.read',
        'pages.revisions.restore',
        'redirects.read',
        'redirects.update',
        'users.read',
        'users.create',
        'users.update',
        'users.assign_roles',
        'users.delete',
        'posts.read',
        'posts.templates.read',
        'posts.create',
        'posts.update',
        'posts.publish',
        'posts.delete',
        'pages.read',
        'pages.templates.read',
        'pages.create',
        'pages.update',
        'pages.publish',
        'pages.delete',
        'comments.read',
        'comments.moderate',
        'terms.read',
        'media.read',
        'media.upload',
        'media.embed',
        'media.update',
        'media.delete',
        'updates.read',
        'core.update',
    ];

    public static function hasScope(Connection $connection, string $scope): bool
    {
        return in_array($scope, $connection->scopes, true);
    }

    public static function canReadContent(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.read' : 'posts.read';

        return self::hasScope($connection, $scope);
    }

    public static function canCreateContent(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.create' : 'posts.create';

        return self::hasScope($connection, $scope);
    }

    public static function canUpdateContent(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.update' : 'posts.update';

        return self::hasScope($connection, $scope);
    }

    public static function canPublishContent(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.publish' : 'posts.publish';

        return self::hasScope($connection, $scope);
    }

    public static function canDeleteContent(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.delete' : 'posts.delete';

        return self::hasScope($connection, $scope);
    }

    public static function canReadTerms(Connection $connection): bool
    {
        return self::hasScope($connection, 'terms.read');
    }

    public static function canReadTemplates(Connection $connection, string $type): bool
    {
        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.templates.read' : 'posts.templates.read';

        return self::hasScope($connection, $scope);
    }

    public static function canUploadMedia(Connection $connection): bool
    {
        return self::hasScope($connection, 'media.upload');
    }

    public static function canReadMedia(Connection $connection): bool
    {
        return self::hasScope($connection, 'media.read');
    }

    public static function mapToCapability(string $scope): ?string
    {
        return match ($scope) {
            'site.read', 'posts.read', 'pages.read', 'terms.read' => 'read',
            'posts.templates.read', 'pages.templates.read' => 'read',
            'seo.robots.update' => 'manage_options',
            'site.health.read' => 'view_site_health_checks',
            'site.maintenance', 'settings.read', 'settings.update' => 'manage_options',
            'plugins.read', 'plugins.activate', 'plugins.deactivate' => 'activate_plugins',
            'plugins.install' => 'install_plugins',
            'plugins.update' => 'update_plugins',
            'plugins.delete' => 'delete_plugins',
            'themes.read', 'themes.activate' => 'switch_themes',
            'themes.install' => 'install_themes',
            'themes.update' => 'update_themes',
            'themes.delete' => 'delete_themes',
            'appearance.read', 'appearance.update' => 'edit_theme_options',
            'posts.revisions.read', 'pages.revisions.read' => 'read',
            'posts.revisions.restore' => 'edit_posts',
            'pages.revisions.restore' => 'edit_pages',
            'redirects.read', 'redirects.update' => 'manage_options',
            'users.read' => 'list_users',
            'users.create' => 'create_users',
            'users.update' => 'edit_users',
            'users.assign_roles' => 'promote_users',
            'users.delete' => 'delete_users',
            'posts.create' => 'edit_posts',
            'posts.update' => 'edit_posts',
            'posts.publish' => 'publish_posts',
            'posts.delete' => 'delete_posts',
            'pages.create' => 'edit_pages',
            'pages.update' => 'edit_pages',
            'pages.publish' => 'publish_pages',
            'pages.delete' => 'delete_pages',
            'comments.read', 'comments.moderate' => 'moderate_comments',
            'media.upload' => 'upload_files',
            'media.embed' => 'upload_files',
            'media.update' => 'upload_files',
            'media.delete' => 'delete_posts',
            'updates.read' => 'update_core',
            'core.update' => 'update_core',
            'media.read' => null,
            default => null,
        };
    }

    public static function userCan(Connection $connection, string $scope): bool
    {
        if (! self::hasScope($connection, $scope)) {
            return false;
        }

        if ($scope === 'media.read') {
            return user_can($connection->userId, 'upload_files')
                || user_can($connection->userId, 'read');
        }

        $capability = self::mapToCapability($scope);

        if ($capability === null) {
            return false;
        }

        return user_can($connection->userId, $capability);
    }
}
