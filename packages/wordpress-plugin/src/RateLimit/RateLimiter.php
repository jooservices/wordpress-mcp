<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\RateLimit;

final class RateLimiter
{
    private const DEFAULT_LIMIT = 120;
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $windowSeconds = self::WINDOW_SECONDS,
    ) {
    }

    public function isAllowed(int $connectionId): bool
    {
        $key = 'chatgpt_rl_' . $connectionId;
        $stored = get_transient($key);

        if (! is_array($stored) || ! isset($stored['count'], $stored['expires'])) {
            $this->resetWindow($key);

            return true;
        }

        if (time() >= (int) $stored['expires']) {
            $this->resetWindow($key);

            return true;
        }

        if ((int) $stored['count'] >= $this->limit) {
            return false;
        }

        $remaining = max(1, (int) $stored['expires'] - time());
        set_transient($key, [
            'count' => (int) $stored['count'] + 1,
            'expires' => (int) $stored['expires'],
        ], $remaining);

        return true;
    }

    private function resetWindow(string $key): void
    {
        set_transient($key, [
            'count' => 1,
            'expires' => time() + $this->windowSeconds,
        ], $this->windowSeconds);
    }
}
