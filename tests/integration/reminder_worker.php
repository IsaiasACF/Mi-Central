<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$reminderService = new ReminderService(new ReminderRepository($pdo), 'America/Santiago');
$username = 'test_worker_' . bin2hex(random_bytes(4));
$otherUsername = 'test_worker_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$exitCode = 1;

function worker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function worker_local(string $modifier): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('America/Santiago')))->format('Y-m-d H:i');
}

function worker_run(): array
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

function worker_notification_count(PDO $pdo, int $reminderId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE reminder_id = :reminder_id');
    $statement->execute(['reminder_id' => $reminderId]);

    return (int) $statement->fetchColumn();
}

function worker_notification_count_for_user(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id');
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $nowUtc = gmdate('Y-m-d H:i:s');

    $future = $reminderService->create($userId, [
        'title' => 'Worker futuro',
        'remind_at' => worker_local('+1 day'),
    ]);
    $due = $reminderService->create($userId, [
        'title' => 'Worker vencido',
        'description' => 'Debe crear una notificacion',
        'remind_at' => worker_local('-10 minutes'),
    ]);
    $otherDue = $reminderService->create($otherUserId, [
        'title' => 'Worker otro usuario',
        'remind_at' => worker_local('-8 minutes'),
    ]);
    $recurring = $reminderService->create($userId, [
        'title' => 'Worker recurrente',
        'remind_at' => worker_local('-5 minutes'),
        'recurrence_type' => 'daily',
    ]);
    $missed = $reminderService->create($userId, [
        'title' => 'Worker recurrente atrasado',
        'remind_at' => worker_local('-3 days'),
        'recurrence_type' => 'daily',
    ]);
    $until = $reminderService->create($userId, [
        'title' => 'Worker recurrente con fin',
        'remind_at' => (new DateTimeImmutable('yesterday 09:00', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i'),
        'recurrence_type' => 'daily',
        'recurrence_until' => (new DateTimeImmutable('yesterday', new DateTimeZone('America/Santiago')))->format('Y-m-d'),
    ]);

    $firstRun = worker_run();
    worker_assert($firstRun['code'] === 0, 'Worker manual command failed.');
    worker_assert(str_contains($firstRun['output'], 'Processed:') && str_contains($firstRun['output'], 'Created notifications:'), 'Worker summary was not printed.');

    worker_assert(worker_notification_count($pdo, (int) $future['id']) === 0, 'Future reminder was processed.');
    worker_assert(worker_notification_count($pdo, (int) $due['id']) === 1, 'Due reminder did not create notification.');
    worker_assert(worker_notification_count($pdo, (int) $otherDue['id']) === 1, 'Other user reminder was not processed.');
    worker_assert(worker_notification_count_for_user($pdo, $userId) >= 4, 'User notifications were not created.');
    worker_assert(worker_notification_count_for_user($pdo, $otherUserId) === 1, 'Other user notification count was wrong.');

    $reloadedRecurring = $reminderService->get($userId, (int) $recurring['id']);
    worker_assert($reloadedRecurring !== null && $reloadedRecurring['status'] === 'pending', 'Recurring reminder was completed permanently.');
    worker_assert(is_string($reloadedRecurring['next_remind_at']) && $reloadedRecurring['next_remind_at'] > $nowUtc, 'Recurring reminder did not advance to a future occurrence.');

    $reloadedMissed = $reminderService->get($userId, (int) $missed['id']);
    worker_assert(worker_notification_count($pdo, (int) $missed['id']) === 1, 'Missed recurring reminder generated more than one notification.');
    worker_assert($reloadedMissed !== null && is_string($reloadedMissed['next_remind_at']) && $reloadedMissed['next_remind_at'] > $nowUtc, 'Missed recurring reminder did not skip to next future occurrence.');

    $reloadedUntil = $reminderService->get($userId, (int) $until['id']);
    worker_assert($reloadedUntil !== null && $reloadedUntil['status'] === 'completed' && $reloadedUntil['next_remind_at'] === null, 'Recurrence until did not finalize reminder.');

    $secondRun = worker_run();
    worker_assert($secondRun['code'] === 0, 'Second worker run failed.');
    worker_assert(worker_notification_count($pdo, (int) $due['id']) === 1, 'Repeated worker run duplicated a notification.');
    worker_assert(worker_notification_count($pdo, (int) $missed['id']) === 1, 'Repeated worker run duplicated missed recurrence notification.');

    echo "Reminder worker: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Reminder worker: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
