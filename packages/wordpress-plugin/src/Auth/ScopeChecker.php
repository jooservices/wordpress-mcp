<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Auth;

use JOOservices\WordPressMcp\Models\Connection;

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
        'media.read',
        'media.upload',
    ];

    public static function hasScope(Connection $connection, string $scope): bool
    {
        return in_array($scope, $connection->scopes, true);
    }

    public static function canReadContent(Connection $connection, string $type): bool
    {
        $scope = $type === 'page' ? 'pages.read' : 'posts.read';

        return self::hasScope($connection, $scope);
    }

    public static function canCreateContent(Connection $connection, string $type): bool
    {
        $scope = $type === 'page' ? 'pages.create' : 'posts.create';

        return self::hasScope($connection, $scope);
    }

    public static function canUpdateContent(Connection $connection, string $type): bool
    {
        $scope = $type === 'page' ? 'pages.update' : 'posts.update';

        return self::hasScope($connection, $scope);
    }

    public static function canPublishContent(Connection $connection, string $type): bool
    {
        $scope = $type === 'page' ? 'pages.publish' : 'posts.publish';

        return self::hasScope($connection, $scope);
    }

    public static function canDeleteContent(Connection $connection, string $type): bool
    {
        $scope = $type === 'page' ? 'pages.delete' : 'posts.delete';

        return self::hasScope($connection, $scope);
    }

    public static function canUploadMedia(Connection $connection): bool
    {
        return self::hasScope($connection, 'media.upload');
    }

    public static function mapToCapability(string $scope): ?string
    {
        return match ($scope) {
            'site.read' => 'read',
            'posts.read', 'pages.read' => 'read',
            'posts.create', 'pages.create' => 'edit_posts',
            'posts.update', 'pages.update' => 'edit_posts',
            'posts.publish', 'pages.publish' => 'publish_posts',
            'posts.delete', 'pages.delete' => 'delete_posts',
            'comments.read' => 'moderate_comments',
            'comments.moderate' => 'moderate_comments',
            'media.read', 'media.upload' => 'upload_files',
            default => null,
        };
    }

    public static function userCan(Connection $connection, string $scope): bool
    {
        if (! self::hasScope($connection, $scope)) {
            return false;
        }

        $capability = self::mapToCapability($scope);

        if ($capability === null) {
            return false;
        }

        return user_can($connection->userId, $capability);
    }
}
