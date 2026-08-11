<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class ReminderRepository
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
            'INSERT INTO organization_reminders
                (user_id, task_id, project_id, title, description, remind_at, status,
                 recurrence_type, recurrence_interval, recurrence_until, next_remind_at)
             VALUES
                (:user_id, :task_id, :project_id, :title, :description, :remind_at, :status,
                 :recurrence_type, :recurrence_interval, :recurrence_until, :next_remind_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'task_id' => $data['task_id'],
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'remind_at' => $data['remind_at'],
            'status' => $data['status'],
            'recurrence_type' => $data['recurrence_type'],
            'recurrence_interval' => $data['recurrence_interval'],
            'recurrence_until' => $data['recurrence_until'],
            'next_remind_at' => $data['next_remind_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $reminderId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE r.id = :id AND r.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        $reminder = $statement->fetch();

        return is_array($reminder) ? $reminder : null;
    }

    /**
     * @return array<int, int>
     */
    public function dueIdsForProcessing(string $nowUtc, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM organization_reminders
             WHERE status = :status
               AND COALESCE(next_remind_at, remind_at) <= :now_utc
             ORDER BY COALESCE(next_remind_at, remind_at) ASC, id ASC
             LIMIT ' . $limit
        );
        $statement->execute([
            'status' => 'pending',
            'now_utc' => $nowUtc,
        ]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDueForProcessing(int $reminderId, string $nowUtc): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE r.id = :id
               AND r.status = :status
               AND COALESCE(r.next_remind_at, r.remind_at) <= :now_utc
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'id' => $reminderId,
            'status' => 'pending',
            'now_utc' => $nowUtc,
        ]);

        $reminder = $statement->fetch();

        return is_array($reminder) ? $reminder : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['r.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (isset($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['done_statuses'])) {
            $where[] = "r.status IN ('completed', 'dismissed')";
        }

        if (isset($filters['remind_from'])) {
            $where[] = 'COALESCE(r.next_remind_at, r.remind_at) >= :remind_from';
            $params['remind_from'] = $filters['remind_from'];
        }

        if (isset($filters['remind_before'])) {
            $where[] = 'COALESCE(r.next_remind_at, r.remind_at) < :remind_before';
            $params['remind_before'] = $filters['remind_before'];
        }

        if (isset($filters['limit'])) {
            $limit = max(1, min(50, (int) $filters['limit']));
        } else {
            $limit = null;
        }

        $sql = $this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(r.next_remind_at, r.remind_at) ASC, r.created_at ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $reminderId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $allowedFields = [
            'task_id',
            'project_id',
            'title',
            'description',
            'remind_at',
            'status',
            'recurrence_type',
            'recurrence_interval',
            'recurrence_until',
            'next_remind_at',
        ];
        $sets = [];
        $params = [
            'id' => $reminderId,
            'user_id' => $userId,
        ];

        foreach ($allowedFields as $field) {
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
            'UPDATE organization_reminders
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $reminderId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM organization_reminders WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function taskBelongsToUser(int $userId, int $taskId): bool
    {
        return $this->existsForUser('organization_tasks', $userId, $taskId);
    }

    public function projectBelongsToUser(int $userId, int $projectId): bool
    {
        return $this->existsForUser('organization_projects', $userId, $projectId);
    }

    private function existsForUser(string $table, int $userId, int $id): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM {$table} WHERE id = :id AND user_id = :user_id LIMIT 1"
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function selectSql(): string
    {
        return 'SELECT r.id, r.user_id, r.task_id, r.project_id, r.title, r.description,
                    r.remind_at, r.status, r.recurrence_type, r.recurrence_interval,
                    r.recurrence_until, r.next_remind_at, r.created_at, r.updated_at,
                    t.title AS task_title,
                    t.project_id AS task_project_id,
                    p.title AS project_title
             FROM organization_reminders r
             LEFT JOIN organization_tasks t ON t.id = r.task_id AND t.user_id = r.user_id
             LEFT JOIN organization_projects p ON p.id = r.project_id AND p.user_id = r.user_id';
    }
}
