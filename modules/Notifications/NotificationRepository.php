<?php
declare(strict_types=1);

namespace Modules\Notifications;

use PDO;
use PDOException;

final class NotificationRepository
{
    public const TYPE_REMINDER_DUE = 'reminder_due';
    public const TYPE_DAILY_AGENDA = 'daily_agenda';
    public const TYPE_TASK_DUE_TODAY = 'task_due_today';
    public const TYPE_TASK_DUE_SOON = 'task_due_soon';
    public const TYPE_TASK_STARTS_TODAY = 'task_starts_today';
    public const TYPE_TASK_OVERDUE = 'task_overdue';
    public const TYPE_PROJECT_SUMMARY = 'project_summary';
    public const TYPE_NOTE_DAILY_REMINDER = 'note_daily_reminder';
    public const TYPE_VIDEO_EXPORT_COMPLETED = 'video_export_completed';
    public const TYPE_VIDEO_EXPORT_FAILED = 'video_export_failed';
    public const TYPE_EXPENSE_DUE_SOON = 'expense_due_soon';
    public const TYPE_EXPENSE_DUE_TODAY = 'expense_due_today';
    public const TYPE_EXPENSE_OVERDUE = 'expense_overdue';
    public const TYPE_EXPENSE_MISSING_AMOUNT = 'expense_missing_amount';
    public const TYPE_EXPENSE_WEEKLY_SUMMARY = 'expense_weekly_summary';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createReminderDue(
        int $userId,
        int $reminderId,
        string $title,
        ?string $message,
        string $scheduledAt,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications
                (user_id, reminder_id, type, source_module, entity_type, entity_id, dedupe_key, title, message, scheduled_at)
             VALUES
                (:user_id, :reminder_id, :type, :source_module, :entity_type, :entity_id, :dedupe_key, :title, :message, :scheduled_at)'
        );

        try {
            $statement->execute([
                'user_id' => $userId,
                'reminder_id' => $reminderId,
                'type' => self::TYPE_REMINDER_DUE,
                'source_module' => 'reminders',
                'entity_type' => 'reminder',
                'entity_id' => $reminderId,
                'dedupe_key' => 'reminder_due:' . $reminderId . ':' . $scheduledAt,
                'title' => $title,
                'message' => $message,
                'scheduled_at' => $scheduledAt,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function createActivity(
        int $userId,
        string $type,
        string $sourceModule,
        ?string $entityType,
        ?int $entityId,
        string $dedupeKey,
        string $title,
        ?string $message,
        string $scheduledAt,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications
                (user_id, reminder_id, type, source_module, entity_type, entity_id, dedupe_key, title, message, scheduled_at)
             VALUES
                (:user_id, NULL, :type, :source_module, :entity_type, :entity_id, :dedupe_key, :title, :message, :scheduled_at)'
        );

        try {
            $statement->execute([
                'user_id' => $userId,
                'type' => $type,
                'source_module' => $sourceModule,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'dedupe_key' => $dedupeKey,
                'title' => substr($title, 0, 180),
                'message' => $message,
                'scheduled_at' => $scheduledAt,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['n.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (($filters['status'] ?? null) === 'unread') {
            $where[] = 'n.read_at IS NULL';
        } elseif (($filters['status'] ?? null) === 'read') {
            $where[] = 'n.read_at IS NOT NULL';
        }

        $limit = isset($filters['limit']) ? max(1, min(50, (int) $filters['limit'])) : null;
        $sql = $this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY n.created_at DESC, n.scheduled_at DESC, n.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function unreadCountForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function markRead(int $userId, int $notificationId, string $readAtUtc): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, :read_at)
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $notificationId,
            'user_id' => $userId,
            'read_at' => $readAtUtc,
        ]);

        return $statement->rowCount() > 0;
    }

    public function markAllRead(int $userId, string $readAtUtc): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = :read_at
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'read_at' => $readAtUtc,
        ]);

        return $statement->rowCount();
    }

    private function selectSql(): string
    {
        return 'SELECT n.id, n.user_id, n.reminder_id, n.type, n.source_module, n.entity_type, n.entity_id, n.dedupe_key, n.title, n.message,
                    n.scheduled_at, n.read_at, n.created_at,
                    r.task_id AS reminder_task_id,
                    r.project_id AS reminder_project_id,
                    t.title AS task_title,
                    t.project_id AS task_project_id,
                    p.title AS project_title,
                    nt.title AS entity_task_title,
                    nt.project_id AS entity_task_project_id,
                    np.title AS entity_project_title,
                    nn.title AS entity_note_title,
                    vej.output_name AS video_export_name,
                    vej.status AS video_export_status,
                    e.period_month AS expense_period_month,
                    e.description AS expense_description,
                    e.status AS expense_status
             FROM notifications n
             LEFT JOIN organization_reminders r ON r.id = n.reminder_id AND r.user_id = n.user_id
             LEFT JOIN organization_tasks t ON t.id = r.task_id AND t.user_id = n.user_id
             LEFT JOIN organization_projects p ON p.id = r.project_id AND p.user_id = n.user_id
             LEFT JOIN organization_tasks nt ON n.entity_type = \'task\' AND nt.id = n.entity_id AND nt.user_id = n.user_id
             LEFT JOIN organization_projects np ON n.entity_type = \'project\' AND np.id = n.entity_id AND np.user_id = n.user_id
             LEFT JOIN organization_notes nn ON n.entity_type = \'note\' AND nn.id = n.entity_id AND nn.user_id = n.user_id
             LEFT JOIN video_export_jobs vej ON n.entity_type = \'video_export\' AND vej.id = n.entity_id AND vej.user_id = n.user_id
             LEFT JOIN expenses e ON n.entity_type = \'expense\' AND e.id = n.entity_id AND e.user_id = n.user_id';
    }
}
