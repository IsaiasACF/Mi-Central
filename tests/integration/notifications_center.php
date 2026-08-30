<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Notifications\NotificationActivityService;
use Modules\Notifications\NotificationRepository;
use Modules\Notifications\NotificationService;
use Modules\Organization\NoteRepository;
use Modules\Organization\NoteService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$config = require dirname(__DIR__, 2) . '/config/app.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$taskService = new TaskService(new TaskRepository($pdo));
$projectService = new ProjectService(new ProjectRepository($pdo), 'America/Santiago');
$noteService = new NoteService(new NoteRepository($pdo));
$reminderService = new ReminderService(new ReminderRepository($pdo), 'America/Santiago');
$notificationRepository = new NotificationRepository($pdo);
$notificationService = new NotificationService($notificationRepository, 'America/Santiago');
$activityService = new NotificationActivityService($pdo, $notificationRepository, 'America/Santiago');
$username = 'test_notifications_' . bin2hex(random_bytes(4));
$otherUsername = 'test_notifications_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-notifications-');
$exitCode = 1;

function notifications_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function notifications_form_request(string $url, array $postFields, string $cookieFile): array
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

function notifications_csrf_from_login(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function notifications_csrf_from_header(string $html): string
{
    if (preg_match('/data-notification-center[^>]+data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Notification CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function notifications_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notifications_insert(PDO $pdo, int $userId, ?int $reminderId, string $title, string $createdAt, ?string $readAt = null): int
{
    $statement = $pdo->prepare(
        'INSERT INTO notifications
            (user_id, reminder_id, type, title, message, scheduled_at, read_at, created_at)
         VALUES
            (:user_id, :reminder_id, :type, :title, :message, :scheduled_at, :read_at, :created_at)'
    );
    $statement->execute([
        'user_id' => $userId,
        'reminder_id' => $reminderId,
        'type' => NotificationRepository::TYPE_REMINDER_DUE,
        'title' => $title,
        'message' => 'Mensaje ' . $title,
        'scheduled_at' => '2026-08-08 22:20:00',
        'read_at' => $readAt,
        'created_at' => $createdAt,
    ]);

    return (int) $pdo->lastInsertId();
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $spaceStatement = $pdo->prepare(
        'INSERT INTO organization_spaces (user_id, name, slug)
         VALUES (:user_id, :name, :slug)'
    );
    $spaceStatement->execute([
        'user_id' => $userId,
        'name' => 'Actividad',
        'slug' => 'actividad-' . $userId,
    ]);
    $spaceId = (int) $pdo->lastInsertId();
    $task = $taskService->create($userId, ['title' => 'Tarea destino notificacion']);
    $dueTask = $taskService->create($userId, ['title' => 'Tarea vence hoy actividad', 'due_at' => '2030-01-01 12:00']);
    $startTask = $taskService->create($userId, ['title' => 'Tarea empieza hoy actividad', 'starts_at' => '2030-01-01 09:00']);
    $overdueTask = $taskService->create($userId, ['title' => 'Tarea vencida actividad', 'due_at' => '2029-12-31 09:00']);
    $otherTask = $taskService->create($otherUserId, ['title' => 'Tarea ajena actividad', 'due_at' => '2030-01-01 12:00']);
    $project = $projectService->create($userId, ['space_id' => (string) $spaceId, 'title' => 'Proyecto actividad', 'due_on' => '2030-01-03']);
    $projectTaskDone = $taskService->create($userId, ['title' => 'Proyecto done', 'project_id' => (string) $project['id'], 'status' => 'completed']);
    $projectTaskPending = $taskService->create($userId, ['title' => 'Proyecto pending', 'project_id' => (string) $project['id']]);
    $projectTaskSoon = $taskService->create($userId, ['title' => 'Proyecto tarea por vencer', 'project_id' => (string) $project['id'], 'due_at' => '2030-01-03 09:00']);
    $subtaskSoon = $taskService->create($userId, ['title' => 'Subtarea por vencer', 'project_id' => (string) $project['id'], 'parent_task_id' => (string) $projectTaskSoon['id'], 'due_at' => '2030-01-03 13:00']);
    $startsAndDueTask = $taskService->create($userId, ['title' => 'Tarea empieza y vence hoy', 'starts_at' => '2030-01-01 08:00', 'due_at' => '2030-01-01 17:00']);
    $activeNote = $noteService->create($userId, ['title' => 'Nota activa diaria', 'content' => 'Recordar cada dia']);
    $completedNote = $noteService->create($userId, ['title' => 'Nota completada sin aviso', 'content' => 'No recordar']);
    $noteService->complete($userId, (int) $completedNote['id']);
    $reminder = $reminderService->create($userId, [
        'title' => 'Recordatorio destino',
        'task_id' => (string) $task['id'],
        'remind_at' => '2026-08-09 10:00',
    ]);

    $activitySummary = $activityService->processUser($userId, new DateTimeImmutable('2030-01-01 10:00:00', new DateTimeZone('America/Santiago')));
    $activitySummaryAgain = $activityService->processUser($userId, new DateTimeImmutable('2030-01-01 10:05:00', new DateTimeZone('America/Santiago')));
    notifications_assert($activitySummary['daily_agenda'] === 1 && $activitySummaryAgain['daily_agenda'] === 0, 'Daily agenda was not deduplicated.');
    notifications_assert($activitySummary['tasks'] >= 6 && $activitySummaryAgain['tasks'] === 0, 'Task activity notifications were not created or deduplicated.');
    notifications_assert($activitySummary['notes'] === 1 && $activitySummaryAgain['notes'] === 0, 'Daily active note notification was not created or deduplicated.');
    notifications_assert($activitySummary['projects'] >= 1 && $activitySummaryAgain['projects'] === 0, 'Project activity notification was not created or deduplicated.');

    $activityItems = $notificationService->list($userId, ['status' => 'all', 'limit' => 20]);
    $activityJson = json_encode($activityItems, JSON_THROW_ON_ERROR);
    notifications_assert(str_contains($activityJson, 'Tu dia') && str_contains($activityJson, 'Tarea vence hoy') && str_contains($activityJson, 'Tarea por vencer') && str_contains($activityJson, 'Subtarea por vencer') && str_contains($activityJson, 'Nota activa diaria') && str_contains($activityJson, 'Proyecto actividad: 1 de 4 tareas completadas') && str_contains($activityJson, 'edit_task=' . (int) $dueTask['id']) && str_contains($activityJson, 'section=organization&tab=notes'), 'Activity center did not include organization targets.');
    notifications_assert(!str_contains($activityJson, 'Tarea ajena actividad'), 'Activity center included another user task.');
    notifications_assert(!str_contains($activityJson, 'Nota completada sin aviso'), 'Activity center included a completed note.');
    $startsAndDueNotifications = array_filter(
        $activityItems,
        static fn (array $item): bool => ($item['entity_type'] ?? '') === 'task'
            && (int) ($item['entity_id'] ?? 0) === (int) $startsAndDueTask['id'],
    );
    notifications_assert(count($startsAndDueNotifications) === 1, 'Task with start and due today generated duplicate activity.');

    $videoStatement = $pdo->prepare(
        "INSERT INTO video_files (user_id, original_name, stored_name, storage_path, extension, mime_type, size_bytes, status)
         VALUES (:user_id, :original_name, :stored_name, :storage_path, 'mp4', 'video/mp4', 120, 'uploaded')"
    );
    $videoStatement->execute([
        'user_id' => $userId,
        'original_name' => 'Video actividad.mp4',
        'stored_name' => 'activity-' . $userId . '.mp4',
        'storage_path' => 'activity-' . $userId . '.mp4',
    ]);
    $videoId = (int) $pdo->lastInsertId();
    $exportStatement = $pdo->prepare(
        "INSERT INTO video_export_jobs (user_id, video_id, status, output_name, output_stored_name, estimated_duration_seconds, progress_percent, completed_at)
         VALUES (:user_id, :video_id, 'completed', 'Export actividad', :stored, 10, 100, UTC_TIMESTAMP())"
    );
    $exportStatement->execute(['user_id' => $userId, 'video_id' => $videoId, 'stored' => 'export-activity-' . $userId . '.mp4']);
    $exportId = (int) $pdo->lastInsertId();
    $notificationRepository->createActivity($userId, NotificationRepository::TYPE_VIDEO_EXPORT_COMPLETED, 'video', 'video_export', $exportId, 'video_export:' . $exportId . ':completed', 'Exportacion completada', 'Export actividad', '2030-01-01 13:00:00');
    $videoItem = $notificationService->list($userId, ['status' => 'all', 'limit' => 1])[0] ?? [];
    notifications_assert(($videoItem['target_url'] ?? '') === '/index.php?section=video&tab=processings' && ($videoItem['type_label'] ?? '') === 'Video', 'Video notification target or label failed.');
    $pdo->prepare('DELETE FROM notifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);

    $olderId = notifications_insert($pdo, $userId, null, 'Notificacion antigua', '2030-01-01 09:00:00');
    $readId = notifications_insert($pdo, $userId, null, 'Notificacion leida', '2030-01-01 10:00:00', '2026-08-08 23:00:00');
    $newerId = notifications_insert($pdo, $userId, (int) $reminder['id'], 'Notificacion nueva', '2030-01-01 11:00:00');
    $otherId = notifications_insert($pdo, $otherUserId, null, 'Notificacion ajena', '2030-01-01 12:00:00');

    notifications_assert($notificationService->unreadCount($userId) === 2, 'Unread counter is wrong.');
    $all = $notificationService->list($userId, ['status' => 'all']);
    notifications_assert(count($all) === 3, 'Service listed another user notification.');
    notifications_assert((int) $all[0]['id'] === $newerId && (int) $all[2]['id'] === $olderId, 'Notifications were not ordered newest first.');
    notifications_assert(str_contains((string) ($all[0]['target_url'] ?? ''), 'edit_task=' . (int) $task['id']), 'Notification did not resolve task target URL.');
    notifications_assert(count($notificationService->list($userId, ['status' => 'unread'])) === 2, 'Unread filter failed.');
    notifications_assert(count($notificationService->list($userId, ['status' => 'read'])) === 1, 'Read filter failed.');

    notifications_assert($notificationService->markRead($userId, $olderId), 'Could not mark own notification as read.');
    notifications_assert($notificationService->unreadCount($userId) === 1, 'Unread counter did not change after mark read.');
    notifications_assert(!$notificationService->markRead($userId, $otherId), 'User could mark another user notification.');
    notifications_assert($notificationService->markAllRead($userId) === 1, 'Mark all read did not update remaining unread notification.');
    notifications_assert($notificationService->unreadCount($userId) === 0, 'Unread counter did not clear after mark all.');

    notifications_insert($pdo, $userId, null, 'Dashboard notificacion', '2030-01-01 13:00:00');
    $summary = (new DashboardSummaryService($config, $taskService, $userId, $reminderService, $notificationService))->summary();
    $dashboardNotifications = json_encode($summary['sections']['notifications']['items'] ?? [], JSON_THROW_ON_ERROR);
    notifications_assert(str_contains($dashboardNotifications, 'Dashboard notificacion'), 'Dashboard did not receive unread notification.');

    $unauthenticated = notifications_request('http://127.0.0.1/api/notifications.php');
    notifications_assert($unauthenticated['status'] === 401, 'Notifications API did not require authentication.');

    $loginPage = notifications_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = notifications_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => notifications_csrf_from_login($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    notifications_assert($login['status'] === 302, 'Test user could not log in.');

    $home = notifications_request('http://127.0.0.1/index.php', 'GET', null, $cookieFile);
    notifications_assert($home['status'] === 200 && str_contains($home['body'], 'notification-bell'), 'Header did not render notification bell.');
    notifications_assert(str_contains($home['body'], 'Dashboard notificacion'), 'Dashboard page did not render notification widget item.');
    $csrfHeader = ['X-CSRF-Token: ' . notifications_csrf_from_header($home['body'])];

    $apiList = notifications_request('http://127.0.0.1/api/notifications.php?status=unread', 'GET', null, $cookieFile);
    notifications_assert($apiList['status'] === 200 && $apiList['json']['data']['unread_count'] === 1, 'Notifications API unread list failed.');

    $missingCsrf = notifications_request('http://127.0.0.1/api/notifications.php', 'POST', ['action' => 'read-all'], $cookieFile);
    notifications_assert($missingCsrf['status'] === 403, 'Notifications API write did not require CSRF.');

    $foreign = notifications_request('http://127.0.0.1/api/notifications.php', 'POST', ['action' => 'read', 'id' => $otherId], $cookieFile, $csrfHeader);
    notifications_assert($foreign['status'] === 404, 'User could mark another notification through API.');

    $markAll = notifications_request('http://127.0.0.1/api/notifications.php', 'POST', ['action' => 'read-all'], $cookieFile, $csrfHeader);
    notifications_assert($markAll['status'] === 200 && $markAll['json']['data']['unread_count'] === 0, 'Notifications API mark all failed.');

    $page = notifications_request('http://127.0.0.1/index.php?section=notifications&status=all', 'GET', null, $cookieFile);
    notifications_assert($page['status'] === 200 && str_contains($page['body'], 'Centro de actividad') && str_contains($page['body'], 'Notificacion nueva'), 'Notifications page did not render.');

    echo "Notifications center: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Notifications center: FAILED\n");
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
