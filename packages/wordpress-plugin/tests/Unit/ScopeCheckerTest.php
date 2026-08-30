<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Support\ContentTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeCheckerTest extends TestCase
{
    private function connection(array $scopes): Connection
    {
        return new Connection(1, 'Test', 'hash', 1, $scopes, true, '2026-01-01', null);
    }

    #[Test]
    public function it_maps_post_capabilities(): void
    {
        self::assertSame('edit_posts', ScopeChecker::mapToCapability('posts.create'));
        self::assertSame('edit_posts', ScopeChecker::mapToCapability('posts.update'));
        self::assertSame('publish_posts', ScopeChecker::mapToCapability('posts.publish'));
        self::assertSame('delete_posts', ScopeChecker::mapToCapability('posts.delete'));
        self::assertSame('read', ScopeChecker::mapToCapability('posts.read'));
    }

    #[Test]
    public function it_maps_page_capabilities(): void
    {
        self::assertSame('edit_pages', ScopeChecker::mapToCapability('pages.create'));
        self::assertSame('edit_pages', ScopeChecker::mapToCapability('pages.update'));
        self::assertSame('publish_pages', ScopeChecker::mapToCapability('pages.publish'));
        self::assertSame('delete_pages', ScopeChecker::mapToCapability('pages.delete'));
        self::assertSame('read', ScopeChecker::mapToCapability('pages.read'));
    }

    #[Test]
    public function it_maps_other_capabilities(): void
    {
        self::assertSame('read', ScopeChecker::mapToCapability('site.read'));
        self::assertSame('read', ScopeChecker::mapToCapability('terms.read'));
        self::assertSame('moderate_comments', ScopeChecker::mapToCapability('comments.read'));
        self::assertSame('moderate_comments', ScopeChecker::mapToCapability('comments.moderate'));
        self::assertSame('upload_files', ScopeChecker::mapToCapability('media.upload'));
        self::assertNull(ScopeChecker::mapToCapability('media.read'));
        self::assertNull(ScopeChecker::mapToCapability('unknown.scope'));
    }

    #[Test]
    public function every_defined_scope_has_a_capability_or_special_case(): void
    {
        foreach (ScopeChecker::ALL_SCOPES as $scope) {
            if ($scope === 'media.read') {
                continue;
            }

            self::assertNotNull(
                ScopeChecker::mapToCapability($scope),
                'Missing capability mapping for ' . $scope,
            );
        }
    }

    #[Test]
    public function it_checks_delete_and_upload_scopes(): void
    {
        $connection = $this->connection(['posts.delete', 'media.upload']);

        self::assertTrue(ScopeChecker::canDeleteContent($connection, ContentTypes::POST));
        self::assertFalse(ScopeChecker::canDeleteContent($connection, ContentTypes::PAGE));
        self::assertFalse(ScopeChecker::canDeleteContent($connection, 'product'));
        self::assertTrue(ScopeChecker::canUploadMedia($connection));
    }

    #[Test]
    public function it_checks_publish_and_update_scopes(): void
    {
        $connection = $this->connection(['pages.read', 'pages.update', 'pages.publish']);

        self::assertTrue(ScopeChecker::canUpdateContent($connection, ContentTypes::PAGE));
        self::assertTrue(ScopeChecker::canPublishContent($connection, ContentTypes::PAGE));
        self::assertFalse(ScopeChecker::canCreateContent($connection, ContentTypes::POST));
        self::assertFalse(ScopeChecker::canReadContent($connection, 'product'));
    }

    #[Test]
    public function it_checks_terms_and_media_read_scopes(): void
    {
        $connection = $this->connection(['terms.read', 'media.read']);

        self::assertTrue(ScopeChecker::canReadTerms($connection));
        self::assertTrue(ScopeChecker::canReadMedia($connection));
        self::assertFalse(ScopeChecker::canUploadMedia($connection));
    }
}
