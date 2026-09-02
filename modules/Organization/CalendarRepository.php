<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class CalendarRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tasksInRange(int $userId, string $rangeStartUtc, string $rangeEndUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, project_id, parent_task_id, title, status,
                    starts_at, ends_at, due_at
             FROM organization_tasks
             WHERE user_id = :user_id
               AND (
                    (starts_at IS NOT NULL AND starts_at < :range_end_scheduled AND COALESCE(ends_at, starts_at) >= :range_start_scheduled)
                    OR (due_at IS NOT NULL AND due_at >= :range_start_due AND due_at < :range_end_due)
               )
             ORDER BY COALESCE(starts_at, due_at) ASC, due_at ASC, title ASC'
        );
        $statement->execute([
            'user_id' => $userId,
            'range_start_scheduled' => $rangeStartUtc,
            'range_end_scheduled' => $rangeEndUtc,
            'range_start_due' => $rangeStartUtc,
            'range_end_due' => $rangeEndUtc,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function projectsInRange(int $userId, string $rangeStartDate, string $rangeEndDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, title, status, starts_on, due_on
             FROM organization_projects
             WHERE user_id = :user_id
               AND (starts_on IS NOT NULL OR due_on IS NOT NULL)
               AND COALESCE(starts_on, due_on) <= :range_end_start
               AND COALESCE(due_on, starts_on) >= :range_start_end
             ORDER BY COALESCE(starts_on, due_on) ASC, title ASC'
        );
        $statement->execute([
            'user_id' => $userId,
            'range_start_end' => $rangeStartDate,
            'range_end_start' => $rangeEndDate,
        ]);

        return $statement->fetchAll();
    }
}
