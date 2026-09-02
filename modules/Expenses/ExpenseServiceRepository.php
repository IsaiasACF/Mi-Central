<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;

final class ExpenseServiceRepository
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
            'INSERT INTO expense_services
                (user_id, category_id, name, normalized_name, default_amount_clp, notes, active)
             VALUES
                (:user_id, :category_id, :name, :normalized_name, :default_amount_clp, :notes, :active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'normalized_name' => $data['normalized_name'],
            'default_amount_clp' => $data['default_amount_clp'],
            'notes' => $data['notes'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $serviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT services.id, services.user_id, services.category_id, categories.name AS category_name,
                    services.name, services.normalized_name, services.default_amount_clp,
                    services.notes, services.active, services.created_at, services.updated_at,
                    rules.id AS recurring_rule_id, rules.frequency AS recurring_frequency,
                    rules.interval_value AS recurring_interval_value, rules.day_of_month AS recurring_day_of_month,
                    rules.default_amount_clp AS recurring_default_amount_clp,
                    rules.default_category_id AS recurring_default_category_id,
                    rules.default_payment_method_id AS recurring_default_payment_method_id,
                    rules.starts_on AS recurring_starts_on, rules.ends_on AS recurring_ends_on,
                    rules.next_generation_on AS recurring_next_generation_on, rules.active AS recurring_active
             FROM expense_services services
             LEFT JOIN expense_categories categories
                ON categories.id = services.category_id
             LEFT JOIN expense_recurring_rules rules
                ON rules.service_id = services.id
             WHERE services.id = :id AND services.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $serviceId,
            'user_id' => $userId,
        ]);

        $service = $statement->fetch();

        return is_array($service) ? $service : null;
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

        $statement = $this->pdo->prepare('SELECT 1 FROM expense_services WHERE ' . $where . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        $where = 'services.user_id = :user_id';

        if ($activeOnly) {
            $where .= ' AND services.active = 1';
        }

        $statement = $this->pdo->prepare(
            'SELECT services.id, services.user_id, services.category_id, categories.name AS category_name,
                    services.name, services.normalized_name, services.default_amount_clp,
                    services.notes, services.active, services.created_at, services.updated_at,
                    rules.id AS recurring_rule_id, rules.frequency AS recurring_frequency,
                    rules.interval_value AS recurring_interval_value, rules.day_of_month AS recurring_day_of_month,
                    rules.default_amount_clp AS recurring_default_amount_clp,
                    rules.default_category_id AS recurring_default_category_id,
                    rules.default_payment_method_id AS recurring_default_payment_method_id,
                    rules.starts_on AS recurring_starts_on, rules.ends_on AS recurring_ends_on,
                    rules.next_generation_on AS recurring_next_generation_on, rules.active AS recurring_active
             FROM expense_services services
             LEFT JOIN expense_categories categories
                ON categories.id = services.category_id
             LEFT JOIN expense_recurring_rules rules
                ON rules.service_id = services.id
             WHERE ' . $where . '
             ORDER BY services.active DESC, services.name ASC, services.id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $serviceId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $serviceId,
            'user_id' => $userId,
        ];

        foreach (['category_id', 'name', 'normalized_name', 'default_amount_clp', 'notes', 'active'] as $field) {
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
            'UPDATE expense_services
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function categoryBelongsToUser(int $userId, int $categoryId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM expense_categories WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $categoryId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function paymentMethodBelongsToUser(int $userId, int $paymentMethodId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM expense_payment_methods WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $paymentMethodId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function paymentMethodSelectableForService(int $userId, int $serviceId, int $paymentMethodId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expense_payment_methods methods
             WHERE methods.id = :payment_method_id
               AND methods.user_id = :user_id
               AND (
                    methods.active = 1
                    OR EXISTS (
                        SELECT 1
                        FROM expense_service_payment_methods pivot
                        INNER JOIN expense_services services
                            ON services.id = pivot.service_id
                        WHERE pivot.payment_method_id = methods.id
                          AND pivot.service_id = :service_id
                          AND services.user_id = :user_id_for_service
                    )
               )
             LIMIT 1'
        );
        $statement->execute([
            'payment_method_id' => $paymentMethodId,
            'user_id' => $userId,
            'service_id' => $serviceId,
            'user_id_for_service' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function addPaymentMethod(int $serviceId, int $paymentMethodId, int $isDefault): void
    {
        if ($isDefault === 1) {
            $statement = $this->pdo->prepare(
                'UPDATE expense_service_payment_methods
                 SET is_default = 0
                 WHERE service_id = :service_id'
            );
            $statement->execute(['service_id' => $serviceId]);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO expense_service_payment_methods (service_id, payment_method_id, is_default)
             VALUES (:service_id, :payment_method_id, :is_default)'
        );
        $statement->execute([
            'service_id' => $serviceId,
            'payment_method_id' => $paymentMethodId,
            'is_default' => $isDefault,
        ]);
    }

    public function setPaymentMethodDefault(int $serviceId, int $paymentMethodId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clear = $this->pdo->prepare(
                'UPDATE expense_service_payment_methods
                 SET is_default = 0
                 WHERE service_id = :service_id'
            );
            $clear->execute(['service_id' => $serviceId]);

            $set = $this->pdo->prepare(
                'UPDATE expense_service_payment_methods
                 SET is_default = 1
                 WHERE service_id = :service_id AND payment_method_id = :payment_method_id'
            );
            $set->execute([
                'service_id' => $serviceId,
                'payment_method_id' => $paymentMethodId,
            ]);

            $changed = $set->rowCount() > 0;
            $this->pdo->commit();

            return $changed;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<int, int> $paymentMethodIds
     */
    public function syncPaymentMethods(int $serviceId, array $paymentMethodIds, ?int $defaultPaymentMethodId): void
    {
        $paymentMethodIds = array_values(array_unique(array_filter(
            $paymentMethodIds,
            static fn (int $id): bool => $id > 0,
        )));

        if ($defaultPaymentMethodId !== null && !in_array($defaultPaymentMethodId, $paymentMethodIds, true)) {
            $defaultPaymentMethodId = null;
        }

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($paymentMethodIds === []) {
                $delete = $this->pdo->prepare(
                    'DELETE FROM expense_service_payment_methods
                     WHERE service_id = :service_id'
                );
                $delete->execute(['service_id' => $serviceId]);
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return;
            }

            $placeholders = implode(', ', array_fill(0, count($paymentMethodIds), '?'));
            $delete = $this->pdo->prepare(
                "DELETE FROM expense_service_payment_methods
                 WHERE service_id = ?
                   AND payment_method_id NOT IN ({$placeholders})"
            );
            $delete->execute(array_merge([$serviceId], $paymentMethodIds));

            $upsert = $this->pdo->prepare(
                'INSERT INTO expense_service_payment_methods (service_id, payment_method_id, is_default)
                 VALUES (:service_id, :payment_method_id, :is_default)
                 ON DUPLICATE KEY UPDATE is_default = VALUES(is_default)'
            );

            foreach ($paymentMethodIds as $paymentMethodId) {
                $upsert->execute([
                    'service_id' => $serviceId,
                    'payment_method_id' => $paymentMethodId,
                    'is_default' => $paymentMethodId === $defaultPaymentMethodId ? 1 : 0,
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function servicePaymentMethodExists(int $serviceId, int $paymentMethodId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expense_service_payment_methods
             WHERE service_id = :service_id AND payment_method_id = :payment_method_id
             LIMIT 1'
        );
        $statement->execute([
            'service_id' => $serviceId,
            'payment_method_id' => $paymentMethodId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethodsForService(int $userId, int $serviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT methods.id, methods.user_id, methods.name, methods.type, methods.institution_name,
                    methods.notes, methods.active, pivot.is_default, pivot.created_at AS linked_at
             FROM expense_service_payment_methods pivot
             INNER JOIN expense_services services
                ON services.id = pivot.service_id
             INNER JOIN expense_payment_methods methods
                ON methods.id = pivot.payment_method_id
             WHERE services.id = :service_id
               AND services.user_id = :service_user_id
               AND methods.user_id = :method_user_id
             ORDER BY pivot.is_default DESC, methods.name ASC, methods.id ASC'
        );
        $statement->execute([
            'service_id' => $serviceId,
            'service_user_id' => $userId,
            'method_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @param array<int, int> $serviceIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function paymentMethodsForServices(int $userId, array $serviceIds): array
    {
        $serviceIds = array_values(array_unique(array_filter(
            array_map('intval', $serviceIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($serviceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT pivot.service_id, methods.id, methods.user_id, methods.name, methods.type, methods.institution_name,
                    methods.notes, methods.active, pivot.is_default, pivot.created_at AS linked_at
             FROM expense_service_payment_methods pivot
             INNER JOIN expense_services services
                ON services.id = pivot.service_id
             INNER JOIN expense_payment_methods methods
                ON methods.id = pivot.payment_method_id
             WHERE pivot.service_id IN (' . $placeholders . ')
               AND services.user_id = ?
               AND methods.user_id = ?
             ORDER BY pivot.service_id ASC, pivot.is_default DESC, methods.name ASC, methods.id ASC'
        );
        $statement->execute(array_merge($serviceIds, [$userId, $userId]));
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            $serviceId = (int) $row['service_id'];
            unset($row['service_id']);
            $grouped[$serviceId][] = $row;
        }

        return $grouped;
    }
}
