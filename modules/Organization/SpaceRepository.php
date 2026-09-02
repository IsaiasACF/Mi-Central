<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class SpaceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, name, slug
             FROM organization_spaces
             WHERE user_id = :user_id
             ORDER BY FIELD(slug, 'universidad', 'amigos', 'personal', 'trabajo'), name"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }
}
