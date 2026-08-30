<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;

final class ExpenseRecurringAdjustmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(int $userId, int $ruleId, string $periodMonth, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO expense_recurring_adjustments
                (user_id, recurring_rule_id, period_month, action, description, amount_override,
                 amount_clp, due_on, category_id, payment_method_id, notes, active)
             VALUES
                (:user_id, :recurring_rule_id, :period_month, :action, :description, :amount_override,
                 :amount_clp, :due_on, :category_id, :payment_method_id, :notes, :active)
             ON DUPLICATE KEY UPDATE
                action = VALUES(action),
                description = VALUES(description),
                amount_override = VALUES(amount_override),
                amount_clp = VALUES(amount_clp),
                due_on = VALUES(due_on),
                category_id = VALUES(category_id),
                payment_method_id = VALUES(payment_method_id),
                notes = VALUES(notes),
                active = VALUES(active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'recurring_rule_id' => $ruleId,
            'period_month' => $periodMonth,
            'action' => $data['action'],
            'description' => $data['description'],
            'amount_override' => $data['amount_override'],
            'amount_clp' => $data['amount_clp'],
            'due_on' => $data['due_on'],
            'category_id' => $data['category_id'],
            'payment_method_id' => $data['payment_method_id'],
            'notes' => $data['notes'],
            'active' => $data['active'],
        ]);

        $existing = $this->findByRuleAndPeriodForUser($userId, $ruleId, $periodMonth);

        return (int) ($existing['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateForUser(int $userId, int $adjustmentId, int $ruleId, string $periodMonth, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE expense_recurring_adjustments
             SET period_month = :period_month,
                 action = :action,
                 description = :description,
                 amount_override = :amount_override,
                 amount_clp = :amount_clp,
                 due_on = :due_on,
                 category_id = :category_id,
                 payment_method_id = :payment_method_id,
                 notes = :notes,
                 active = :active
             WHERE id = :id
               AND user_id = :user_id
               AND recurring_rule_id = :recurring_rule_id'
        );
        $statement->execute([
            'id' => $adjustmentId,
            'user_id' => $userId,
            'recurring_rule_id' => $ruleId,
            'period_month' => $periodMonth,
            'action' => $data['action'],
            'description' => $data['description'],
            'amount_override' => $data['amount_override'],
            'amount_clp' => $data['amount_clp'],
            'due_on' => $data['due_on'],
            'category_id' => $data['category_id'],
            'payment_method_id' => $data['payment_method_id'],
            'notes' => $data['notes'],
            'active' => $data['active'],
        ]);

        return $this->findByIdForUser($userId, $adjustmentId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $adjustmentId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE adjustments.user_id = :user_id
               AND adjustments.id = :id
             LIMIT 1');
        $statement->execute([
            'user_id' => $userId,
            'id' => $adjustmentId,
        ]);

        $adjustment = $statement->fetch();

        return is_array($adjustment) ? $adjustment : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRuleAndPeriodForUser(int $userId, int $ruleId, string $periodMonth): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE adjustments.user_id = :user_id
               AND adjustments.recurring_rule_id = :rule_id
               AND adjustments.period_month = :period_month
             LIMIT 1');
        $statement->execute([
            'user_id' => $userId,
            'rule_id' => $ruleId,
            'period_month' => $periodMonth,
        ]);

        $adjustment = $statement->fetch();

        return is_array($adjustment) ? $adjustment : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByRuleAndPeriod(int $ruleId, string $periodMonth): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE adjustments.recurring_rule_id = :rule_id
               AND adjustments.period_month = :period_month
               AND adjustments.active = 1
             LIMIT 1');
        $statement->execute([
            'rule_id' => $ruleId,
            'period_month' => $periodMonth,
        ]);

        $adjustment = $statement->fetch();

        return is_array($adjustment) ? $adjustment : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForServiceForUser(int $userId, int $serviceId): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             INNER JOIN expense_recurring_rules rules
                ON rules.id = adjustments.recurring_rule_id
             WHERE adjustments.user_id = :user_id
               AND rules.service_id = :service_id
             ORDER BY adjustments.period_month ASC, adjustments.id ASC');
        $statement->execute([
            'user_id' => $userId,
            'service_id' => $serviceId,
        ]);

        return $statement->fetchAll();
    }

    public function deleteForUser(int $userId, int $adjustmentId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM expense_recurring_adjustments
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $adjustmentId,
            'user_id' => $userId,
        ]);

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

    public function ruleBelongsToServiceForUser(int $userId, int $ruleId, int $serviceId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM expense_recurring_rules
             WHERE id = :id AND service_id = :service_id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $ruleId,
            'service_id' => $serviceId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function selectSql(): string
    {
        return 'SELECT adjustments.id, adjustments.user_id, adjustments.recurring_rule_id,
                    adjustments.period_month, adjustments.action, adjustments.description,
                    adjustments.amount_override, adjustments.amount_clp, adjustments.due_on,
                    adjustments.category_id, categories.name AS category_name,
                    adjustments.payment_method_id, payment_methods.name AS payment_method_name,
                    adjustments.notes, adjustments.active, adjustments.created_at, adjustments.updated_at
             FROM expense_recurring_adjustments adjustments
             LEFT JOIN expense_categories categories
                ON categories.id = adjustments.category_id
             LEFT JOIN expense_payment_methods payment_methods
                ON payment_methods.id = adjustments.payment_method_id';
    }
}
