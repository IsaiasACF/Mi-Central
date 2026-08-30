<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;

final class ExpensePaymentMethodRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO expense_payment_methods
                (user_id, name, normalized_name, type, institution_name, notes, active)
             VALUES
                (:user_id, :name, :normalized_name, :type, :institution_name, :notes, :active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $data['name'],
            'normalized_name' => $data['normalized_name'],
            'type' => $data['type'],
            'institution_name' => $data['institution_name'],
            'notes' => $data['notes'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $paymentMethodId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, type, institution_name, notes, active, created_at, updated_at
             FROM expense_payment_methods
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $paymentMethodId,
            'user_id' => $userId,
        ]);

        $method = $statement->fetch();

        return is_array($method) ? $method : null;
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

        $statement = $this->pdo->prepare('SELECT 1 FROM expense_payment_methods WHERE ' . $where . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        $where = 'user_id = :user_id';

        if ($activeOnly) {
            $where .= ' AND active = 1';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, type, institution_name, notes, active, created_at, updated_at
             FROM expense_payment_methods
             WHERE ' . $where . '
             ORDER BY active DESC, name ASC, id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $paymentMethodId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $paymentMethodId,
            'user_id' => $userId,
        ];

        foreach (['name', 'normalized_name', 'type', 'institution_name', 'notes', 'active'] as $field) {
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
            'UPDATE expense_payment_methods
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }
}
