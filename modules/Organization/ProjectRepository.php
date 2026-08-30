<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class ProjectRepository
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
            'INSERT INTO organization_projects
                (user_id, space_id, title, description, status, starts_on, due_on)
             VALUES
                (:user_id, :space_id, :title, :description, :status, :starts_on, :due_on)'
        );
        $statement->execute([
            'user_id' => $userId,
            'space_id' => $data['space_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => $data['status'],
            'starts_on' => $data['starts_on'],
            'due_on' => $data['due_on'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.space_id, p.title, p.description, p.status,
                    p.starts_on, p.due_on, p.created_at, p.updated_at,
                    COUNT(t.id) AS tasks_total,
                    COALESCE(SUM(CASE WHEN t.status = \'completed\' THEN 1 ELSE 0 END), 0) AS tasks_completed
             FROM organization_projects p
             LEFT JOIN organization_tasks t ON t.project_id = p.id AND t.user_id = p.user_id
             WHERE p.id = :id AND p.user_id = :user_id
             GROUP BY p.id, p.user_id, p.space_id, p.title, p.description, p.status,
                      p.starts_on, p.due_on, p.created_at, p.updated_at
             LIMIT 1'
        );
        $statement->execute([
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        $project = $statement->fetch();

        return is_array($project) ? $this->withProgress($project) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['p.user_id = :user_id'];
        $params = ['user_id' => $userId];

        foreach (['space_id', 'status'] as $field) {
            if (!array_key_exists($field, $filters)) {
                continue;
            }

            $where[] = "p.{$field} = :{$field}";
            $params[$field] = $filters[$field];
        }

        if (isset($filters['due_from'])) {
            $where[] = 'p.due_on >= :due_from';
            $params['due_from'] = $filters['due_from'];
        }

        if (isset($filters['due_before'])) {
            $where[] = 'p.due_on < :due_before';
            $params['due_before'] = $filters['due_before'];
        }

        if (isset($filters['label_id'])) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM organization_project_labels project_labels
                WHERE project_labels.project_id = p.id
                  AND project_labels.user_id = p.user_id
                  AND project_labels.label_id = :label_id
            )';
            $params['label_id'] = $filters['label_id'];
        }

        $statement = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.space_id, p.title, p.description, p.status,
                    p.starts_on, p.due_on, p.created_at, p.updated_at,
                    COUNT(t.id) AS tasks_total,
                    COALESCE(SUM(CASE WHEN t.status = \'completed\' THEN 1 ELSE 0 END), 0) AS tasks_completed
             FROM organization_projects p
             LEFT JOIN organization_tasks t ON t.project_id = p.id AND t.user_id = p.user_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id, p.user_id, p.space_id, p.title, p.description, p.status,
                      p.starts_on, p.due_on, p.created_at, p.updated_at
             ORDER BY p.due_on ASC, p.created_at ASC'
        );
        $statement->execute($params);

        return array_map(fn (array $project): array => $this->withProgress($project), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $projectId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $allowedFields = ['space_id', 'title', 'description', 'status', 'starts_on', 'due_on'];
        $sets = [];
        $params = [
            'id' => $projectId,
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
            'UPDATE organization_projects
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $projectId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM organization_projects WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function syncTaskSpacesForProject(int $userId, int $projectId, int $spaceId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE organization_tasks
             SET space_id = :space_id
             WHERE project_id = :project_id AND user_id = :user_id'
        );
        $statement->execute([
            'space_id' => $spaceId,
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    public function spaceBelongsToUser(int $userId, int $spaceId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM organization_spaces WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $spaceId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function withProgress(array $project): array
    {
        $total = (int) ($project['tasks_total'] ?? 0);
        $completed = (int) ($project['tasks_completed'] ?? 0);
        $project['tasks_total'] = $total;
        $project['tasks_completed'] = $completed;
        $project['progress_percent'] = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;

        return $project;
    }
}
