<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Live;

final class PluginRestClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $url = rtrim((string) getenv('WORDPRESS_URL'), '/');
        $token = (string) getenv('WORDPRESS_CONNECTION_TOKEN');

        if ($url === '' || $token === '') {
            throw new \RuntimeException('WORDPRESS_URL and WORDPRESS_CONNECTION_TOKEN are required.');
        }

        return new self($url . '/wp-json/chatgpt-connector/v1', $token);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{status: int, body: mixed}
     */
    public function request(string $method, string $path, ?array $json = null, bool $auth = true): array
    {
        $headers = [
            'Accept: application/json',
        ];

        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $body = null;

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $encoded = json_encode($json, JSON_THROW_ON_ERROR);
            $body = $encoded;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 60,
                'follow_location' => 0,
            ],
        ]);

        $url = $this->baseUrl . $path;
        $raw = @file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        $decoded = null;

        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = $raw;
            }
        }

        return ['status' => $status, 'body' => $decoded];
    }
}
