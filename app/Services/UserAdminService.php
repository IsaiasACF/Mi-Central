<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class UserAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, username, approval_status, is_active, is_admin, created_at, last_login_at, approved_at, rejected_at
             FROM users
             ORDER BY username ASC"
        );

        return $statement->fetchAll();
    }

    public function deactivate(int $targetUserId, int $adminUserId): bool
    {
        if ($targetUserId === $adminUserId) {
            throw new RuntimeException('No puedes desactivar tu propia cuenta.');
        }

        $statement = $this->pdo->prepare(
            "UPDATE users
             SET is_active = 0
             WHERE id = :id AND is_active = 1"
        );
        $statement->execute(['id' => $targetUserId]);

        return $statement->rowCount() === 1;
    }

    public function reactivate(int $targetUserId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE users
             SET approval_status = 'approved',
                 is_active = 1,
                 approved_at = COALESCE(approved_at, NOW()),
                 rejected_at = NULL
             WHERE id = :id AND is_active = 0"
        );
        $statement->execute(['id' => $targetUserId]);

        return $statement->rowCount() === 1;
    }
}
