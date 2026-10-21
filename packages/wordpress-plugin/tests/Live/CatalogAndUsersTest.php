<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class CatalogAndUsersTest extends LiveTestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            $this->api('DELETE', '/users/' . $id);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_lists_terms_comments_plugins_and_themes(): void
    {
        $terms = $this->api('GET', '/terms?taxonomy=category');
        self::assertSame(200, $terms['status']);

        $comments = $this->api('GET', '/comments?status=hold&per_page=5');
        self::assertSame(200, $comments['status']);
        $items = $comments['body']['items'] ?? [];

        if (is_array($items) && $items !== []) {
            $commentId = (int) ($items[0]['id'] ?? 0);

            if ($commentId > 0) {
                $got = $this->api('GET', '/comments/' . $commentId);
                self::assertSame(200, $got['status']);
                $moderated = $this->api('PATCH', '/comments/' . $commentId, ['action' => 'approve']);
                self::assertContains($moderated['status'], [200, 400]);
            }
        }

        $plugins = $this->api('GET', '/plugins');
        self::assertSame(200, $plugins['status']);
        self::assertIsArray($plugins['body']['items'] ?? null);

        $missing = $this->api('POST', '/plugins/state', [
            'plugin' => 'missing/missing.php',
            'enabled' => true,
        ]);
        self::assertSame(400, $missing['status']);

        $themes = $this->api('GET', '/themes');
        self::assertSame(200, $themes['status']);
    }

    #[Test]
    public function it_creates_updates_and_deletes_a_subscriber(): void
    {
        $login = 'e2e_' . $this->faker->unique()->numerify('user####');
        $email = $login . '@example.com';
        $password = 'E2e!' . $this->faker->bothify('??##??##');

        $created = $this->api('POST', '/users', [
            'login' => $login,
            'email' => $email,
            'password' => $password,
            'role' => 'subscriber',
        ]);
        self::assertContains($created['status'], [200, 201], (string) json_encode($created['body']));
        $id = (int) $created['body']['id'];
        $this->userIds[] = $id;

        $updated = $this->api('PATCH', '/users/' . $id, [
            'display_name' => 'E2E ' . $login,
        ]);
        self::assertSame(200, $updated['status']);

        $listed = $this->api('GET', '/users?per_page=5');
        self::assertSame(200, $listed['status']);

        $deleted = $this->api('DELETE', '/users/' . $id);
        self::assertSame(200, $deleted['status']);
        $this->userIds = array_values(array_filter($this->userIds, static fn(int $kept): bool => $kept !== $id));
    }

    #[Test]
    public function it_refuses_to_change_the_connection_users_own_role(): void
    {
        $self = $this->api('PATCH', '/users/1', ['role' => 'subscriber']);
        self::assertSame(403, $self['status'], (string) json_encode($self['body']));

        $still = $this->api('GET', '/users?per_page=50');
        $admin = array_values(array_filter(
            $still['body']['items'] ?? [],
            static fn(array $user): bool => (int) $user['id'] === 1,
        ));
        self::assertContains('administrator', $admin[0]['roles'] ?? []);
    }

    #[Test]
    public function it_applies_no_setting_when_one_value_is_invalid_and_accepts_unchanged_values(): void
    {
        $before = $this->api('GET', '/settings');
        self::assertSame(200, $before['status']);
        $description = (string) $before['body']['blogdescription'];

        $rejected = $this->api('PATCH', '/settings', [
            'blogdescription' => 'Should not be saved ' . $this->faker->numerify('####'),
            'default_comment_status' => 'sometimes',
        ]);
        self::assertSame(400, $rejected['status']);

        $after = $this->api('GET', '/settings');
        self::assertSame($description, $after['body']['blogdescription']);

        $unchanged = $this->api('PATCH', '/settings', [
            'blogdescription' => $description,
            'blog_public' => (bool) $before['body']['blog_public'],
            'posts_per_page' => (int) $before['body']['posts_per_page'],
        ]);
        self::assertSame(200, $unchanged['status'], (string) json_encode($unchanged['body']));
    }
}
