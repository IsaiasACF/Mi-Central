<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Organization\TaskValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$taskService = new TaskService(new TaskRepository($pdo));
$projectService = new ProjectService(new ProjectRepository($pdo));
$reminderService = new ReminderService(new ReminderRepository($pdo), 'America/Santiago');
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_reminders_' . bin2hex(random_bytes(4));
$otherUsername = 'test_reminders_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-reminders-');
$exitCode = 1;

function reminders_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $headers[] = 'Content-Type: application/json';
    }

    if ($headers !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    $json = json_decode($responseBody, true);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
    ];
}

function reminders_form_request(string $url, array $postFields, string $cookieFile): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function reminders_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function reminders_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function reminders_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reminders_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (TaskValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function reminders_utc(string $local): string
{
    return (new DateTimeImmutable($local, new DateTimeZone('America/Santiago')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaceId = (int) $spaceRepository->listForUser($userId)[0]['id'];
    $otherSpaceId = (int) $spaceRepository->listForUser($otherUserId)[0]['id'];
    $task = $taskService->create($userId, [
        'title' => 'Tarea con recordatorio',
        'due_at' => '2026-08-25 22:00:00',
    ]);
    $otherTask = $taskService->create($otherUserId, [
        'title' => 'Tarea ajena',
    ]);
    $project = $projectService->create($userId, [
        'title' => 'Proyecto con recordatorio',
        'space_id' => $spaceId,
        'starts_on' => '2026-08-20',
        'due_on' => '2026-08-30',
    ]);
    $otherProject = $projectService->create($otherUserId, [
        'title' => 'Proyecto ajeno',
        'space_id' => $otherSpaceId,
    ]);

    $independent = $reminderService->create($userId, [
        'title' => 'Recordatorio independiente',
        'description' => 'Preparar materiales',
        'remind_at' => '2026-08-24 20:00',
    ]);
    reminders_assert($independent['target_type'] === 'none', 'Independent reminder got a target.');
    reminders_assert($independent['remind_at'] === '2026-08-25 00:00:00', 'Reminder was not stored in UTC.');
    reminders_assert(($independent['recurrence_type'] ?? null) === 'none', 'Non-recurring reminder did not default to none.');
    reminders_assert($independent['next_remind_at'] === $independent['remind_at'], 'Non-recurring reminder did not keep next_remind_at as remind_at.');

    $taskReminder = $reminderService->create($userId, [
        'title' => 'Recordatorio de tarea',
        'task_id' => (string) $task['id'],
        'remind_date' => '2026-08-24',
        'remind_time' => '21:00',
    ]);
    reminders_assert((int) $taskReminder['task_id'] === (int) $task['id'], 'Task reminder did not keep task_id.');

    $projectReminder = $reminderService->create($userId, [
        'title' => 'Recordatorio de proyecto',
        'project_id' => (string) $project['id'],
        'remind_at' => '2026-08-24 22:00',
    ]);
    reminders_assert((int) $projectReminder['project_id'] === (int) $project['id'], 'Project reminder did not keep project_id.');

    reminders_assert($reminderService->get($otherUserId, (int) $independent['id']) === null, 'Another user could read a reminder.');
    reminders_reject(
        fn () => $reminderService->create($userId, ['title' => 'Ajeno', 'task_id' => (string) $otherTask['id'], 'remind_at' => '2026-08-24 20:00']),
        'Reminder accepted another user task.'
    );
    reminders_reject(
        fn () => $reminderService->create($userId, ['title' => 'Ajeno', 'project_id' => (string) $otherProject['id'], 'remind_at' => '2026-08-24 20:00']),
        'Reminder accepted another user project.'
    );
    reminders_reject(
        fn () => $reminderService->create($userId, ['title' => 'Doble relacion', 'task_id' => (string) $task['id'], 'project_id' => (string) $project['id'], 'remind_at' => '2026-08-24 20:00']),
        'Reminder accepted task_id and project_id simultaneously.'
    );

    $updated = $reminderService->update($userId, (int) $independent['id'], [
        'title' => 'Recordatorio editado',
        'description' => '',
        'remind_at' => '2026-08-24 21:30',
    ]);
    reminders_assert($updated !== null && $updated['title'] === 'Recordatorio editado' && $updated['description'] === null, 'Reminder update failed.');
    reminders_assert(($reminderService->complete($userId, (int) $taskReminder['id'])['status'] ?? '') === 'completed', 'Reminder complete failed.');
    reminders_assert(($reminderService->dismiss($userId, (int) $projectReminder['id'])['status'] ?? '') === 'dismissed', 'Reminder dismiss failed.');

    $overdue = $reminderService->create($userId, [
        'title' => 'Recordatorio atrasado',
        'remind_at' => '2020-01-01 10:00',
    ]);
    reminders_assert($overdue['is_overdue'] === true, 'Pending overdue reminder was not detected.');

    $pending = $reminderService->list($userId, ['status' => 'pending']);
    $done = $reminderService->list($userId, ['status' => 'done']);
    $all = $reminderService->list($userId, ['status' => 'all']);
    reminders_assert(count($pending) >= 2, 'Pending filter did not return pending reminders.');
    reminders_assert(count($done) >= 2, 'Done filter did not return completed/dismissed reminders.');
    reminders_assert(count($all) >= count($pending) + count($done), 'All filter did not include all reminders.');

    $daily = $reminderService->create($userId, [
        'title' => 'Recurrente diario',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'daily',
    ]);
    $dailyAdvanced = $reminderService->complete($userId, (int) $daily['id']);
    reminders_assert($dailyAdvanced !== null && $dailyAdvanced['status'] === 'pending', 'Daily recurring reminder was completed permanently.');
    reminders_assert($dailyAdvanced['next_remind_at'] === reminders_utc('2026-08-11 09:00'), 'Daily recurrence did not advance one day.');

    $everyTwoDays = $reminderService->create($userId, [
        'title' => 'Recurrente cada 2 dias',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'daily',
        'recurrence_interval' => '2',
    ]);
    reminders_assert($reminderService->complete($userId, (int) $everyTwoDays['id'])['next_remind_at'] === reminders_utc('2026-08-12 09:00'), 'Every two days recurrence failed.');

    $weekly = $reminderService->create($userId, [
        'title' => 'Recurrente semanal',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'weekly',
    ]);
    reminders_assert($reminderService->complete($userId, (int) $weekly['id'])['next_remind_at'] === reminders_utc('2026-08-17 09:00'), 'Weekly recurrence failed.');

    $everyTwoWeeks = $reminderService->create($userId, [
        'title' => 'Recurrente cada 2 semanas',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'weekly',
        'recurrence_interval' => 2,
    ]);
    reminders_assert($reminderService->complete($userId, (int) $everyTwoWeeks['id'])['next_remind_at'] === reminders_utc('2026-08-24 09:00'), 'Every two weeks recurrence failed.');

    $monthly = $reminderService->create($userId, [
        'title' => 'Recurrente mensual',
        'remind_at' => '2026-08-15 18:00',
        'recurrence_type' => 'monthly',
    ]);
    reminders_assert($reminderService->complete($userId, (int) $monthly['id'])['next_remind_at'] === reminders_utc('2026-09-15 18:00'), 'Monthly recurrence failed.');

    $yearly = $reminderService->create($userId, [
        'title' => 'Recurrente anual',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'yearly',
    ]);
    reminders_assert($reminderService->complete($userId, (int) $yearly['id'])['next_remind_at'] === reminders_utc('2027-08-10 09:00'), 'Yearly recurrence failed.');

    $until = $reminderService->create($userId, [
        'title' => 'Recurrente con fin',
        'remind_at' => '2026-08-10 09:00',
        'recurrence_type' => 'daily',
        'recurrence_until' => '2026-08-11',
    ]);
    $untilNext = $reminderService->complete($userId, (int) $until['id']);
    reminders_assert($untilNext !== null && $untilNext['next_remind_at'] === reminders_utc('2026-08-11 09:00'), 'Recurrence until blocked a valid occurrence.');
    $untilDone = $reminderService->complete($userId, (int) $until['id']);
    reminders_assert($untilDone !== null && $untilDone['next_remind_at'] === null && $untilDone['status'] === 'completed', 'Recurrence until did not finish after last occurrence.');

    reminders_reject(
        fn () => $reminderService->create($userId, ['title' => 'Intervalo invalido', 'remind_at' => '2026-08-10 09:00', 'recurrence_type' => 'daily', 'recurrence_interval' => '0']),
        'Invalid recurrence interval was accepted.'
    );

    reminders_assert(
        $reminderService->calculateNextOccurrence(reminders_utc('2026-01-31 09:00'), 'monthly', 1, null, reminders_utc('2026-01-31 09:00')) === reminders_utc('2026-02-28 09:00'),
        'Monthly recurrence did not clamp day 31 to month end.'
    );
    reminders_assert(
        $reminderService->calculateNextOccurrence(reminders_utc('2024-02-29 09:00'), 'yearly', 1, null, reminders_utc('2024-02-29 09:00')) === reminders_utc('2025-02-28 09:00'),
        'Yearly recurrence did not handle leap day.'
    );

    $editableRecurrence = $reminderService->create($userId, [
        'title' => 'Recurrente editable',
        'remind_at' => '2026-08-10 09:00',
    ]);
    $editedRecurrence = $reminderService->update($userId, (int) $editableRecurrence['id'], [
        'remind_at' => '2026-08-11 10:00',
        'recurrence_type' => 'weekly',
        'recurrence_interval' => '2',
    ]);
    reminders_assert($editedRecurrence !== null && $editedRecurrence['next_remind_at'] === reminders_utc('2026-08-11 10:00'), 'Editing recurrence did not recalculate next occurrence.');

    $stopped = $reminderService->stopRecurrence($userId, (int) $editedRecurrence['id']);
    reminders_assert($stopped !== null && $stopped['status'] === 'completed' && $stopped['recurrence_type'] === 'none' && $stopped['next_remind_at'] === null, 'Stopping recurrence failed.');

    $loginPage = reminders_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = reminders_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => reminders_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    reminders_assert($login['status'] === 302, 'Test user could not log in.');

    $remindersPage = reminders_request('http://127.0.0.1/index.php?section=organization&tab=reminders', 'GET', null, $cookieFile);
    reminders_assert($remindersPage['status'] === 200, 'Reminders page did not load.');
    reminders_assert(str_contains($remindersPage['body'], 'data-reminder-form'), 'Reminder form was not rendered.');
    reminders_assert(str_contains($remindersPage['body'], 'Recordatorios'), 'Reminder tab was not rendered.');
    reminders_assert(str_contains($remindersPage['body'], 'Recordatorio editado'), 'Own reminder was not shown.');
    reminders_assert(str_contains($remindersPage['body'], 'Atrasado'), 'Overdue reminder badge was not rendered.');
    reminders_assert(!str_contains($remindersPage['body'], 'Tarea ajena'), 'Another user target leaked in reminders page.');

    $csrfHeader = ['X-CSRF-Token: ' . reminders_screen_csrf($remindersPage['body'])];
    $apiCreate = reminders_request(
        'http://127.0.0.1/api/organization/reminders.php',
        'POST',
        [
            'title' => 'Recordatorio API',
            'task_id' => (string) $task['id'],
            'remind_at' => '2026-08-25 09:00',
            'recurrence_type' => 'weekly',
            'recurrence_interval' => '2',
        ],
        $cookieFile,
        $csrfHeader
    );
    reminders_assert($apiCreate['status'] === 201 && $apiCreate['json']['ok'] === true, 'Reminder API did not create.');
    reminders_assert($apiCreate['json']['data']['recurrence_type'] === 'weekly', 'Reminder API did not keep recurrence type.');
    $apiReminderId = (int) $apiCreate['json']['data']['id'];

    $apiList = reminders_request('http://127.0.0.1/api/organization/reminders.php?status=pending', 'GET', null, $cookieFile);
    reminders_assert($apiList['status'] === 200 && str_contains(json_encode($apiList['json']['data'], JSON_THROW_ON_ERROR), 'Recordatorio API'), 'Reminder API pending filter failed.');

    $apiPatch = reminders_request(
        'http://127.0.0.1/api/organization/reminders.php?id=' . $apiReminderId,
        'PATCH',
        ['title' => 'Recordatorio API editado', 'remind_date' => '2026-08-25', 'remind_time' => '10:00', 'recurrence_type' => 'daily', 'recurrence_interval' => '1'],
        $cookieFile,
        $csrfHeader
    );
    reminders_assert($apiPatch['status'] === 200 && $apiPatch['json']['data']['title'] === 'Recordatorio API editado', 'Reminder API update failed.');

    $apiComplete = reminders_request('http://127.0.0.1/api/organization/reminders.php?id=' . $apiReminderId . '&action=complete', 'POST', [], $cookieFile, $csrfHeader);
    reminders_assert($apiComplete['status'] === 200 && $apiComplete['json']['data']['status'] === 'pending', 'Reminder API recurrent complete did not advance occurrence.');
    $apiDismiss = reminders_request('http://127.0.0.1/api/organization/reminders.php?id=' . $apiReminderId . '&action=dismiss', 'POST', [], $cookieFile, $csrfHeader);
    reminders_assert($apiDismiss['status'] === 200 && $apiDismiss['json']['data']['status'] === 'pending', 'Reminder API recurrent dismiss did not advance occurrence.');
    $apiStop = reminders_request('http://127.0.0.1/api/organization/reminders.php?id=' . $apiReminderId . '&action=stop', 'POST', [], $cookieFile, $csrfHeader);
    reminders_assert($apiStop['status'] === 200 && $apiStop['json']['data']['recurrence_type'] === 'none', 'Reminder API stop recurrence failed.');
    $apiDelete = reminders_request('http://127.0.0.1/api/organization/reminders.php?id=' . $apiReminderId, 'DELETE', [], $cookieFile, $csrfHeader);
    reminders_assert($apiDelete['status'] === 200 && $apiDelete['json']['data']['deleted'] === true, 'Reminder API delete failed.');
    reminders_assert($reminderService->delete($userId, (int) $overdue['id']), 'Reminder service delete failed.');

    echo "Organization reminders: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization reminders: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }

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
