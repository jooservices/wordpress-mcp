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
        'posts.read',
        'posts.create',
        'posts.update',
        'posts.publish',
        'posts.delete',
        'pages.read',
        'pages.create',
        'pages.update',
        'pages.publish',
        'pages.delete',
        'comments.read',
        'comments.moderate',
        'terms.read',
        'media.read',
        'media.upload',
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
