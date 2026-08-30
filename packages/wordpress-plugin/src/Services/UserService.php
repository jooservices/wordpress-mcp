<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Services;

use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_User;
use WP_User_Query;

final class UserService
{
    /**
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, int|string|list<string>>>, pagination: array<string, int>}
     */
    public function list(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 20)));
        $query = new WP_User_Query([
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'search' => isset($params['q']) ? '*' . sanitize_text_field((string) $params['q']) . '*' : '',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
        ]);
        $items = array_map($this->normalize(...), $query->get_results());
        $total = $query->get_total();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: array<string, int|string|list<string>>|null, error: string|null}
     */
    public function create(array $data): array
    {
        $login = sanitize_user((string) ($data['login'] ?? ''), true);
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($login === '' || ! is_email($email) || strlen($password) < 12) {
            return ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $role = sanitize_key((string) ($data['role'] ?? 'subscriber'));

        if (! isset(wp_roles()->roles[$role])) {
            return ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => sanitize_text_field((string) ($data['display_name'] ?? $login)),
            'role' => $role,
        ]);

        if (is_wp_error($userId)) {
            return ['user' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        return $this->get((int) $userId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: array<string, int|string|list<string>>|null, error: string|null}
     */
    public function update(int $id, array $data): array
    {
        if (! get_userdata($id) instanceof WP_User) {
            return ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        $update = ['ID' => $id];

        foreach (['display_name', 'user_email', 'user_url', 'description', 'first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $field === 'user_email'
                    ? sanitize_email((string) $data[$field])
                    : sanitize_text_field((string) $data[$field]);
            }
        }

        if (isset($data['password'])) {
            $password = (string) $data['password'];

            if (strlen($password) < 12) {
                return ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }

            $update['user_pass'] = $password;
        }

        if (wp_update_user($update) instanceof \WP_Error) {
            return ['user' => null, 'error' => ErrorCodes::WORDPRESS_ERROR];
        }

        if (isset($data['role'])) {
            $role = sanitize_key((string) $data['role']);

            if (! isset(wp_roles()->roles[$role])) {
                return ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
            }

            $user = new WP_User($id);
            $user->set_role($role);
        }

        return $this->get($id);
    }

    /**
     * @return array{deleted: bool, error: string|null}
     */
    public function delete(int $id, ?int $reassign): array
    {
        if ($id === get_current_user_id() || ! get_userdata($id) instanceof WP_User) {
            return ['deleted' => false, 'error' => ErrorCodes::INVALID_ARGUMENT];
        }

        /** @phpstan-ignore-next-line WP's runtime install provides this admin-only file. */
        require_once ABSPATH . 'wp-admin/includes/user.php';

        return wp_delete_user($id, $reassign) !== false
            ? ['deleted' => true, 'error' => null]
            : ['deleted' => false, 'error' => ErrorCodes::WORDPRESS_ERROR];
    }

    /**
     * @return array{user: array<string, int|string|list<string>>|null, error: string|null}
     */
    private function get(int $id): array
    {
        $user = get_userdata($id);

        return $user instanceof WP_User
            ? ['user' => $this->normalize($user), 'error' => null]
            : ['user' => null, 'error' => ErrorCodes::INVALID_ARGUMENT];
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    private function normalize(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
            'registered_at' => $user->user_registered,
        ];
    }
}
