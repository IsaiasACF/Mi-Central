<?php
declare(strict_types=1);

namespace App\Http;

use PDO;
use SessionHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'sessions',
        private readonly int $ttl = 7200,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT data FROM %s WHERE id = :id AND expires_at > :expires_at LIMIT 1',
                $this->quoteIdentifier($this->table),
            )
        );

        $statement->execute([
            'id' => $sessionId,
            'expires_at' => date('Y-m-d H:i:s.u', time()),
        ]);

        $data = $statement->fetchColumn();

        return is_string($data) ? $data : '';
    }

    public function write(string $sessionId, string $data): bool
    {
        $expiresAt = date('Y-m-d H:i:s.u', time() + $this->ttl);
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %s (id, data, expires_at, created_at, updated_at)
                 VALUES (:id, :data, :expires_at, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    expires_at = VALUES(expires_at),
                    updated_at = CURRENT_TIMESTAMP(6)',
                $this->quoteIdentifier($this->table),
            )
        );

        return $statement->execute([
            'id' => $sessionId,
            'data' => $data,
            'expires_at' => $expiresAt,
        ]) !== false;
    }

    public function destroy(string $sessionId): bool
    {
        $statement = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE id = :id', $this->quoteIdentifier($this->table))
        );

        return $statement->execute(['id' => $sessionId]) !== false;
    }

    public function gc(int $maxLifetime): int|false
    {
        $statement = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE expires_at <= :expires_at', $this->quoteIdentifier($this->table))
        );

        $statement->execute([
            'expires_at' => date('Y-m-d H:i:s.u', time()),
        ]);

        return $statement->rowCount();
    }

    public function cleanupExpired(): int
    {
        $statement = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE expires_at <= :expires_at', $this->quoteIdentifier($this->table))
        );

        $statement->execute([
            'expires_at' => date('Y-m-d H:i:s.u', time()),
        ]);

        return $statement->rowCount();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
