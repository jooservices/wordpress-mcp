<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Http;

use JOOservices\WordPressMcp\Audit\AuditLogger;
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

    /**
     * Resets per-request state. When called with the incoming request, an
     * `X-Request-Id` header set by the MCP server is adopted as this
     * request's id, so the same id shows up in the MCP server's
     * observability event and this site's audit log for the same call.
     * Falls back to a freshly generated id when the header is absent or
     * malformed.
     */
    public static function reset(?WP_REST_Request $request = null): void
    {
        self::$requestId = self::extractIncomingRequestId($request);
        ConnectionAuthenticator::reset();
    }

    private static function extractIncomingRequestId(?WP_REST_Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $header = $request->get_header('x-request-id');

        if ($header === null || $header === '') {
            return null;
        }

        $header = sanitize_text_field($header);

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $header) === 1
            ? $header
            : null;
    }

    public static function requireConnection(WP_REST_Request $request): Connection|WP_Error
    {
        $header = $request->get_header('authorization');
        $connection = ConnectionAuthenticator::authenticateFromRequest($header);
        $route = $request->get_route();

        if ($connection === null) {
            (new AuditLogger())->log(null, self::requestId(), 'denied', 'auth', $route, false, [
                'reason' => 'authentication_failed',
            ]);

            $err = ErrorCodes::error(
                ErrorCodes::AUTHENTICATION_FAILED,
                'Invalid or missing bearer token.',
                401,
            );

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $limiter = new RateLimiter();

        if (! $limiter->isAllowed($connection->id)) {
            (new AuditLogger())->log($connection->id, self::requestId(), 'denied', 'auth', $route, false, [
                'reason' => 'rate_limited',
            ]);

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
