<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Organization\CalendarRepository;
use Modules\Organization\CalendarService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$config = require dirname(__DIR__, 2) . '/config/app.php';
$pdo = Connection::get();
$taskRepository = new TaskRepository($pdo);
$reminderRepository = new ReminderRepository($pdo);
$taskService = new TaskService($taskRepository, DateTimeHelper::DEFAULT_TIMEZONE);
$reminderService = new ReminderService($reminderRepository, DateTimeHelper::DEFAULT_TIMEZONE);
$calendarService = new CalendarService(new CalendarRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
$username = 'test_timezone_' . bin2hex(random_bytes(4));
$exitCode = 1;

function timezone_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function timezone_user(PDO $pdo, string $username): int
{
    $hash = password_hash('test-secret-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);

    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash password.');
    }

    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);

    return (int) $pdo->lastInsertId();
}

function timezone_scalar(PDO $pdo, string $sql, array $params): string
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();

    return is_string($value) ? $value : '';
}

function timezone_worker_run(): array
{
    $basePath = dirname(__DIR__, 2);
    $command = 'cd ' . escapeshellarg($basePath) . ' && php workers/process-reminders.php 2>&1';
    $output = [];
    $code = 1;

    exec($command, $output, $code);

    return [
        'code' => $code,
        'output' => implode("\n", $output),
    ];
}

try {
    $userId = timezone_user($pdo, $username);

    $task = $taskService->create($userId, [
        'title' => 'TZ tarea exacta 18:20',
        'starts_at' => '2026-08-08T18:20',
        'due_at' => '2026-08-08T18:20',
    ]);
    timezone_assert($task['starts_at'] === '2026-08-08 22:20:00', 'TaskService did not store Santiago input as UTC.');
    timezone_assert($task['starts_at_local'] === '2026-08-08 18:20:00', 'TaskService did not expose local Santiago time.');
    timezone_assert($task['starts_at_input'] === '2026-08-08T18:20', 'TaskService did not expose datetime-local value.');
    timezone_assert(
        timezone_scalar($pdo, 'SELECT starts_at FROM organization_tasks WHERE id = :id', ['id' => (int) $task['id']]) === '2026-08-08 22:20:00',
        'Task raw database value is not UTC.'
    );

    $crossingDay = $taskService->create($userId, [
        'title' => 'TZ cruza dia UTC',
        'starts_at' => '2026-08-08T23:30',
    ]);
    timezone_assert($crossingDay['starts_at'] === '2026-08-09 03:30:00', 'Task UTC conversion did not cross day correctly.');
    timezone_assert($crossingDay['starts_at_local'] === '2026-08-08 23:30:00', 'Cross-day task did not return to original local day.');

    $calendar = $calendarService->view($userId, 'month', '2026-08-08');
    $calendarDay = json_encode($calendar['items_by_date']['2026-08-08'] ?? [], JSON_THROW_ON_ERROR);
    timezone_assert(str_contains($calendarDay, '18:20 TZ tarea exacta 18:20'), 'Calendar did not show task in Santiago time.');
    timezone_assert(str_contains($calendarDay, '18:20 Vence: TZ tarea exacta 18:20'), 'Calendar did not show due_at in Santiago time.');

    $reminder = $reminderService->create($userId, [
        'title' => 'TZ recordatorio exacto 18:20',
        'remind_at' => '2026-08-08T18:20',
    ]);
    timezone_assert($reminder['remind_at'] === '2026-08-08 22:20:00', 'ReminderService did not store Santiago input as UTC.');
    timezone_assert($reminder['remind_at_local'] === '2026-08-08 18:20:00', 'ReminderService did not expose local Santiago time.');
    timezone_assert($reminder['next_remind_at'] === '2026-08-08 22:20:00', 'Reminder next_remind_at did not use UTC occurrence.');

    $todayLocal = DateTimeHelper::nowLocal(DateTimeHelper::DEFAULT_TIMEZONE)->format('Y-m-d');
    $taskService->create($userId, [
        'title' => 'TZ dashboard hoy 18:20',
        'starts_at' => $todayLocal . 'T18:20',
    ]);
    $reminderService->create($userId, [
        'title' => 'TZ dashboard recordatorio 18:20',
        'remind_at' => $todayLocal . 'T18:20',
    ]);
    $summary = (new DashboardSummaryService($config, $taskService, $userId, $reminderService))->summary();
    $today = json_encode($summary['sections']['today']['items'], JSON_THROW_ON_ERROR);
    timezone_assert(str_contains($today, '18:20 TZ dashboard hoy 18:20'), 'Dashboard did not show task in Santiago time.');
    timezone_assert(str_contains($today, '18:20 Recordatorio: TZ dashboard recordatorio 18:20'), 'Dashboard did not show reminder in Santiago time.');

    $dueReminder = $reminderService->create($userId, [
        'title' => 'TZ worker vencido',
        'remind_at' => DateTimeHelper::nowLocal(DateTimeHelper::DEFAULT_TIMEZONE)->modify('-10 minutes')->format('Y-m-d H:i'),
    ]);
    $workerRun = timezone_worker_run();
    timezone_assert($workerRun['code'] === 0, 'Worker did not execute successfully.');
    $scheduledAt = timezone_scalar(
        $pdo,
        'SELECT scheduled_at FROM notifications WHERE reminder_id = :reminder_id AND type = :type LIMIT 1',
        ['reminder_id' => (int) $dueReminder['id'], 'type' => 'reminder_due']
    );
    timezone_assert($scheduledAt === $dueReminder['remind_at'], 'Worker notification scheduled_at did not preserve UTC occurrence.');

    echo "Timezone policy: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Timezone policy: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
