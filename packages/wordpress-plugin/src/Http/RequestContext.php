<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Http;

use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\RateLimit\RateLimiter;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Error;
use WP_REST_Request;

final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = wp_generate_uuid4();
        }

        return self::$requestId;
    }

    public static function reset(): void
    {
        self::$requestId = null;
        ConnectionAuthenticator::reset();
    }

    public static function requireConnection(WP_REST_Request $request): Connection|WP_Error
    {
        $header = $request->get_header('authorization');
        $connection = ConnectionAuthenticator::authenticateFromRequest($header);

        if ($connection === null) {
            $err = ErrorCodes::error(
                ErrorCodes::AUTHENTICATION_FAILED,
                'Invalid or missing bearer token.',
                401,
            );

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $limiter = new RateLimiter();

        if (! $limiter->isAllowed($connection->id)) {
            $err = ErrorCodes::error(
                ErrorCodes::RATE_LIMITED,
                'Rate limit exceeded. Try again later.',
                429,
            );

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return $connection;
    }

    public static function deny(string $message = 'Permission denied.'): WP_Error
    {
        $err = ErrorCodes::error(ErrorCodes::PERMISSION_DENIED, $message, 403);

        return new WP_Error($err['code'], $err['message'], $err['data']);
    }

    public static function invalid(string $message): WP_Error
    {
        $err = ErrorCodes::error(ErrorCodes::INVALID_ARGUMENT, $message, 400);

        return new WP_Error($err['code'], $err['message'], $err['data']);
    }
}
