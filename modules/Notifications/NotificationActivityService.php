<?php
declare(strict_types=1);

namespace Modules\Notifications;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use PDO;

final class NotificationActivityService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly NotificationRepository $notifications,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function process(?DateTimeImmutable $nowLocal = null): array
    {
        $nowLocal ??= DateTimeHelper::nowLocal($this->timezone);
        $today = $nowLocal->format('Y-m-d');
        $startUtc = $nowLocal->setTime(0, 0, 0)->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $endUtc = $nowLocal->setTime(23, 59, 59)->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $nowUtc = $nowLocal->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $summary = [
            'daily_agenda' => 0,
            'tasks' => 0,
            'notes' => 0,
            'projects' => 0,
            'video' => 0,
        ];

        foreach ($this->activeUserIds() as $userId) {
            $userSummary = $this->processUser($userId, $nowLocal);
            $summary['daily_agenda'] += $userSummary['daily_agenda'];
            $summary['tasks'] += $userSummary['tasks'];
            $summary['notes'] += $userSummary['notes'];
            $summary['projects'] += $userSummary['projects'];
        }

        $summary['video'] += $this->createVideoNotifications($nowUtc);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function processUser(int $userId, ?DateTimeImmutable $nowLocal = null): array
    {
        $nowLocal ??= DateTimeHelper::nowLocal($this->timezone);
        $today = $nowLocal->format('Y-m-d');
        $startUtc = $nowLocal->setTime(0, 0, 0)->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $endUtc = $nowLocal->setTime(23, 59, 59)->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $nowUtc = $nowLocal->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
        $summary = [
            'daily_agenda' => 0,
            'tasks' => 0,
            'notes' => 0,
            'projects' => 0,
        ];

        if ($this->createDailyAgenda($userId, $today, $nowUtc, $startUtc, $endUtc)) {
            $summary['daily_agenda']++;
        }

        $summary['tasks'] += $this->createTaskNotifications($userId, $today, $nowLocal, $nowUtc, $startUtc, $endUtc);
        $summary['notes'] += $this->createNoteNotifications($userId, $today, $nowUtc);
        $summary['projects'] += $this->createProjectNotifications($userId, $today, $nowUtc);

        return $summary;
    }

    /**
     * @return array<int, int>
     */
    private function activeUserIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC');

        return $statement === false ? [] : array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function createDailyAgenda(int $userId, string $today, string $nowUtc, string $startUtc, string $endUtc): bool
    {
        $tasksToday = $this->countScalar(
            'SELECT COUNT(*) FROM organization_tasks
             WHERE user_id = :user_id
               AND status = "pending"
               AND (
                   (due_at IS NOT NULL AND due_at BETWEEN :due_start_utc AND :due_end_utc)
                   OR (starts_at IS NOT NULL AND starts_at BETWEEN :starts_start_utc AND :starts_end_utc)
               )',
            [
                'user_id' => $userId,
                'due_start_utc' => $startUtc,
                'due_end_utc' => $endUtc,
                'starts_start_utc' => $startUtc,
                'starts_end_utc' => $endUtc,
            ],
        );
        $remindersToday = $this->countScalar(
            'SELECT COUNT(*) FROM organization_reminders
             WHERE user_id = :user_id
               AND status = "pending"
               AND COALESCE(next_remind_at, remind_at) BETWEEN :start_utc AND :end_utc',
            ['user_id' => $userId, 'start_utc' => $startUtc, 'end_utc' => $endUtc],
        );
        $activeNotes = $this->countScalar(
            'SELECT COUNT(*) FROM organization_notes
             WHERE user_id = :user_id
               AND status = "active"',
            ['user_id' => $userId],
        );
        $project = $this->featuredProject($userId);
        $parts = [];

        if ($tasksToday > 0) {
            $parts[] = $tasksToday . ($tasksToday === 1 ? ' tarea para hoy' : ' tareas para hoy');
        }

        if ($remindersToday > 0) {
            $parts[] = $remindersToday . ($remindersToday === 1 ? ' recordatorio' : ' recordatorios');
        }

        if ($activeNotes > 0) {
            $parts[] = $activeNotes . ($activeNotes === 1 ? ' nota activa' : ' notas activas');
        }

        if ($project !== null) {
            $parts[] = 'Proyecto ' . (string) $project['title'] . ' al ' . (int) $project['progress_percent'] . '%';
        }

        $message = $parts === [] ? 'No hay pendientes fuertes para hoy.' : implode(' · ', $parts);

        return $this->notifications->createActivity(
            $userId,
            NotificationRepository::TYPE_DAILY_AGENDA,
            'activity',
            null,
            null,
            'daily_agenda:' . $userId . ':' . $today,
            'Tu dia',
            $message,
            $nowUtc,
        );
    }

    private function createTaskNotifications(int $userId, string $today, DateTimeImmutable $nowLocal, string $nowUtc, string $startUtc, string $endUtc): int
    {
        $created = 0;
        $notifiedTaskIds = [];
        $soonEndUtc = $nowLocal
            ->modify('+3 days')
            ->setTime(23, 59, 59)
            ->setTimezone(DateTimeHelper::utcTimezone())
            ->format('Y-m-d H:i:s');

        foreach ($this->taskRows($userId, 'COALESCE(due_at, ends_at) < :start_utc', ['start_utc' => $startUtc]) as $task) {
            $taskId = (int) $task['id'];
            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_TASK_OVERDUE,
                'organization',
                'task',
                $taskId,
                'task_overdue:' . $taskId . ':' . $today,
                'Tarea vencida pendiente',
                (string) $task['title'],
                $nowUtc,
            ) ? 1 : 0;
            $notifiedTaskIds[$taskId] = true;
        }

        foreach ($this->taskRows($userId, 'COALESCE(due_at, ends_at) BETWEEN :start_utc AND :end_utc', ['start_utc' => $startUtc, 'end_utc' => $endUtc]) as $task) {
            $taskId = (int) $task['id'];
            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_TASK_DUE_TODAY,
                'organization',
                'task',
                $taskId,
                'task_due_today:' . $taskId . ':' . $today,
                'Tarea vence hoy',
                (string) $task['title'],
                $nowUtc,
            ) ? 1 : 0;
            $notifiedTaskIds[$taskId] = true;
        }

        foreach ($this->taskRows($userId, 'COALESCE(due_at, ends_at) > :end_utc AND COALESCE(due_at, ends_at) <= :soon_end_utc', ['end_utc' => $endUtc, 'soon_end_utc' => $soonEndUtc]) as $task) {
            $taskId = (int) $task['id'];
            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_TASK_DUE_SOON,
                'organization',
                'task',
                $taskId,
                'task_due_soon:' . $taskId . ':' . $today,
                'Tarea por vencer',
                (string) $task['title'],
                $nowUtc,
            ) ? 1 : 0;
            $notifiedTaskIds[$taskId] = true;
        }

        foreach ($this->taskRows($userId, 'starts_at BETWEEN :start_utc AND :end_utc', ['start_utc' => $startUtc, 'end_utc' => $endUtc]) as $task) {
            $taskId = (int) $task['id'];

            if (isset($notifiedTaskIds[$taskId])) {
                continue;
            }

            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_TASK_STARTS_TODAY,
                'organization',
                'task',
                $taskId,
                'task_starts_today:' . $taskId . ':' . $today,
                'Tarea comienza hoy',
                (string) $task['title'],
                $nowUtc,
            ) ? 1 : 0;
            $notifiedTaskIds[$taskId] = true;
        }

        return $created;
    }

    private function createNoteNotifications(int $userId, string $today, string $nowUtc): int
    {
        $created = 0;
        $statement = $this->pdo->prepare(
            'SELECT id, title
             FROM organization_notes
             WHERE user_id = :user_id
               AND status = "active"
             ORDER BY updated_at DESC, id DESC
             LIMIT 100'
        );
        $statement->execute(['user_id' => $userId]);

        foreach ($statement->fetchAll() as $note) {
            $noteId = (int) $note['id'];
            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_NOTE_DAILY_REMINDER,
                'organization',
                'note',
                $noteId,
                'note_daily_reminder:' . $noteId . ':' . $today,
                'Nota activa',
                (string) $note['title'],
                $nowUtc,
            ) ? 1 : 0;
        }

        return $created;
    }

    private function createProjectNotifications(int $userId, string $today, string $nowUtc): int
    {
        $created = 0;
        $limit = (new DateTimeImmutable($today, DateTimeHelper::timezone($this->timezone)))->modify('+3 days')->format('Y-m-d');
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.title, p.due_on,
                    COUNT(t.id) AS tasks_total,
                    COALESCE(SUM(CASE WHEN t.status = "completed" THEN 1 ELSE 0 END), 0) AS tasks_completed
             FROM organization_projects p
             LEFT JOIN organization_tasks t ON t.project_id = p.id AND t.user_id = p.user_id
             WHERE p.user_id = :user_id
               AND p.status = "active"
               AND p.due_on IS NOT NULL
               AND p.due_on BETWEEN :today AND :limit_day
             GROUP BY p.id, p.title, p.due_on
             ORDER BY p.due_on ASC, p.id ASC'
        );
        $statement->execute(['user_id' => $userId, 'today' => $today, 'limit_day' => $limit]);

        foreach ($statement->fetchAll() as $project) {
            $total = (int) ($project['tasks_total'] ?? 0);
            $completed = (int) ($project['tasks_completed'] ?? 0);
            $progress = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;
            $created += $this->notifications->createActivity(
                $userId,
                NotificationRepository::TYPE_PROJECT_SUMMARY,
                'organization',
                'project',
                (int) $project['id'],
                'project_summary:' . (int) $project['id'] . ':' . $today,
                'Proyecto activo',
                (string) $project['title'] . ': ' . $completed . ' de ' . $total . ' tareas completadas · ' . $progress . '%',
                $nowUtc,
            ) ? 1 : 0;
        }

        return $created;
    }

    private function createVideoNotifications(string $nowUtc): int
    {
        $created = 0;
        $exports = $this->pdo->query(
            'SELECT id, user_id, status, output_name, error_message, completed_at
             FROM video_export_jobs
             WHERE status IN ("completed", "failed")
               AND completed_at IS NOT NULL
             ORDER BY completed_at DESC
             LIMIT 200'
        );

        if ($exports !== false) {
            foreach ($exports->fetchAll() as $job) {
                $status = (string) $job['status'];
                $created += $this->notifications->createActivity(
                    (int) $job['user_id'],
                    $status === 'completed' ? NotificationRepository::TYPE_VIDEO_EXPORT_COMPLETED : NotificationRepository::TYPE_VIDEO_EXPORT_FAILED,
                    'video',
                    'video_export',
                    (int) $job['id'],
                    'video_export:' . (int) $job['id'] . ':' . $status,
                    $status === 'completed' ? 'Exportacion completada' : 'Exportacion fallida',
                    $status === 'completed' ? (string) $job['output_name'] : ((string) ($job['error_message'] ?? '') ?: (string) $job['output_name']),
                    $nowUtc,
                ) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * @param array<string, string> $params
     * @return array<int, array<string, mixed>>
     */
    private function taskRows(int $userId, string $condition, array $params): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, project_id
             FROM organization_tasks
             WHERE user_id = :user_id
               AND status = "pending"
               AND ' . $condition . '
             ORDER BY COALESCE(due_at, ends_at, starts_at) ASC, id ASC
             LIMIT 100'
        );
        $statement->execute(['user_id' => $userId] + $params);

        return $statement->fetchAll();
    }

    private function featuredProject(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.title,
                    COUNT(t.id) AS tasks_total,
                    COALESCE(SUM(CASE WHEN t.status = "completed" THEN 1 ELSE 0 END), 0) AS tasks_completed
             FROM organization_projects p
             LEFT JOIN organization_tasks t ON t.project_id = p.id AND t.user_id = p.user_id
             WHERE p.user_id = :user_id AND p.status = "active"
             GROUP BY p.id, p.title
             HAVING tasks_total > 0
             ORDER BY p.due_on IS NULL ASC, p.due_on ASC, p.id ASC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $project = $statement->fetch();

        if (!is_array($project)) {
            return null;
        }

        $total = (int) ($project['tasks_total'] ?? 0);
        $completed = (int) ($project['tasks_completed'] ?? 0);
        $project['progress_percent'] = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;

        return $project;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countScalar(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
