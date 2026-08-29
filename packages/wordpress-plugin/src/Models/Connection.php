<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Models;

final readonly class Connection
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $tokenHash,
        public int $userId,
        public array $scopes,
        public bool $active,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        if (! is_array($scopes)) {
            $scopes = [];
        }

        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            tokenHash: (string) $row['token_hash'],
            userId: (int) $row['user_id'],
            scopes: array_values(array_map(strval(...), $scopes)),
            active: (bool) $row['active'],
            createdAt: (string) $row['created_at'],
            lastUsedAt: isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
        );
    }
}
