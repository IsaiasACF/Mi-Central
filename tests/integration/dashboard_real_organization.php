<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$config = require dirname(__DIR__, 2) . '/config/app.php';
$pdo = Connection::get();
$taskService = new TaskService(new TaskRepository($pdo));
$reminderService = new ReminderService(new ReminderRepository($pdo), 'America/Santiago');
$username = 'test_dashboard_real_' . bin2hex(random_bytes(4));
$exitCode = 1;

function dashboard_real_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dashboard_real_user(PDO $pdo, string $username): int
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

function dashboard_real_local(string $modifier): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('America/Santiago')))->format('Y-m-d H:i:s');
}

try {
    $userId = dashboard_real_user($pdo, $username);

    $taskService->create($userId, [
        'title' => 'Dashboard tarea programada hoy',
        'starts_at' => dashboard_real_local('today 10:00'),
    ]);
    $taskService->create($userId, [
        'title' => 'Dashboard vencimiento hoy',
        'due_at' => dashboard_real_local('today 18:00'),
    ]);
    $taskService->create($userId, [
        'title' => 'Dashboard proxima tarea',
        'due_at' => dashboard_real_local('+2 days 09:00'),
    ]);
    $taskService->create($userId, [
        'title' => 'Dashboard sin fecha invisible',
    ]);
    $reminderService->create($userId, [
        'title' => 'Dashboard recordatorio hoy',
        'remind_at' => (new DateTimeImmutable('today 20:00', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i'),
    ]);
    $reminderService->create($userId, [
        'title' => 'Dashboard recordatorio proximo',
        'remind_at' => (new DateTimeImmutable('+3 days 11:00', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i'),
    ]);
    $reminderService->create($userId, [
        'title' => 'Dashboard recordatorio recurrente',
        'remind_at' => (new DateTimeImmutable('today 21:00', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i'),
        'recurrence_type' => 'daily',
    ]);

    $summary = (new DashboardSummaryService($config, $taskService, $userId, $reminderService))->summary();
    $today = json_encode($summary['sections']['today']['items'], JSON_THROW_ON_ERROR);
    $upcoming = json_encode($summary['sections']['upcoming']['items'], JSON_THROW_ON_ERROR);

    dashboard_real_assert(str_contains($today, 'Dashboard tarea programada hoy'), 'Today widget did not receive scheduled task.');
    dashboard_real_assert(str_contains($today, 'Dashboard vencimiento hoy'), 'Today widget did not receive due task.');
    dashboard_real_assert(str_contains($today, 'Dashboard recordatorio hoy'), 'Today widget did not receive reminder.');
    dashboard_real_assert(substr_count($today . $upcoming, 'Dashboard recordatorio recurrente') === 1, 'Dashboard duplicated future recurring occurrences.');
    dashboard_real_assert(str_contains($upcoming, 'Dashboard proxima tarea'), 'Upcoming widget did not receive upcoming task.');
    dashboard_real_assert(str_contains($upcoming, 'Dashboard recordatorio proximo'), 'Upcoming widget did not receive upcoming reminder.');
    dashboard_real_assert(!str_contains($today . $upcoming, 'Dashboard sin fecha invisible'), 'Dashboard showed task without dates.');
    dashboard_real_assert(count($summary['sections']['today']['items']) <= 5, 'Today widget was not limited.');
    dashboard_real_assert(count($summary['sections']['upcoming']['items']) <= 5, 'Upcoming widget was not limited.');

    echo "Dashboard real organization: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Dashboard real organization: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
