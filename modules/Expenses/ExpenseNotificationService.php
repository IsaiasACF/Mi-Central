<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use Modules\Notifications\NotificationRepository;
use PDO;
use Throwable;

final class ExpenseNotificationService
{
    private const SOURCE_MODULE = 'expenses';
    private const ENTITY_EXPENSE = 'expense';

    public function __construct(
        private readonly PDO $pdo,
        private readonly NotificationRepository $notifications,
        private readonly ExpenseNotificationFormatter $formatter,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array{
     *     processed_users: int,
     *     due_tomorrow: int,
     *     due_today: int,
     *     overdue: int,
     *     missing_amount: int,
     *     weekly_summaries: int,
     *     duplicates_skipped: int,
     *     errors: int
     * }
     */
    public function process(?DateTimeImmutable $nowLocal = null, ?array $onlyUserIds = null): array
    {
        $nowLocal ??= DateTimeHelper::nowLocal($this->timezone);
        $nowLocal = $nowLocal->setTimezone(DateTimeHelper::timezone($this->timezone));
        $onlyUserIds = $this->onlyUserIds($onlyUserIds);
        $today = $nowLocal->format('Y-m-d');
        $tomorrow = $nowLocal->modify('+1 day')->format('Y-m-d');
        $missingAmountDay = $nowLocal->modify('+3 days')->format('Y-m-d');
        $nowUtc = $nowLocal->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $summary = [
            'processed_users' => 0,
            'due_tomorrow' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'missing_amount' => 0,
            'weekly_summaries' => 0,
            'duplicates_skipped' => 0,
            'errors' => 0,
        ];
        $processedUsers = [];

        foreach ($this->expensesDueOn($tomorrow, $onlyUserIds) as $expense) {
            $processedUsers[(int) $expense['user_id']] = true;
            $this->createExpenseNotification(
                $expense,
                NotificationRepository::TYPE_EXPENSE_DUE_SOON,
                'expense_due_tomorrow:' . (int) $expense['id'] . ':' . $tomorrow,
                $this->formatter->dueTomorrow($expense),
                $nowUtc,
                'due_tomorrow',
                $summary,
            );
        }

        foreach ($this->expensesDueOn($today, $onlyUserIds) as $expense) {
            $processedUsers[(int) $expense['user_id']] = true;
            $this->createExpenseNotification(
                $expense,
                NotificationRepository::TYPE_EXPENSE_DUE_TODAY,
                'expense_due_today:' . (int) $expense['id'] . ':' . $today,
                $this->formatter->dueToday($expense),
                $nowUtc,
                'due_today',
                $summary,
            );
        }

        foreach ($this->overdueExpenses($today, $onlyUserIds) as $expense) {
            $processedUsers[(int) $expense['user_id']] = true;
            $dueOn = is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : $today;
            $this->createExpenseNotification(
                $expense,
                NotificationRepository::TYPE_EXPENSE_OVERDUE,
                'expense_overdue:' . (int) $expense['id'] . ':' . $dueOn,
                $this->formatter->overdue($expense),
                $nowUtc,
                'overdue',
                $summary,
            );
        }

        foreach ($this->missingAmountExpenses($missingAmountDay, $onlyUserIds) as $expense) {
            $processedUsers[(int) $expense['user_id']] = true;
            $period = is_string($expense['period_month'] ?? null) ? substr((string) $expense['period_month'], 0, 7) : $today;
            $this->createExpenseNotification(
                $expense,
                NotificationRepository::TYPE_EXPENSE_MISSING_AMOUNT,
                'expense_missing_amount:' . (int) $expense['id'] . ':' . $period,
                $this->formatter->missingAmount($expense),
                $nowUtc,
                'missing_amount',
                $summary,
            );
        }

        if ((int) $nowLocal->format('N') === 1) {
            foreach ($this->weeklySummaries($today, $nowLocal->modify('+6 days')->format('Y-m-d'), $onlyUserIds) as $row) {
                $userId = (int) $row['user_id'];
                $processedUsers[$userId] = true;
                $message = $this->formatter->weeklySummary(
                    (int) $row['expense_count'],
                    (int) $row['total_known_clp'],
                    (int) $row['unknown_amount_count'],
                );
                $weekKey = $nowLocal->format('o-\WW');

                $this->createGenericNotification(
                    $userId,
                    NotificationRepository::TYPE_EXPENSE_WEEKLY_SUMMARY,
                    'expense_weekly_summary:' . $userId . ':' . $weekKey,
                    $message,
                    $nowUtc,
                    'weekly_summaries',
                    $summary,
                );
            }
        }

        $summary['processed_users'] = count($processedUsers);

        return $summary;
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, array<string, mixed>>
     */
    private function expensesDueOn(string $date, ?array $onlyUserIds): array
    {
        return $this->expenseRows(
            'expenses.status = "pending" AND expenses.due_on = :due_on',
            ['due_on' => $date],
            $onlyUserIds,
        );
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, array<string, mixed>>
     */
    private function overdueExpenses(string $today, ?array $onlyUserIds): array
    {
        return $this->expenseRows(
            'expenses.status = "pending" AND expenses.due_on < :today',
            ['today' => $today],
            $onlyUserIds,
        );
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, array<string, mixed>>
     */
    private function missingAmountExpenses(string $date, ?array $onlyUserIds): array
    {
        return $this->expenseRows(
            'expenses.status = "pending" AND expenses.amount_clp IS NULL AND expenses.due_on = :due_on',
            ['due_on' => $date],
            $onlyUserIds,
        );
    }

    /**
     * @param array<string, scalar> $params
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, array<string, mixed>>
     */
    private function expenseRows(string $condition, array $params, ?array $onlyUserIds): array
    {
        [$userSql, $userParams] = $this->onlyUsersSql($onlyUserIds);
        $statement = $this->pdo->prepare(
            'SELECT expenses.id, expenses.user_id, expenses.service_id, expenses.recurring_rule_id,
                    expenses.period_month, expenses.description, expenses.amount_clp, expenses.due_on,
                    expenses.status, expenses.payment_method_id, expenses.installment_current, expenses.installment_total
             FROM expenses
             INNER JOIN users ON users.id = expenses.user_id AND users.is_active = 1
             WHERE ' . $condition . '
               AND expenses.due_on IS NOT NULL
               ' . $userSql . '
             ORDER BY expenses.user_id ASC, expenses.due_on ASC, expenses.id ASC
             LIMIT 1000'
        );
        $statement->execute($params + $userParams);

        return $statement->fetchAll();
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, array<string, mixed>>
     */
    private function weeklySummaries(string $start, string $end, ?array $onlyUserIds): array
    {
        [$userSql, $userParams] = $this->onlyUsersSql($onlyUserIds);
        $statement = $this->pdo->prepare(
            'SELECT expenses.user_id,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NOT NULL THEN expenses.amount_clp ELSE 0 END), 0) AS total_known_clp,
                    COALESCE(SUM(CASE WHEN expenses.amount_clp IS NULL THEN 1 ELSE 0 END), 0) AS unknown_amount_count
             FROM expenses
             INNER JOIN users ON users.id = expenses.user_id AND users.is_active = 1
             WHERE expenses.status = "pending"
               AND expenses.due_on BETWEEN :start AND :end
               ' . $userSql . '
             GROUP BY expenses.user_id
             HAVING expense_count > 0
             ORDER BY expenses.user_id ASC'
        );
        $statement->execute([
            'start' => $start,
            'end' => $end,
        ] + $userParams);

        return $statement->fetchAll();
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array<int, int>|null
     */
    private function onlyUserIds(?array $onlyUserIds): ?array
    {
        if ($onlyUserIds === null) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $onlyUserIds), static fn (int $id): bool => $id > 0)));

        return $ids === [] ? [0] : $ids;
    }

    /**
     * @param array<int, int>|null $onlyUserIds
     * @return array{0: string, 1: array<string, int>}
     */
    private function onlyUsersSql(?array $onlyUserIds): array
    {
        if ($onlyUserIds === null) {
            return ['', []];
        }

        $placeholders = [];
        $params = [];

        foreach ($onlyUserIds as $index => $userId) {
            $placeholder = 'only_user_' . $index;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $userId;
        }

        return ['AND expenses.user_id IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /**
     * @param array<string, mixed> $expense
     * @param array{title: string, message: ?string} $content
     * @param array<string, int> $summary
     */
    private function createExpenseNotification(
        array $expense,
        string $type,
        string $dedupeKey,
        array $content,
        string $nowUtc,
        string $counter,
        array &$summary,
    ): void {
        $this->createNotification(
            (int) $expense['user_id'],
            $type,
            self::ENTITY_EXPENSE,
            (int) $expense['id'],
            $dedupeKey,
            $content,
            $nowUtc,
            $counter,
            $summary,
        );
    }

    /**
     * @param array{title: string, message: ?string} $content
     * @param array<string, int> $summary
     */
    private function createGenericNotification(
        int $userId,
        string $type,
        string $dedupeKey,
        array $content,
        string $nowUtc,
        string $counter,
        array &$summary,
    ): void {
        $this->createNotification($userId, $type, null, null, $dedupeKey, $content, $nowUtc, $counter, $summary);
    }

    /**
     * @param array{title: string, message: ?string} $content
     * @param array<string, int> $summary
     */
    private function createNotification(
        int $userId,
        string $type,
        ?string $entityType,
        ?int $entityId,
        string $dedupeKey,
        array $content,
        string $nowUtc,
        string $counter,
        array &$summary,
    ): void {
        try {
            $created = $this->notifications->createActivity(
                $userId,
                $type,
                self::SOURCE_MODULE,
                $entityType,
                $entityId,
                $dedupeKey,
                $content['title'],
                $content['message'],
                $nowUtc,
            );

            if ($created) {
                $summary[$counter]++;
            } else {
                $summary['duplicates_skipped']++;
            }
        } catch (Throwable) {
            $summary['errors']++;
        }
    }
}
