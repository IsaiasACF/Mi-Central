<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class TaskRepository
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
            'INSERT INTO organization_tasks
                (user_id, space_id, category_id, project_id, parent_task_id, title, description, status, priority, starts_at, ends_at, due_at, completed_at, position)
             VALUES
                (:user_id, :space_id, :category_id, :project_id, :parent_task_id, :title, :description, :status, :priority, :starts_at, :ends_at, :due_at, :completed_at, :position)'
        );
        $statement->execute([
            'user_id' => $userId,
            'space_id' => $data['space_id'],
            'category_id' => $data['category_id'],
            'project_id' => $data['project_id'],
            'parent_task_id' => $data['parent_task_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => $data['status'],
            'priority' => $data['priority'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'due_at' => $data['due_at'],
            'completed_at' => $data['completed_at'],
            'position' => $data['position'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $taskId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, category_id, project_id, parent_task_id, title, description,
                    status, priority, starts_at, ends_at, due_at, completed_at, position, created_at, updated_at
             FROM organization_tasks
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $taskId,
            'user_id' => $userId,
        ]);

        $task = $statement->fetch();

        return is_array($task) ? $task : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        foreach (['space_id', 'project_id', 'parent_task_id', 'status'] as $field) {
            if (!array_key_exists($field, $filters)) {
                continue;
            }

            $where[] = "{$field} = :{$field}";
            $params[$field] = $filters[$field];
        }

        if (!empty($filters['space_id_is_null'])) {
            $where[] = 'space_id IS NULL';
        }

        if (!empty($filters['project_id_is_null'])) {
            $where[] = 'project_id IS NULL';
        }

        if (!empty($filters['parent_task_id_is_null'])) {
            $where[] = 'parent_task_id IS NULL';
        }

        if (isset($filters['due_from'])) {
            $where[] = 'due_at >= :due_from';
            $params['due_from'] = $filters['due_from'];
        }

        if (isset($filters['due_to'])) {
            $where[] = 'due_at <= :due_to';
            $params['due_to'] = $filters['due_to'];
        }

        if (isset($filters['due_before'])) {
            $where[] = 'due_at < :due_before';
            $params['due_before'] = $filters['due_before'];
        }

        if (isset($filters['label_id'])) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM organization_task_labels task_labels
                WHERE task_labels.task_id = organization_tasks.id
                  AND task_labels.user_id = organization_tasks.user_id
                  AND task_labels.label_id = :label_id
            )';
            $params['label_id'] = $filters['label_id'];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, category_id, project_id, parent_task_id, title, description,
                    status, priority, starts_at, ends_at, due_at, completed_at, position, created_at, updated_at
             FROM organization_tasks
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY position ASC, due_at ASC, created_at ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $taskId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $allowedFields = [
            'space_id',
            'category_id',
            'project_id',
            'parent_task_id',
            'title',
            'description',
            'status',
            'priority',
            'starts_at',
            'ends_at',
            'due_at',
            'completed_at',
            'position',
        ];

        $sets = [];
        $params = [
            'id' => $taskId,
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
            'UPDATE organization_tasks
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $taskId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM organization_tasks WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $taskId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function nextPosition(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM organization_tasks WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function spaceBelongsToUser(int $userId, int $spaceId): bool
    {
        return $this->existsForUser('organization_spaces', $userId, $spaceId);
    }

    public function categoryBelongsToUser(int $userId, int $categoryId): bool
    {
        return $this->existsForUser('organization_categories', $userId, $categoryId);
    }

    public function projectBelongsToUser(int $userId, int $projectId): bool
    {
        return $this->existsForUser('organization_projects', $userId, $projectId);
    }

    public function projectSpaceIdForUser(int $userId, int $projectId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT space_id FROM organization_projects WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        $spaceId = $statement->fetchColumn();

        return $spaceId === false || $spaceId === null ? null : (int) $spaceId;
    }

    public function taskBelongsToUser(int $userId, int $taskId): bool
    {
        return $this->existsForUser('organization_tasks', $userId, $taskId);
    }

    public function findParentTaskIdForUser(int $userId, int $taskId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT parent_task_id FROM organization_tasks WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $taskId,
            'user_id' => $userId,
        ]);

        $parentId = $statement->fetchColumn();

        return $parentId === false || $parentId === null ? null : (int) $parentId;
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
}
