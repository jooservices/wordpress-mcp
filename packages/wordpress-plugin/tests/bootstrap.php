<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
    }
}

if (! function_exists('get_transient')) {
    /** @var array<string, mixed> $GLOBALS['wp_test_transients'] */
    $GLOBALS['wp_test_transients'] = [];

    function get_transient(string $transient): mixed
    {
        return $GLOBALS['wp_test_transients'][$transient] ?? false;
    }

    function set_transient(string $transient, mixed $value, int $expiration): bool
    {
        $GLOBALS['wp_test_transients'][$transient] = $value;

        return true;
    }

    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['wp_test_transients'][$transient]);

        return true;
    }
}
