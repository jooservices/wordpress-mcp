<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Services\SiteService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiteServiceTest extends TestCase
{
    #[Test]
    public function it_reports_php_upload_limits(): void
    {
        $GLOBALS['wp_test_ini'] = [
            'upload_max_filesize' => '8M',
            'post_max_size' => '16M',
            'memory_limit' => '256M',
            'max_execution_time' => '120',
        ];
        $GLOBALS['wp_test_max_upload_size'] = 8388608;

        $limits = (new SiteService())->limits();

        self::assertArrayHasKey('upload_max_filesize', $limits);
        self::assertArrayHasKey('wp_max_upload_size_bytes', $limits);
        self::assertSame(8388608, $limits['wp_max_upload_size_bytes']);
    }

    #[Test]
    public function it_includes_settings_when_connection_has_scope(): void
    {
        $GLOBALS['wp_test_options'] = [
            'blogname' => 'Demo Blog',
            'blogdescription' => 'Tagline',
            'timezone_string' => 'UTC',
            'date_format' => 'F j, Y',
            'time_format' => 'g:i a',
            'start_of_week' => 1,
            'posts_per_page' => 10,
            'blog_public' => 1,
            'default_comment_status' => 'open',
            'default_ping_status' => 'open',
            'permalink_structure' => '/%postname%/',
        ];
        $GLOBALS['wp_test_plugins'] = [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.0'],
        ];
        $GLOBALS['wp_test_active_plugins'] = ['akismet/akismet.php'];
        $GLOBALS['wp_test_theme'] = [
            'stylesheet' => 'twentytwentyfive',
            'Name' => 'Twenty Twenty-Five',
            'Version' => '1.0',
        ];

        $connection = new Connection(1, 'Test', 'hash', 1, ['site.read', 'settings.read'], true, '2026-01-01', null);
        $site = (new SiteService())->get($connection);

        self::assertSame('Demo Blog', $site['settings']['blogname']);
        self::assertSame(1, $site['active_plugins_count']);
        self::assertSame('twentytwentyfive', $site['active_theme']['stylesheet']);
        self::assertArrayHasKey('limits', $site);
    }

    #[Test]
    public function it_omits_settings_without_scope(): void
    {
        $connection = new Connection(1, 'Test', 'hash', 1, ['site.read'], true, '2026-01-01', null);
        $site = (new SiteService())->get($connection);

        self::assertNull($site['settings']);
    }
}
