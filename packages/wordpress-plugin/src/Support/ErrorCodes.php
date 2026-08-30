<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class ErrorCodes
{
    public const AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const POST_NOT_FOUND = 'POST_NOT_FOUND';
    public const COMMENT_NOT_FOUND = 'COMMENT_NOT_FOUND';
    public const MEDIA_NOT_FOUND = 'MEDIA_NOT_FOUND';
    public const INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    public const MEDIA_UPLOAD_LIMIT_EXCEEDED = 'MEDIA_UPLOAD_LIMIT_EXCEEDED';
    public const MEDIA_VERIFY_FAILED = 'MEDIA_VERIFY_FAILED';
    public const WORDPRESS_ERROR = 'WORDPRESS_ERROR';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';

    /**
     * @param array<string, mixed> $data
     * @return array{code: string, message: string, data: array<string, mixed>, status: int}
     */
    public static function error(string $code, string $message, int $status = 400, array $data = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => array_merge(['status' => $status], $data),
            'status' => $status,
        ];
    }
}
