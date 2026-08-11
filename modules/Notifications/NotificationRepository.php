<?php
declare(strict_types=1);

namespace Modules\Notifications;

use PDO;
use PDOException;

final class NotificationRepository
{
    public const TYPE_REMINDER_DUE = 'reminder_due';

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
                (user_id, reminder_id, type, title, message, scheduled_at)
             VALUES
                (:user_id, :reminder_id, :type, :title, :message, :scheduled_at)'
        );

        try {
            $statement->execute([
                'user_id' => $userId,
                'reminder_id' => $reminderId,
                'type' => self::TYPE_REMINDER_DUE,
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
        return 'SELECT n.id, n.user_id, n.reminder_id, n.type, n.title, n.message,
                    n.scheduled_at, n.read_at, n.created_at,
                    r.task_id AS reminder_task_id,
                    r.project_id AS reminder_project_id,
                    t.title AS task_title,
                    t.project_id AS task_project_id,
                    p.title AS project_title
             FROM notifications n
             LEFT JOIN organization_reminders r ON r.id = n.reminder_id AND r.user_id = n.user_id
             LEFT JOIN organization_tasks t ON t.id = r.task_id AND t.user_id = n.user_id
             LEFT JOIN organization_projects p ON p.id = r.project_id AND p.user_id = n.user_id';
    }
}
