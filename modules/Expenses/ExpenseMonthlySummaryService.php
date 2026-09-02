<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use PDO;

final class ExpenseMonthlySummaryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $userId, string $periodMonth, ?DateTimeImmutable $today = null): array
    {
        $periodMonth = $this->periodMonth($periodMonth);
        $today ??= DateTimeHelper::nowLocal($this->timezone);
        $todayString = $today->setTimezone(DateTimeHelper::timezone($this->timezone))->format('Y-m-d');
        $totals = $this->totals($userId, $periodMonth, $todayString);
        $totalKnown = (int) $totals['total_known_clp'];

        return [
            'period_month' => $periodMonth,
            'today' => $todayString,
            'expense_count' => (int) $totals['expense_count'],
            'total_known_clp' => $totalKnown,
            'paid_known_clp' => (int) $totals['paid_known_clp'],
            'pending_known_clp' => (int) $totals['pending_known_clp'],
            'overdue_known_clp' => (int) $totals['overdue_known_clp'],
            'unknown_amount_count' => (int) $totals['unknown_amount_count'],
            'paid_percentage' => $totalKnown > 0 ? (int) round(((int) $totals['paid_known_clp'] / $totalKnown) * 100) : 0,
            'category_breakdown' => $this->withPercentages($this->categoryBreakdown($userId, $periodMonth), $totalKnown),
            'payment_method_breakdown' => $this->withPercentages($this->paymentMethodBreakdown($userId, $periodMonth), $totalKnown),
            'upcoming_due' => $this->dueItems($userId, $periodMonth, $todayString, false),
            'overdue_items' => $this->dueItems($userId, $periodMonth, $todayString, true),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(int $userId, string $periodMonth, string $today): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS expense_count,
                COALESCE(SUM(CASE WHEN amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS total_known_clp,
                COALESCE(SUM(CASE WHEN status = 'paid' AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS paid_known_clp,
                COALESCE(SUM(CASE WHEN status = 'pending' AND (due_on IS NULL OR due_on >= :today_pending) AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS pending_known_clp,
                COALESCE(SUM(CASE WHEN status = 'pending' AND due_on < :today_overdue AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS overdue_known_clp,
                COALESCE(SUM(CASE WHEN amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             WHERE user_id = :user_id
               AND period_month = :period_month
               AND status <> 'cancelled'"
        );
        $statement->execute([
            'user_id' => $userId,
            'period_month' => $periodMonth,
            'today_pending' => $today,
            'today_overdue' => $today,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? array_map(static fn (mixed $value): int => (int) $value, $row) : [
            'expense_count' => 0,
            'total_known_clp' => 0,
            'paid_known_clp' => 0,
            'pending_known_clp' => 0,
            'overdue_known_clp' => 0,
            'unknown_amount_count' => 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryBreakdown(int $userId, string $periodMonth): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                expenses.category_id AS id,
                categories.name,
                categories.color,
                categories.active,
                COUNT(*) AS expense_count,
                COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS known_amount_clp,
                COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             LEFT JOIN expense_categories categories
                ON categories.id = expenses.category_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month = :period_month
               AND expenses.status <> 'cancelled'
             GROUP BY expenses.category_id, categories.name, categories.color, categories.active
             ORDER BY known_amount_clp DESC, expense_count DESC, name ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'period_month' => $periodMonth,
        ]);

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'] === null ? null : (int) $row['id'],
                'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? (string) $row['name'] : 'Sin categoria',
                'color' => is_string($row['color'] ?? null) && $row['color'] !== '' ? strtoupper((string) $row['color']) : null,
                'active' => $row['active'] === null ? null : (int) $row['active'],
                'expense_count' => (int) $row['expense_count'],
                'known_amount_clp' => (int) $row['known_amount_clp'],
                'unknown_amount_count' => (int) $row['unknown_amount_count'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentMethodBreakdown(int $userId, string $periodMonth): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                expenses.payment_method_id AS id,
                payment_methods.name,
                payment_methods.type,
                payment_methods.institution_name,
                payment_methods.active,
                COUNT(*) AS expense_count,
                COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS known_amount_clp,
                COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             LEFT JOIN expense_payment_methods payment_methods
                ON payment_methods.id = expenses.payment_method_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month = :period_month
               AND expenses.status <> 'cancelled'
             GROUP BY expenses.payment_method_id, payment_methods.name, payment_methods.type, payment_methods.institution_name, payment_methods.active
             ORDER BY known_amount_clp DESC, expense_count DESC, name ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'period_month' => $periodMonth,
        ]);

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'] === null ? null : (int) $row['id'],
                'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? (string) $row['name'] : 'Sin medio definido',
                'type' => is_string($row['type'] ?? null) ? (string) $row['type'] : null,
                'institution_name' => is_string($row['institution_name'] ?? null) ? (string) $row['institution_name'] : null,
                'active' => $row['active'] === null ? null : (int) $row['active'],
                'expense_count' => (int) $row['expense_count'],
                'known_amount_clp' => (int) $row['known_amount_clp'],
                'unknown_amount_count' => (int) $row['unknown_amount_count'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dueItems(int $userId, string $periodMonth, string $today, bool $overdue): array
    {
        $operator = $overdue ? '<' : '>=';
        $direction = $overdue ? 'ASC' : 'ASC';
        $statement = $this->pdo->prepare(
            "SELECT expenses.id, expenses.service_id, expenses.recurring_rule_id, expenses.category_id,
                    categories.name AS category_name, categories.color AS category_color,
                    expenses.description, expenses.amount_clp, expenses.due_on, expenses.payment_method_id,
                    payment_methods.name AS payment_method_name, expenses.status, expenses.notes
             FROM expenses
             LEFT JOIN expense_categories categories
                ON categories.id = expenses.category_id
             LEFT JOIN expense_payment_methods payment_methods
                ON payment_methods.id = expenses.payment_method_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month = :period_month
               AND expenses.status = 'pending'
               AND expenses.due_on IS NOT NULL
               AND expenses.due_on {$operator} :today
             ORDER BY expenses.due_on {$direction}, expenses.id ASC
             LIMIT 5"
        );
        $statement->execute([
            'user_id' => $userId,
            'period_month' => $periodMonth,
            'today' => $today,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function withPercentages(array $rows, int $totalKnown): array
    {
        return array_map(static function (array $row) use ($totalKnown): array {
            $known = (int) ($row['known_amount_clp'] ?? 0);
            $row['percentage'] = $totalKnown > 0 ? (int) round(($known / $totalKnown) * 100) : 0;

            return $row;
        }, $rows);
    }

    private function periodMonth(string $periodMonth): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodMonth, DateTimeHelper::timezone($this->timezone));

        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $periodMonth || substr($periodMonth, 8, 2) !== '01') {
            throw new ExpenseValidationException('Periodo invalido.');
        }

        return $periodMonth;
    }
}
