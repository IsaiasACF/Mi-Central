<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;

final class ExpenseCategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $name, string $normalizedName, ?string $color, int $active): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO expense_categories (user_id, name, normalized_name, color, active)
             VALUES (:user_id, :name, :normalized_name, :color, :active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'color' => $color,
            'active' => $active,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $categoryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, color, active, created_at, updated_at
             FROM expense_categories
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $categoryId,
            'user_id' => $userId,
        ]);

        $category = $statement->fetch();

        return is_array($category) ? $category : null;
    }

    public function normalizedNameExists(int $userId, string $normalizedName, ?int $exceptId = null): bool
    {
        $where = 'user_id = :user_id AND normalized_name = :normalized_name';
        $params = [
            'user_id' => $userId,
            'normalized_name' => $normalizedName,
        ];

        if ($exceptId !== null) {
            $where .= ' AND id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        $statement = $this->pdo->prepare('SELECT 1 FROM expense_categories WHERE ' . $where . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        $where = 'user_id = :user_id';
        $params = ['user_id' => $userId];

        if ($activeOnly) {
            $where .= ' AND active = 1';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, color, active, created_at, updated_at
             FROM expense_categories
             WHERE ' . $where . '
             ORDER BY active DESC, name ASC, id ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $categoryId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $categoryId,
            'user_id' => $userId,
        ];

        foreach (['name', 'normalized_name', 'color', 'active'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "{$field} = :{$field}";
            $params[$field] = $data[$field];
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE expense_categories
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }
}
