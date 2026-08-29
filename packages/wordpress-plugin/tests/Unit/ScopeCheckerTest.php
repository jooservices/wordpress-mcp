<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeCheckerTest extends TestCase
{
    private function connection(array $scopes): Connection
    {
        return new Connection(1, 'Test', 'hash', 1, $scopes, true, '2026-01-01', null);
    }

    #[Test]
    public function it_maps_capabilities(): void
    {
        self::assertSame('edit_posts', ScopeChecker::mapToCapability('posts.create'));
        self::assertSame('publish_posts', ScopeChecker::mapToCapability('posts.publish'));
        self::assertNull(ScopeChecker::mapToCapability('unknown.scope'));
    }

    #[Test]
    public function it_checks_delete_and_upload_scopes(): void
    {
        $connection = $this->connection(['posts.delete', 'media.upload']);

        self::assertTrue(ScopeChecker::canDeleteContent($connection, 'post'));
        self::assertFalse(ScopeChecker::canDeleteContent($connection, 'page'));
        self::assertTrue(ScopeChecker::canUploadMedia($connection));
        self::assertSame('delete_posts', ScopeChecker::mapToCapability('posts.delete'));
        self::assertSame('upload_files', ScopeChecker::mapToCapability('media.upload'));
    }

    #[Test]
    public function it_checks_publish_and_update_scopes(): void
    {
        $connection = $this->connection(['pages.read', 'pages.update', 'pages.publish']);

        self::assertTrue(ScopeChecker::canUpdateContent($connection, 'page'));
        self::assertTrue(ScopeChecker::canPublishContent($connection, 'page'));
        self::assertFalse(ScopeChecker::canCreateContent($connection, 'post'));
    }
}
