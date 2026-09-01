<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

final class UploadDirectory
{
    public static function basedir(): ?string
    {
        $uploadDir = wp_upload_dir();

        if (! is_array($uploadDir) || ! empty($uploadDir['error']) || empty($uploadDir['basedir'])) {
            return null;
        }

        return rtrim((string) $uploadDir['basedir'], '/');
    }

    public static function baseurl(): ?string
    {
        $uploadDir = wp_upload_dir();

        if (! is_array($uploadDir) || ! empty($uploadDir['error']) || empty($uploadDir['baseurl'])) {
            return null;
        }

        return rtrim((string) $uploadDir['baseurl'], '/');
    }
}
