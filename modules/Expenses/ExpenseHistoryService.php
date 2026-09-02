<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use PDO;

final class ExpenseHistoryService
{
    public const RANGE_3_MONTHS = '3m';
    public const RANGE_6_MONTHS = '6m';
    public const RANGE_12_MONTHS = '12m';
    public const RANGE_YEAR_TO_DATE = 'ytd';
    public const RANGE_PREVIOUS_YEAR = 'previous_year';

    private const RANGE_MONTHS = [
        self::RANGE_3_MONTHS => 3,
        self::RANGE_6_MONTHS => 6,
        self::RANGE_12_MONTHS => 12,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function rangeLabels(): array
    {
        return [
            self::RANGE_3_MONTHS => 'Ultimos 3 meses',
            self::RANGE_6_MONTHS => 'Ultimos 6 meses',
            self::RANGE_12_MONTHS => 'Ultimos 12 meses',
            self::RANGE_YEAR_TO_DATE => 'Ano actual',
            self::RANGE_PREVIOUS_YEAR => 'Ano anterior',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function history(int $userId, array $input = [], ?DateTimeImmutable $today = null): array
    {
        $today ??= DateTimeHelper::nowLocal($this->timezone);
        $today = $today->setTimezone(DateTimeHelper::timezone($this->timezone));
        $range = $this->range((string) ($input['range'] ?? self::RANGE_6_MONTHS));
        $period = $this->periodForRange($range, $today);
        $filters = $this->filters($userId, $input);
        $months = $this->monthsBetween($period['start_month'], $period['end_month']);
        $monthCount = count($months);
        $monthlyRows = $this->monthlyRows($userId, $period['start_month'], $period['end_month'], $today->format('Y-m-d'), $filters);
        $monthlyTotals = $this->monthlySeries($months, $monthlyRows);
        $totalKnown = array_sum(array_column($monthlyTotals, 'known_amount_clp'));
        $totalPaid = array_sum(array_column($monthlyTotals, 'paid_amount_clp'));
        $totalPending = array_sum(array_column($monthlyTotals, 'pending_amount_clp'));
        $totalOverdue = array_sum(array_column($monthlyTotals, 'overdue_amount_clp'));
        $unknownCount = array_sum(array_column($monthlyTotals, 'unknown_amount_count'));
        $expenseCount = array_sum(array_column($monthlyTotals, 'expense_count'));
        $hasAnyData = $expenseCount > 0;

        return [
            'range' => $range,
            'range_label' => self::rangeLabels()[$range],
            'range_options' => self::rangeLabels(),
            'start_month' => $period['start_month'],
            'end_month' => $period['end_month'],
            'months_included' => $monthCount,
            'filters' => $filters,
            'has_any_data' => $hasAnyData,
            'has_comparison' => $monthCount >= 2 && $hasAnyData,
            'total_known_clp' => $totalKnown,
            'total_paid_clp' => $totalPaid,
            'average_monthly_clp' => $monthCount > 0 ? (int) round($totalKnown / $monthCount) : 0,
            'highest_month' => $totalKnown > 0 ? $this->extremeMonth($monthlyTotals, true) : null,
            'lowest_month' => $totalKnown > 0 ? $this->extremeMonth($monthlyTotals, false) : null,
            'monthly_totals' => $monthlyTotals,
            'monthly_unknown_count' => $unknownCount,
            'category_breakdown' => $this->withPercentages($this->categoryBreakdown($userId, $period['start_month'], $period['end_month'], $filters), $totalKnown),
            'service_breakdown' => $this->withPercentages($this->serviceBreakdown($userId, $period['start_month'], $period['end_month'], $filters), $totalKnown),
            'payment_method_breakdown' => $this->withPercentages($this->paymentMethodBreakdown($userId, $period['start_month'], $period['end_month'], $filters), $totalKnown),
            'status_breakdown' => [
                'paid_clp' => $totalPaid,
                'pending_clp' => $totalPending,
                'overdue_clp' => $totalOverdue,
                'unknown_amount_count' => $unknownCount,
            ],
            'category_series' => $filters['category_id'] === null ? [] : $monthlyTotals,
            'service_series' => $filters['service_id'] === null ? [] : $monthlyTotals,
            'month_details' => array_reverse($monthlyTotals),
        ];
    }

    private function range(string $range): string
    {
        return array_key_exists($range, self::rangeLabels()) ? $range : self::RANGE_6_MONTHS;
    }

    /**
     * @return array{start_month: string, end_month: string}
     */
    private function periodForRange(string $range, DateTimeImmutable $today): array
    {
        $currentMonth = $today->modify('first day of this month');

        if (isset(self::RANGE_MONTHS[$range])) {
            $months = self::RANGE_MONTHS[$range];

            return [
                'start_month' => $currentMonth->modify('-' . ($months - 1) . ' months')->format('Y-m-01'),
                'end_month' => $currentMonth->format('Y-m-01'),
            ];
        }

        if ($range === self::RANGE_YEAR_TO_DATE) {
            return [
                'start_month' => $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-01'),
                'end_month' => $currentMonth->format('Y-m-01'),
            ];
        }

        $previousYear = (int) $today->format('Y') - 1;

        return [
            'start_month' => sprintf('%04d-01-01', $previousYear),
            'end_month' => sprintf('%04d-12-01', $previousYear),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{category_id: ?int, service_id: ?int, payment_method_id: ?int}
     */
    private function filters(int $userId, array $input): array
    {
        return [
            'category_id' => $this->ownedId($userId, $input['category_id'] ?? $input['category'] ?? null, 'expense_categories'),
            'service_id' => $this->ownedId($userId, $input['service_id'] ?? $input['service'] ?? null, 'expense_services'),
            'payment_method_id' => $this->ownedId($userId, $input['payment_method_id'] ?? $input['payment_method'] ?? null, 'expense_payment_methods'),
        ];
    }

    private function ownedId(int $userId, mixed $value, string $table): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        $id = (int) $value;
        $statement = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id AND user_id = :user_id LIMIT 1");
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() === false ? null : $id;
    }

    /**
     * @param array{category_id: ?int, service_id: ?int, payment_method_id: ?int} $filters
     * @return array{0: string, 1: array<string, int>}
     */
    private function filterSql(array $filters, string $prefix): array
    {
        $where = '';
        $params = [];

        foreach ([
            'category_id' => 'expenses.category_id',
            'service_id' => 'expenses.service_id',
            'payment_method_id' => 'expenses.payment_method_id',
        ] as $filter => $column) {
            if ($filters[$filter] === null) {
                continue;
            }

            $placeholder = $prefix . '_' . $filter;
            $where .= ' AND ' . $column . ' = :' . $placeholder;
            $params[$placeholder] = (int) $filters[$filter];
        }

        return [$where, $params];
    }

    /**
     * @param array{category_id: ?int, service_id: ?int, payment_method_id: ?int} $filters
     * @return array<string, array<string, int>>
     */
    private function monthlyRows(int $userId, string $startMonth, string $endMonth, string $today, array $filters): array
    {
        [$filterSql, $filterParams] = $this->filterSql($filters, 'monthly');
        $statement = $this->pdo->prepare(
            "SELECT period_month,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(CASE WHEN amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS known_amount_clp,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS paid_amount_clp,
                    COALESCE(SUM(CASE WHEN status = 'pending' AND (due_on IS NULL OR due_on >= :today_pending) AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS pending_amount_clp,
                    COALESCE(SUM(CASE WHEN status = 'pending' AND due_on < :today_overdue AND amount_clp IS NOT NULL THEN amount_clp ELSE 0 END), 0) AS overdue_amount_clp,
                    COALESCE(SUM(CASE WHEN amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count
             FROM expenses
             WHERE user_id = :user_id
               AND period_month BETWEEN :start_month AND :end_month
               AND status <> 'cancelled'
               {$filterSql}
             GROUP BY period_month
             ORDER BY period_month ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'start_month' => $startMonth,
            'end_month' => $endMonth,
            'today_pending' => $today,
            'today_overdue' => $today,
        ] + $filterParams);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $periodMonth = (string) $row['period_month'];
            $rows[$periodMonth] = [
                'expense_count' => (int) $row['expense_count'],
                'known_amount_clp' => (int) $row['known_amount_clp'],
                'paid_amount_clp' => (int) $row['paid_amount_clp'],
                'pending_amount_clp' => (int) $row['pending_amount_clp'],
                'overdue_amount_clp' => (int) $row['overdue_amount_clp'],
                'unknown_amount_count' => (int) $row['unknown_amount_count'],
                'paid_count' => (int) $row['paid_count'],
                'pending_count' => (int) $row['pending_count'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string> $months
     * @param array<string, array<string, int>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function monthlySeries(array $months, array $rows): array
    {
        $series = [];
        $max = 0;

        foreach ($months as $periodMonth) {
            $max = max($max, $rows[$periodMonth]['known_amount_clp'] ?? 0);
        }

        $previous = null;

        foreach ($months as $periodMonth) {
            $row = $rows[$periodMonth] ?? [
                'expense_count' => 0,
                'known_amount_clp' => 0,
                'paid_amount_clp' => 0,
                'pending_amount_clp' => 0,
                'overdue_amount_clp' => 0,
                'unknown_amount_count' => 0,
                'paid_count' => 0,
                'pending_count' => 0,
            ];
            $amount = (int) $row['known_amount_clp'];
            $comparison = $previous === null ? null : $this->comparison($previous, $amount);
            $previous = $amount;
            $series[] = [
                'period_month' => $periodMonth,
                'month_value' => substr($periodMonth, 0, 7),
                'label' => $this->monthLabel($periodMonth, true),
                'full_label' => $this->monthLabel($periodMonth, false),
                'expense_count' => (int) $row['expense_count'],
                'known_amount_clp' => $amount,
                'paid_amount_clp' => (int) $row['paid_amount_clp'],
                'pending_amount_clp' => (int) $row['pending_amount_clp'],
                'overdue_amount_clp' => (int) $row['overdue_amount_clp'],
                'unknown_amount_count' => (int) $row['unknown_amount_count'],
                'paid_count' => (int) $row['paid_count'],
                'pending_count' => (int) $row['pending_count'],
                'bar_percentage' => $max > 0 ? (int) round(($amount / $max) * 100) : 0,
                'comparison' => $comparison,
            ];
        }

        return $series;
    }

    /**
     * @param array{category_id: ?int, service_id: ?int, payment_method_id: ?int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function categoryBreakdown(int $userId, string $startMonth, string $endMonth, array $filters): array
    {
        [$filterSql, $filterParams] = $this->filterSql($filters, 'category');
        $statement = $this->pdo->prepare(
            "SELECT expenses.category_id AS id,
                    categories.name,
                    categories.color,
                    categories.active,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS known_amount_clp,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             LEFT JOIN expense_categories categories ON categories.id = expenses.category_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month BETWEEN :start_month AND :end_month
               AND expenses.status <> 'cancelled'
               {$filterSql}
             GROUP BY expenses.category_id, categories.name, categories.color, categories.active
             ORDER BY known_amount_clp DESC, expense_count DESC, name ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'start_month' => $startMonth,
            'end_month' => $endMonth,
        ] + $filterParams);

        return array_map(static fn (array $row): array => [
            'id' => $row['id'] === null ? null : (int) $row['id'],
            'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? (string) $row['name'] : 'Sin categoria',
            'color' => is_string($row['color'] ?? null) && $row['color'] !== '' ? strtoupper((string) $row['color']) : null,
            'active' => $row['active'] === null ? null : (int) $row['active'],
            'expense_count' => (int) $row['expense_count'],
            'known_amount_clp' => (int) $row['known_amount_clp'],
            'unknown_amount_count' => (int) $row['unknown_amount_count'],
        ], $statement->fetchAll());
    }

    /**
     * @param array{category_id: ?int, service_id: ?int, payment_method_id: ?int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function serviceBreakdown(int $userId, string $startMonth, string $endMonth, array $filters): array
    {
        [$filterSql, $filterParams] = $this->filterSql($filters, 'service');
        $statement = $this->pdo->prepare(
            "SELECT expenses.service_id AS id,
                    services.name,
                    services.active,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS known_amount_clp,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             INNER JOIN expense_services services ON services.id = expenses.service_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month BETWEEN :start_month AND :end_month
               AND expenses.status <> 'cancelled'
               {$filterSql}
             GROUP BY expenses.service_id, services.name, services.active
             ORDER BY known_amount_clp DESC, expense_count DESC, services.name ASC
             LIMIT 10"
        );
        $statement->execute([
            'user_id' => $userId,
            'start_month' => $startMonth,
            'end_month' => $endMonth,
        ] + $filterParams);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'active' => (int) $row['active'],
            'expense_count' => (int) $row['expense_count'],
            'known_amount_clp' => (int) $row['known_amount_clp'],
            'unknown_amount_count' => (int) $row['unknown_amount_count'],
        ], $statement->fetchAll());
    }

    /**
     * @param array{category_id: ?int, service_id: ?int, payment_method_id: ?int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function paymentMethodBreakdown(int $userId, string $startMonth, string $endMonth, array $filters): array
    {
        [$filterSql, $filterParams] = $this->filterSql($filters, 'payment');
        $statement = $this->pdo->prepare(
            "SELECT expenses.payment_method_id AS id,
                    payment_methods.name,
                    payment_methods.type,
                    payment_methods.institution_name,
                    payment_methods.active,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS known_amount_clp,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             LEFT JOIN expense_payment_methods payment_methods ON payment_methods.id = expenses.payment_method_id
             WHERE expenses.user_id = :user_id
               AND expenses.period_month BETWEEN :start_month AND :end_month
               AND expenses.status <> 'cancelled'
               {$filterSql}
             GROUP BY expenses.payment_method_id, payment_methods.name, payment_methods.type, payment_methods.institution_name, payment_methods.active
             ORDER BY known_amount_clp DESC, expense_count DESC, name ASC"
        );
        $statement->execute([
            'user_id' => $userId,
            'start_month' => $startMonth,
            'end_month' => $endMonth,
        ] + $filterParams);

        return array_map(static fn (array $row): array => [
            'id' => $row['id'] === null ? null : (int) $row['id'],
            'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? (string) $row['name'] : 'Sin medio definido',
            'type' => is_string($row['type'] ?? null) ? (string) $row['type'] : null,
            'institution_name' => is_string($row['institution_name'] ?? null) ? (string) $row['institution_name'] : null,
            'active' => $row['active'] === null ? null : (int) $row['active'],
            'expense_count' => (int) $row['expense_count'],
            'known_amount_clp' => (int) $row['known_amount_clp'],
            'unknown_amount_count' => (int) $row['unknown_amount_count'],
        ], $statement->fetchAll());
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

    /**
     * @param array<int, array<string, mixed>> $monthlyTotals
     * @return array<string, mixed>
     */
    private function extremeMonth(array $monthlyTotals, bool $highest): array
    {
        $selected = $monthlyTotals[0] ?? null;

        foreach ($monthlyTotals as $month) {
            if ($selected === null) {
                $selected = $month;
                continue;
            }

            $left = (int) $month['known_amount_clp'];
            $right = (int) $selected['known_amount_clp'];

            if (($highest && $left > $right) || (!$highest && $left < $right)) {
                $selected = $month;
            }
        }

        return $selected ?? [];
    }

    /**
     * @return array{diff_clp: int, percentage: ?float, direction: string, label: string}
     */
    private function comparison(int $previous, int $current): array
    {
        $diff = $current - $previous;
        $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same');
        $percentage = $previous > 0 ? round(($diff / $previous) * 100, 1) : null;
        $amountLabel = ($diff > 0 ? '+' : ($diff < 0 ? '-' : '')) . ExpenseFormat::clp(abs($diff));

        if ($percentage === null) {
            $label = $previous === 0 && $current === 0 ? 'Sin variacion vs mes anterior' : $amountLabel . ' vs mes anterior';
        } else {
            $label = ($percentage > 0 ? '+' : '') . number_format($percentage, 1, ',', '.') . '% vs mes anterior';
        }

        return [
            'diff_clp' => $diff,
            'percentage' => $percentage,
            'direction' => $direction,
            'label' => $label,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function monthsBetween(string $startMonth, string $endMonth): array
    {
        $timezone = DateTimeHelper::timezone($this->timezone);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startMonth, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endMonth, $timezone);

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return [];
        }

        $months = [];
        $current = $start;

        while ($current <= $end && count($months) < 240) {
            $months[] = $current->format('Y-m-01');
            $current = $current->modify('+1 month');
        }

        return $months;
    }

    private function monthLabel(string $periodMonth, bool $short): string
    {
        $months = $short
            ? [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sept', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic']
            : [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodMonth, DateTimeHelper::timezone($this->timezone));

        if (!$date instanceof DateTimeImmutable) {
            return $periodMonth;
        }

        return $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    }
}
