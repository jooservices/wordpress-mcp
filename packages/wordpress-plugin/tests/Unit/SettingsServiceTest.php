<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\SettingsService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    private SettingsService $service;

    protected function setUp(): void
    {
        $GLOBALS['wp_test_options'] = [
            'blogname' => 'Original site',
            'default_comment_status' => 'open',
        ];
        $GLOBALS['wp_test_option_updates'] = [];
        $this->service = new SettingsService();
    }

    #[Test]
    public function it_validates_every_setting_before_writing_any_option(): void
    {
        $result = $this->service->update([
            'blogname' => 'Changed site',
            'default_comment_status' => 'moderated',
        ]);

        self::assertNull($result['settings']);
        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame('Original site', $GLOBALS['wp_test_options']['blogname']);
        self::assertSame('open', $GLOBALS['wp_test_options']['default_comment_status']);
        self::assertSame([], $GLOBALS['wp_test_option_updates']);
    }

    #[Test]
    public function it_writes_all_validated_settings_after_validation_completes(): void
    {
        $result = $this->service->update([
            'blogname' => 'Changed site',
            'posts_per_page' => '25',
        ]);

        self::assertNull($result['error']);
        self::assertSame('Changed site', $GLOBALS['wp_test_options']['blogname']);
        self::assertSame(25, $GLOBALS['wp_test_options']['posts_per_page']);
        self::assertSame([
            ['blogname', 'Changed site'],
            ['posts_per_page', 25],
        ], $GLOBALS['wp_test_option_updates']);
    }
}
