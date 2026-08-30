<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;
use PDOException;

final class ExpenseRecurringRuleRepository
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
            'INSERT INTO expense_recurring_rules
                (user_id, service_id, frequency, interval_value, day_of_month, default_amount_clp,
                 default_category_id, default_payment_method_id, starts_on, ends_on, next_generation_on, active)
             VALUES
                (:user_id, :service_id, :frequency, :interval_value, :day_of_month, :default_amount_clp,
                 :default_category_id, :default_payment_method_id, :starts_on, :ends_on, :next_generation_on, :active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'service_id' => $data['service_id'],
            'frequency' => $data['frequency'],
            'interval_value' => $data['interval_value'],
            'day_of_month' => $data['day_of_month'],
            'default_amount_clp' => $data['default_amount_clp'],
            'default_category_id' => $data['default_category_id'],
            'default_payment_method_id' => $data['default_payment_method_id'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'next_generation_on' => $data['next_generation_on'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $ruleId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $ruleId,
            'user_id' => $userId,
        ];

        foreach ([
            'frequency',
            'interval_value',
            'day_of_month',
            'default_amount_clp',
            'default_category_id',
            'default_payment_method_id',
            'starts_on',
            'ends_on',
            'next_generation_on',
            'active',
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
            'UPDATE expense_recurring_rules
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $ruleId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE rules.id = :id AND rules.user_id = :user_id
             LIMIT 1');
        $statement->execute([
            'id' => $ruleId,
            'user_id' => $userId,
        ]);

        $rule = $statement->fetch();

        return is_array($rule) ? $rule : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByServiceForUser(int $userId, int $serviceId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE rules.service_id = :service_id AND rules.user_id = :user_id
             LIMIT 1');
        $statement->execute([
            'service_id' => $serviceId,
            'user_id' => $userId,
        ]);

        $rule = $statement->fetch();

        return is_array($rule) ? $rule : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueForProcessing(string $localDate, ?int $onlyRuleId = null): array
    {
        $where = [
            'rules.active = 1',
            'rules.next_generation_on IS NOT NULL',
            'rules.next_generation_on <= :local_date',
        ];
        $params = ['local_date' => $localDate];

        if ($onlyRuleId !== null) {
            $where[] = 'rules.id = :rule_id';
            $params['rule_id'] = $onlyRuleId;
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY rules.next_generation_on ASC, rules.id ASC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createGeneratedExpense(int $userId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO expenses
                (user_id, service_id, recurring_rule_id, category_id, period_month, description, amount_clp,
                 installment_current, installment_total, due_on, paid_on, payment_method_id, status, notes)
             VALUES
                (:user_id, :service_id, :recurring_rule_id, :category_id, :period_month, :description, :amount_clp,
                 NULL, NULL, :due_on, NULL, :payment_method_id, :status, :notes)'
        );

        try {
            $statement->execute([
                'user_id' => $userId,
                'service_id' => $data['service_id'],
                'recurring_rule_id' => $data['recurring_rule_id'],
                'category_id' => $data['category_id'],
                'period_month' => $data['period_month'],
                'description' => $data['description'],
                'amount_clp' => $data['amount_clp'],
                'due_on' => $data['due_on'],
                'payment_method_id' => $data['payment_method_id'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function generatedExpenseExists(int $ruleId, string $periodMonth): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expenses
             WHERE recurring_rule_id = :rule_id
               AND period_month = :period_month
             LIMIT 1'
        );
        $statement->execute([
            'rule_id' => $ruleId,
            'period_month' => $periodMonth,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function serviceBelongsToUser(int $userId, int $serviceId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM expense_services WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $serviceId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
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

    private function selectSql(): string
    {
        return 'SELECT rules.id, rules.user_id, rules.service_id, rules.frequency, rules.interval_value,
                    rules.day_of_month, rules.default_amount_clp, rules.default_category_id,
                    rules.default_payment_method_id, rules.starts_on, rules.ends_on,
                    rules.next_generation_on, rules.active, rules.created_at, rules.updated_at,
                    services.name AS service_name, services.category_id AS service_category_id,
                    services.default_amount_clp AS service_default_amount_clp, services.active AS service_active,
                    categories.name AS default_category_name,
                    payment_methods.name AS default_payment_method_name,
                    service_default_methods.payment_method_id AS service_default_payment_method_id,
                    service_default_payment_methods.name AS service_default_payment_method_name,
                    service_default_payment_methods.active AS service_default_payment_method_active,
                    payment_methods.active AS default_payment_method_active
             FROM expense_recurring_rules rules
             INNER JOIN expense_services services
                ON services.id = rules.service_id
             LEFT JOIN expense_categories categories
                ON categories.id = rules.default_category_id
             LEFT JOIN expense_payment_methods payment_methods
                ON payment_methods.id = rules.default_payment_method_id
             LEFT JOIN expense_service_payment_methods service_default_methods
                ON service_default_methods.service_id = services.id
               AND service_default_methods.is_default = 1
             LEFT JOIN expense_payment_methods service_default_payment_methods
                ON service_default_payment_methods.id = service_default_methods.payment_method_id';
    }
}
