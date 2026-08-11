<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\CalendarRepository;
use Modules\Organization\CalendarService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$taskService = new TaskService(new TaskRepository($pdo));
$projectService = new ProjectService(new ProjectRepository($pdo));
$calendarService = new CalendarService(new CalendarRepository($pdo), 'America/Santiago');
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_calendar_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-calendar-');
$exitCode = 1;

function calendar_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null): array
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
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

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

function calendar_form_request(string $url, array $postFields, string $cookieFile): array
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

function calendar_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function calendar_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $userId = $auth->createUser($username, $password);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaceId = (int) $spaceRepository->listForUser($userId)[0]['id'];
    $project = $projectService->create($userId, [
        'title' => 'Proyecto Redes',
        'space_id' => $spaceId,
        'starts_on' => '2026-08-12',
        'due_on' => '2026-08-30',
    ]);
    $projectId = (int) $project['id'];
    $scheduled = $taskService->create($userId, [
        'title' => 'Presentacion',
        'space_id' => $spaceId,
        'starts_at' => '2026-08-12 10:00:00',
        'ends_at' => '2026-08-12 11:30:00',
    ]);
    $multiDay = $taskService->create($userId, [
        'title' => 'Actividad varios dias',
        'space_id' => $spaceId,
        'starts_at' => '2026-08-13 10:00:00',
        'ends_at' => '2026-08-15 12:00:00',
    ]);
    $twoWeekTask = $taskService->create($userId, [
        'title' => 'Rango cruza dos semanas',
        'space_id' => $spaceId,
        'starts_at' => '2026-08-14 09:00:00',
        'ends_at' => '2026-08-18 11:00:00',
    ]);
    $due = $taskService->create($userId, [
        'title' => 'Entrega informe',
        'space_id' => $spaceId,
        'due_at' => '2026-08-12 18:00:00',
    ]);
    $longProject = $projectService->create($userId, [
        'title' => 'Proyecto varias semanas',
        'space_id' => $spaceId,
        'starts_on' => '2026-08-03',
        'due_on' => '2026-08-25',
    ]);
    $startsBeforeProject = $projectService->create($userId, [
        'title' => 'Proyecto viene de julio',
        'space_id' => $spaceId,
        'starts_on' => '2026-07-20',
        'due_on' => '2026-08-03',
    ]);
    $endsAfterProject = $projectService->create($userId, [
        'title' => 'Proyecto sigue en septiembre',
        'space_id' => $spaceId,
        'starts_on' => '2026-08-30',
        'due_on' => '2026-09-12',
    ]);
    $projectTask = $taskService->create($userId, [
        'title' => 'Demo proyecto',
        'project_id' => $projectId,
        'due_at' => '2026-08-23 10:00:00',
    ]);
    $taskService->create($userId, [
        'title' => 'Tarea sin fecha calendario',
        'space_id' => $spaceId,
    ]);

    $calendar = $calendarService->view($userId, 'month', '2026-08-12');
    $itemsByDate = $calendar['items_by_date'];
    calendar_assert(isset($itemsByDate['2026-08-12'], $itemsByDate['2026-08-13'], $itemsByDate['2026-08-14'], $itemsByDate['2026-08-15'], $itemsByDate['2026-08-23']), 'Calendar service did not group expected dates.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-12'], JSON_THROW_ON_ERROR), 'Presentacion'), 'Scheduled task did not appear.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-12'], JSON_THROW_ON_ERROR), 'Vence: Entrega informe'), 'Due task did not appear as due item.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-23'], JSON_THROW_ON_ERROR), 'Demo proyecto'), 'Project task did not appear.');
    calendar_assert(!str_contains(json_encode($itemsByDate, JSON_THROW_ON_ERROR), 'Tarea sin fecha calendario'), 'Task without dates appeared in calendar.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-13'], JSON_THROW_ON_ERROR), 'Actividad varios dias'), 'Multi-day task did not appear on range start day.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-14'], JSON_THROW_ON_ERROR), 'Actividad varios dias'), 'Multi-day task did not appear on intermediate day.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-15'], JSON_THROW_ON_ERROR), 'Actividad varios dias'), 'Multi-day task did not appear on range end day.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-12'], JSON_THROW_ON_ERROR), 'Proyecto Redes'), 'Project did not appear on range start day.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-30'] ?? [], JSON_THROW_ON_ERROR), 'Proyecto Redes'), 'Project did not appear on range end day.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-14'], JSON_THROW_ON_ERROR), 'Rango cruza dos semanas'), 'Two-week task did not appear before week break.');
    calendar_assert(str_contains(json_encode($itemsByDate['2026-08-18'] ?? [], JSON_THROW_ON_ERROR), 'Rango cruza dos semanas'), 'Two-week task did not appear after week break.');

    $loginPage = calendar_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = calendar_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => calendar_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    calendar_assert($login['status'] === 302, 'Test user could not log in.');

    $monthPage = calendar_request('http://127.0.0.1/index.php?section=organization&tab=calendar&view=month&date=2026-08-12&status=all', 'GET', null, $cookieFile);
    calendar_assert($monthPage['status'] === 200, 'Monthly calendar page did not load.');
    calendar_assert(str_contains($monthPage['body'], 'Agosto 2026'), 'Monthly calendar title was not rendered.');
    calendar_assert(str_contains($monthPage['body'], '10:00-11:30 Presentacion'), 'Scheduled task label was not rendered in Santiago time.');
    calendar_assert(str_contains($monthPage['body'], '18:00 Vence: Entrega informe'), 'Due label was not rendered in Santiago time.');
    calendar_assert(str_contains($monthPage['body'], 'Proyecto Proyecto Redes'), 'Project range was not rendered.');
    calendar_assert(substr_count($monthPage['body'], 'Actividad varios dias') >= 3, 'Single-week range title was not rendered in each day.');
    calendar_assert(!str_contains($monthPage['body'], 'calendar-week__ranges'), 'Range lane was rendered outside the daily grid.');
    calendar_assert(!str_contains($monthPage['body'], 'calendar-range'), 'Continuous range bar markup was rendered.');
    calendar_assert(
        preg_match('/<section class="calendar-day[^"]*".*calendar-item calendar-item--project.*Proyecto Proyecto Redes.*<\/section>/s', $monthPage['body']) === 1,
        'Project chip was not rendered inside a day cell.'
    );
    calendar_assert(!str_contains($monthPage['body'], 'Tarea sin fecha calendario'), 'Task without dates appeared in monthly page.');
    calendar_assert(str_contains($monthPage['body'], 'edit_task=' . (int) $scheduled['id']), 'Scheduled task link did not target edit flow.');
    calendar_assert(str_contains($monthPage['body'], 'edit_task=' . (int) $multiDay['id']), 'Multi-day task range did not keep clickable task link.');
    calendar_assert(str_contains($monthPage['body'], 'edit_task=' . (int) $twoWeekTask['id']), 'Two-week task range did not keep clickable task link.');
    calendar_assert(str_contains($monthPage['body'], 'edit_task=' . (int) $projectTask['id']), 'Project task link did not target its record.');
    calendar_assert(str_contains($monthPage['body'], 'project=' . $projectId), 'Project link did not target project detail.');
    calendar_assert(str_contains($monthPage['body'], 'project=' . (int) $longProject['id']), 'Multi-week project range did not keep clickable project link.');
    calendar_assert(str_contains($monthPage['body'], 'project=' . (int) $startsBeforeProject['id']), 'Left-continuing project range did not keep clickable project link.');
    calendar_assert(str_contains($monthPage['body'], 'project=' . (int) $endsAfterProject['id']), 'Right-continuing project range did not keep clickable project link.');

    $weekPage = calendar_request('http://127.0.0.1/index.php?section=organization&tab=calendar&view=week&date=2026-08-12&status=all', 'GET', null, $cookieFile);
    calendar_assert($weekPage['status'] === 200, 'Weekly calendar page did not load.');
    calendar_assert(str_contains($weekPage['body'], '10/08/2026 - 16/08/2026'), 'Weekly calendar range title was not rendered.');
    calendar_assert(str_contains($weekPage['body'], 'Actividad varios dias'), 'Weekly calendar did not show multi-day task.');

    echo "Organization calendar: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization calendar: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $username]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
