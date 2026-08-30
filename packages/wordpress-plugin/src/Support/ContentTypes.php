<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class ContentTypes
{
    public const POST = 'post';

    public const PAGE = 'page';

    /** @var list<string> */
    public const SUPPORTED = [self::POST, self::PAGE];

    public static function isSupported(string $type): bool
    {
        return in_array(\sanitize_key($type), self::SUPPORTED, true);
    }

    public static function normalize(string $type, string $default = self::POST): ?string
    {
        $normalized = \sanitize_key($type);

        if ($normalized === '') {
            return self::isSupported($default) ? $default : null;
        }

        return self::isSupported($normalized) ? $normalized : null;
    }
}
