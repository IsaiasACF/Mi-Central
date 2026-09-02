<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Friends\CoincidenceService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleResolver;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendService;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$friendRepository = new FriendRepository($pdo);
$scheduleRepository = new FriendScheduleRepository($pdo);
$friendService = new FriendService($friendRepository);
$scheduleService = new FriendScheduleService($scheduleRepository);
$resolver = new FriendScheduleResolver($scheduleService, 'America/Santiago');
$coincidences = new CoincidenceService($friendRepository, $resolver);
$taskService = new TaskService(new TaskRepository($pdo), 'America/Santiago');
$username = 'test_coincidence_org_' . bin2hex(random_bytes(4));
$otherUsername = 'test_coincidence_org_other_' . bin2hex(random_bytes(4));
$emptyUsername = 'test_coincidence_org_empty_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$emptyPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidence-org-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidence-org-other-');
$exitCode = 1;

function coincidence_org_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function coincidence_org_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
    }

    $requestHeaders = $headers;

    if ($body !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
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
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
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

function coincidence_org_form_request(string $url, array $postFields, string $cookieFile): array
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

function coincidence_org_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function coincidence_org_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function coincidence_org_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = coincidence_org_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = coincidence_org_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => coincidence_org_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    coincidence_org_assert($login['status'] === 302, 'Test user could not log in.');
}

function coincidence_org_space_id(PDO $pdo, int $userId, string $slug): int
{
    $statement = $pdo->prepare('SELECT id FROM organization_spaces WHERE user_id = :user_id AND slug = :slug LIMIT 1');
    $statement->execute(['user_id' => $userId, 'slug' => $slug]);

    return (int) $statement->fetchColumn();
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080002_create_organization_tables.php',
        '202608080003_add_task_time_range.php',
        '202608080004_create_organization_reminders.php',
        '202608080007_create_friends_schedule_tables.php',
        '202608080008_add_user_schedule_exceptions.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $emptyUserId = $auth->createUser($emptyUsername, $emptyPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);
    $amigosSpaceId = coincidence_org_space_id($pdo, $userId, 'amigos');

    $date = '2026-08-12';
    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'room' => 'K200',
        'campus' => 'CSJ',
    ]);

    $tomas = $friendService->create($userId, ['name' => 'Tomas', 'default_campus' => 'CSJ']);
    $felipe = $friendService->create($userId, ['name' => 'Felipe', 'default_campus' => 'CSJ']);
    $otherFriend = $friendService->create($otherUserId, ['name' => 'Ajeno']);

    $scheduleService->createEntry($userId, 'friend', (int) $tomas['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'inf309',
        'room' => 'B038',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $felipe['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Arquitectura',
        'course_code' => 'INF325',
        'room' => 'A014',
        'campus' => 'CSJ',
    ]);

    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->modify('+1 day');
    $tomorrowDate = $tomorrow->format('Y-m-d');
    $tomorrowWeekday = (int) $tomorrow->format('N');
    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => $tomorrowWeekday,
        'starts_at' => '10:00',
        'ends_at' => '11:00',
        'course_name' => 'Clase dashboard',
        'course_code' => 'DASH1',
        'campus' => 'CSJ',
    ]);
    $cancelled = $friendService->create($userId, ['name' => 'Cancelado Dashboard', 'default_campus' => 'CSJ']);
    $cancelledEntry = $scheduleService->createEntry($userId, 'friend', (int) $cancelled['id'], [
        'weekday' => $tomorrowWeekday,
        'starts_at' => '10:00',
        'ends_at' => '11:00',
        'course_name' => 'Cancelada dashboard',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $cancelled['id'], [
        'schedule_entry_id' => (string) $cancelledEntry['id'],
        'exception_date' => $tomorrowDate,
        'type' => 'cancelled',
    ]);
    $modified = $friendService->create($userId, ['name' => 'Modificado Dashboard', 'default_campus' => 'CSJ']);
    $modifiedEntry = $scheduleService->createEntry($userId, 'friend', (int) $modified['id'], [
        'weekday' => $tomorrowWeekday,
        'starts_at' => '08:00',
        'ends_at' => '09:00',
        'course_name' => 'Original dashboard',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $modified['id'], [
        'schedule_entry_id' => (string) $modifiedEntry['id'],
        'exception_date' => $tomorrowDate,
        'type' => 'modified',
        'starts_at' => '10:00',
        'ends_at' => '11:00',
        'course_name' => 'Clase dashboard modificada',
        'course_code' => 'DASH1',
        'campus' => 'CSJ',
    ]);

    coincidence_org_login($username, $password, $cookieFile);

    $calendarBefore = coincidence_org_request('http://127.0.0.1/index.php?section=organization&tab=calendar&view=month&date=' . $date, 'GET', null, $cookieFile);
    coincidence_org_assert($calendarBefore['status'] === 200 && !str_contains($calendarBefore['body'], 'Ver a Tomas'), 'Raw coincidence appeared on Organization calendar before creating a task.');

    $coincidencePage = coincidence_org_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date, 'GET', null, $cookieFile);
    coincidence_org_assert($coincidencePage['status'] === 200 && str_contains($coincidencePage['body'], 'Crear tarea') && str_contains($coincidencePage['body'], 'Crear tarea con este grupo'), 'Coincidence task actions were not rendered.');

    $returnTo = rawurlencode('/index.php?section=friends&tab=coincidences&date=' . $date);
    $prefillUrl = 'http://127.0.0.1/index.php?section=organization&tab=tasks&from=coincidence&type=individual&date=' . $date . '&starts_at=14%3A40&ends_at=15%3A50&friend_id=' . (int) $tomas['id'] . '&return_to=' . $returnTo;
    $prefillPage = coincidence_org_request($prefillUrl, 'GET', null, $cookieFile);
    coincidence_org_assert($prefillPage['status'] === 200, 'Coincidence task prefill page did not load.');
    coincidence_org_assert(str_contains($prefillPage['body'], 'Ver a Tomas') && str_contains($prefillPage['body'], '2026-08-12T14:40') && str_contains($prefillPage['body'], '2026-08-12T15:50'), 'Coincidence prefill did not include local start/end.');
    coincidence_org_assert(str_contains($prefillPage['body'], 'Coincidencia segun horarios academicos con Tomas.') && str_contains($prefillPage['body'], 'Ramo compartido: INF309.') && str_contains($prefillPage['body'], 'Campus segun horario: CSJ.'), 'Coincidence prefill description was incomplete.');
    coincidence_org_assert(str_contains($prefillPage['body'], '&quot;space_id&quot;:&quot;' . $amigosSpaceId . '&quot;'), 'Coincidence prefill did not select Amigos by slug lookup.');

    $groupPrefill = coincidence_org_request(
        'http://127.0.0.1/index.php?section=organization&tab=tasks&from=coincidence&type=group&date=' . $date . '&starts_at=14%3A40&ends_at=15%3A50&friend_ids=' . (int) $tomas['id'] . ',' . (int) $felipe['id'],
        'GET',
        null,
        $cookieFile
    );
    coincidence_org_assert($groupPrefill['status'] === 200 && str_contains($groupPrefill['body'], 'Juntarse con Tomas y Felipe') && str_contains($groupPrefill['body'], 'Participantes: Tomas, Felipe.'), 'Group coincidence prefill failed.');

    $foreignPrefill = coincidence_org_request(
        'http://127.0.0.1/index.php?section=organization&tab=tasks&from=coincidence&type=individual&date=' . $date . '&starts_at=14%3A40&ends_at=15%3A50&friend_id=' . (int) $otherFriend['id'],
        'GET',
        null,
        $cookieFile
    );
    coincidence_org_assert($foreignPrefill['status'] === 422, 'Foreign coincidence prefill was not rejected.');

    $csrf = coincidence_org_screen_csrf($prefillPage['body']);
    $badCsrf = coincidence_org_request('http://127.0.0.1/api/organization/tasks.php', 'POST', [
        'title' => 'Sin CSRF',
    ], $cookieFile);
    coincidence_org_assert($badCsrf['status'] === 403, 'Task creation without CSRF was not rejected.');

    $create = coincidence_org_request('http://127.0.0.1/api/organization/tasks.php', 'POST', [
        'title' => 'Cafe con Tomas editado',
        'description' => 'El usuario cambio los datos antes de guardar.',
        'space_id' => (string) $amigosSpaceId,
        'starts_at' => '2026-08-12T14:40',
        'ends_at' => '2026-08-12T15:50',
        'due_at' => '',
    ], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    coincidence_org_assert($create['status'] === 201 && is_array($create['json'] ?? null) && ($create['json']['ok'] ?? false) === true, 'Task could not be created from prefilled coincidence data.');

    $createdTask = $create['json']['data'];
    $createdTaskId = (int) ($createdTask['id'] ?? 0);
    coincidence_org_assert((int) ($createdTask['space_id'] ?? 0) === $amigosSpaceId, 'User-edited task space was not preserved.');
    coincidence_org_assert(($createdTask['starts_at'] ?? '') === '2026-08-12 18:40:00' && ($createdTask['ends_at'] ?? '') === '2026-08-12 19:50:00', 'Task was not stored in UTC from local coincidence time.');
    coincidence_org_assert(($createdTask['starts_at_input'] ?? '') === '2026-08-12T14:40' && ($createdTask['ends_at_input'] ?? '') === '2026-08-12T15:50', 'Task did not round-trip to local coincidence time.');
    coincidence_org_assert($taskService->get($userId, $createdTaskId) !== null, 'Created task was not available through OrganizationTaskService.');

    $createdPage = coincidence_org_request('http://127.0.0.1/index.php?section=organization&tab=tasks&status=all', 'GET', null, $cookieFile);
    coincidence_org_assert($createdPage['status'] === 200 && str_contains($createdPage['body'], 'Cafe con Tomas editado'), 'Created task did not appear in Organization tasks.');

    $calendarAfter = coincidence_org_request('http://127.0.0.1/index.php?section=organization&tab=calendar&view=month&date=' . $date, 'GET', null, $cookieFile);
    coincidence_org_assert($calendarAfter['status'] === 200 && str_contains($calendarAfter['body'], 'Cafe con Tomas editado'), 'Created task did not appear in Organization calendar.');

    $reminderCount = $pdo->prepare('SELECT COUNT(*) FROM organization_reminders WHERE task_id = :task_id');
    $reminderCount->execute(['task_id' => $createdTaskId]);
    coincidence_org_assert((int) $reminderCount->fetchColumn() === 0, 'Creating a task from a coincidence created a reminder.');

    $dashboard = (new DashboardSummaryService($config['app'], $taskService, $userId, null, null, null, $coincidences))->summary();
    $coincidenceItems = $dashboard['sections']['coincidences']['items'] ?? [];
    coincidence_org_assert(is_array($coincidenceItems) && $coincidenceItems !== [], 'Dashboard did not expose next coincidence.');
    $dashboardLabel = (string) ($coincidenceItems[0]['label'] ?? '');
    coincidence_org_assert(str_contains($dashboardLabel, 'Modificado Dashboard') && str_contains($dashboardLabel, '10:00 - 11:00'), 'Dashboard did not use modified effective schedule.');
    coincidence_org_assert(!str_contains($dashboardLabel, 'Cancelado Dashboard'), 'Dashboard included a cancelled coincidence.');

    $emptyDashboard = (new DashboardSummaryService($config['app'], $taskService, $emptyUserId, null, null, null, $coincidences))->summary();
    coincidence_org_assert(($emptyDashboard['sections']['coincidences']['items'] ?? []) === [], 'Dashboard should handle absence of coincidences.');

    echo "Friends coincidences Organization integration: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends coincidences Organization integration: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ([$cookieFile, $otherCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username, :empty_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'empty_username' => $emptyUsername,
    ]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username, :empty_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'empty_username' => $emptyUsername,
    ]);
}

exit($exitCode);
