<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\UserService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_User;

final class UserServiceTest extends TestCase
{
    private UserService $service;

    protected function setUp(): void
    {
        $GLOBALS['wp_test_users'] = [];
        $GLOBALS['wp_test_roles'] = [
            'subscriber' => ['name' => 'Subscriber'],
            'editor' => ['name' => 'Editor'],
            'administrator' => ['name' => 'Administrator'],
        ];
        $GLOBALS['wp_test_editable_roles_by_user'] = [
            7 => [
                'subscriber' => ['name' => 'Subscriber'],
                'editor' => ['name' => 'Editor'],
            ],
            8 => ['subscriber' => ['name' => 'Subscriber']],
        ];
        $GLOBALS['wp_test_current_user_id'] = 7;
        $GLOBALS['wp_test_insert_user_calls'] = 0;
        $GLOBALS['wp_test_update_user_calls'] = 0;
        $GLOBALS['wp_test_next_user_id'] = 1000;
        $this->service = new UserService();
    }

    #[Test]
    public function it_rejects_roles_the_acting_user_cannot_assign_when_creating_users(): void
    {
        $result = $this->service->create([
            'login' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'LongPassword123!',
            'role' => 'administrator',
        ]);

        self::assertNull($result['user']);
        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame(0, $GLOBALS['wp_test_insert_user_calls']);
    }

    #[Test]
    public function it_allows_an_editable_role_when_creating_users(): void
    {
        $result = $this->service->create([
            'login' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'LongPassword123!',
            'role' => 'editor',
        ]);

        self::assertNull($result['error']);
        self::assertSame(['editor'], $result['user']['roles']);
        self::assertSame(1, $GLOBALS['wp_test_insert_user_calls']);
    }

    #[Test]
    public function it_checks_the_acting_users_editable_roles_before_updating_any_user_data(): void
    {
        $user = new WP_User(50);
        $user->user_login = 'existing';
        $user->user_email = 'existing@example.com';
        $user->display_name = 'Existing';
        $user->user_registered = '2025-01-01 00:00:00';
        $user->roles = ['subscriber'];
        $GLOBALS['wp_test_users'][50] = $user;
        $GLOBALS['wp_test_current_user_id'] = 8;

        $result = $this->service->update(50, [
            'display_name' => 'Changed',
            'role' => 'editor',
        ]);

        self::assertNull($result['user']);
        self::assertSame(ErrorCodes::INVALID_ARGUMENT, $result['error']);
        self::assertSame(0, $GLOBALS['wp_test_update_user_calls']);
        self::assertSame('Existing', $GLOBALS['wp_test_users'][50]->display_name);
        self::assertSame(['subscriber'], $GLOBALS['wp_test_users'][50]->roles);
    }

    #[Test]
    public function it_applies_an_editable_role_when_updating_users(): void
    {
        $user = new WP_User(51);
        $user->user_login = 'existing';
        $user->user_email = 'existing@example.com';
        $user->display_name = 'Existing';
        $user->user_registered = '2025-01-01 00:00:00';
        $user->roles = ['subscriber'];
        $GLOBALS['wp_test_users'][51] = $user;

        $result = $this->service->update(51, ['role' => 'editor']);

        self::assertNull($result['error']);
        self::assertSame(['editor'], $result['user']['roles']);
    }
}
