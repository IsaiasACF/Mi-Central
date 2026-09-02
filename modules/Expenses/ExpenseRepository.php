<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;

final class ExpenseRepository
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
            'INSERT INTO expenses
                (user_id, service_id, recurring_rule_id, category_id, period_month, description, amount_clp,
                 installment_current, installment_total, due_on, paid_on, payment_method_id,
                 status, notes)
             VALUES
                (:user_id, :service_id, :recurring_rule_id, :category_id, :period_month, :description, :amount_clp,
                 :installment_current, :installment_total, :due_on, :paid_on, :payment_method_id,
                 :status, :notes)'
        );
        $statement->execute([
            'user_id' => $userId,
            'service_id' => $data['service_id'],
            'recurring_rule_id' => $data['recurring_rule_id'] ?? null,
            'category_id' => $data['category_id'],
            'period_month' => $data['period_month'],
            'description' => $data['description'],
            'amount_clp' => $data['amount_clp'],
            'installment_current' => $data['installment_current'],
            'installment_total' => $data['installment_total'],
            'due_on' => $data['due_on'],
            'paid_on' => $data['paid_on'],
            'payment_method_id' => $data['payment_method_id'],
            'status' => $data['status'],
            'notes' => $data['notes'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $expenseId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE expenses.id = :id AND expenses.user_id = :user_id
             LIMIT 1');
        $statement->execute([
            'id' => $expenseId,
            'user_id' => $userId,
        ]);

        $expense = $statement->fetch();

        return is_array($expense) ? $expense : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForMonth(int $userId, string $periodMonth, array $filters = []): array
    {
        $where = [
            'expenses.user_id = :user_id',
            'expenses.period_month = :period_month',
        ];
        $params = [
            'user_id' => $userId,
            'period_month' => $periodMonth,
        ];

        if (isset($filters['status']) && in_array($filters['status'], ['pending', 'paid', 'cancelled'], true)) {
            $where[] = 'expenses.status = :filter_status';
            $params['filter_status'] = $filters['status'];
        }

        foreach ([
            'category_id' => 'expenses.category_id',
            'service_id' => 'expenses.service_id',
            'payment_method_id' => 'expenses.payment_method_id',
        ] as $filter => $column) {
            if (!isset($filters[$filter]) || $filters[$filter] === '') {
                continue;
            }

            $placeholder = 'filter_' . $filter;
            $where[] = $column . ' = :' . $placeholder;
            $params[$placeholder] = (int) $filters[$filter];
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = '(
                expenses.description LIKE :search_description
                OR expenses.notes LIKE :search_notes
                OR services.name LIKE :search_service
                OR categories.name LIKE :search_category
                OR payment_methods.name LIKE :search_payment_method
            )';
            $search = '%' . trim((string) $filters['search']) . '%';
            $params['search_description'] = $search;
            $params['search_notes'] = $search;
            $params['search_service'] = $search;
            $params['search_category'] = $search;
            $params['search_payment_method'] = $search;
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY expenses.id ASC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $expenseId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $expenseId,
            'user_id' => $userId,
        ];

        foreach ([
            'service_id',
            'category_id',
            'period_month',
            'description',
            'amount_clp',
            'installment_current',
            'installment_total',
            'due_on',
            'paid_on',
            'payment_method_id',
            'status',
            'notes',
        ] as $field) {
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
            'UPDATE expenses
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $expenseId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM expenses WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $expenseId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serviceForUser(int $userId, int $serviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, category_id, name, default_amount_clp, active
             FROM expense_services
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $serviceId,
            'user_id' => $userId,
        ]);

        $service = $statement->fetch();

        return is_array($service) ? $service : null;
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

    public function paymentMethodIsActiveForUser(int $userId, int $paymentMethodId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expense_payment_methods
             WHERE id = :id AND user_id = :user_id AND active = 1
             LIMIT 1'
        );
        $statement->execute([
            'id' => $paymentMethodId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function serviceAllowsPaymentMethod(int $userId, int $serviceId, int $paymentMethodId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expense_service_payment_methods pivot
             INNER JOIN expense_services services
                ON services.id = pivot.service_id
             INNER JOIN expense_payment_methods methods
                ON methods.id = pivot.payment_method_id
             WHERE services.id = :service_id
               AND methods.id = :payment_method_id
               AND services.user_id = :service_user_id
               AND methods.user_id = :method_user_id
             LIMIT 1'
        );
        $statement->execute([
            'service_id' => $serviceId,
            'payment_method_id' => $paymentMethodId,
            'service_user_id' => $userId,
            'method_user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function selectSql(): string
    {
        return 'SELECT expenses.id, expenses.user_id, expenses.service_id, expenses.recurring_rule_id,
                    services.name AS service_name,
                    expenses.category_id, categories.name AS category_name, categories.color AS category_color,
                    categories.active AS category_active,
                    expenses.period_month, expenses.description, expenses.amount_clp,
                    expenses.installment_current, expenses.installment_total,
                    expenses.due_on, expenses.paid_on, expenses.payment_method_id,
                    payment_methods.name AS payment_method_name,
                    payment_methods.type AS payment_method_type,
                    payment_methods.active AS payment_method_active,
                    services.active AS service_active,
                    expenses.status, expenses.notes, expenses.created_at, expenses.updated_at
             FROM expenses
             LEFT JOIN expense_services services
                ON services.id = expenses.service_id
             LEFT JOIN expense_categories categories
                ON categories.id = expenses.category_id
             LEFT JOIN expense_payment_methods payment_methods
                ON payment_methods.id = expenses.payment_method_id';
    }
}
