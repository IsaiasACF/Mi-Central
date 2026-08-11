<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Friends\FriendPresenceService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendService;
use Modules\Notifications\NotificationRepository;
use Modules\Notifications\NotificationService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$config = require dirname(__DIR__, 2) . '/config/app.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$friendRepository = new FriendRepository($pdo);
$scheduleRepository = new FriendScheduleRepository($pdo);
$friendService = new FriendService($friendRepository);
$scheduleService = new FriendScheduleService($scheduleRepository);
$presenceService = new FriendPresenceService($friendRepository, $scheduleService, 'America/Santiago');
$username = 'test_friends_now_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_now_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-friends-now-');
$exitCode = 1;

function friends_now_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function friends_now_form_request(string $url, array $postFields, string $cookieFile): array
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

function friends_now_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function friends_now_screen_csrf(string $html): string
{
    if (preg_match('/data-friends-page[^>]+data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Friends CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function friends_now_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $tomas = $friendService->create($userId, ['name' => 'Tomas', 'default_campus' => 'San Joaquin']);
    $felipe = $friendService->create($userId, ['name' => 'Felipe']);
    $diego = $friendService->create($userId, ['name' => 'Diego']);
    $expired = $friendService->create($userId, ['name' => 'Valentina']);
    $otherFriend = $friendService->create($otherUserId, ['name' => 'Ajeno']);

    $scheduleService->createEntry($userId, 'friend', (int) $tomas['id'], [
        'weekday' => 1,
        'starts_at' => '16:00',
        'ends_at' => '17:30',
        'course_name' => 'Redes',
        'room' => 'B201',
        'campus' => '',
        'valid_from' => '2026-08-01',
        'valid_until' => '2026-12-20',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $felipe['id'], [
        'weekday' => 1,
        'starts_at' => '15:00',
        'ends_at' => '16:00',
        'course_name' => 'Fisica',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $felipe['id'], [
        'weekday' => 1,
        'starts_at' => '19:00',
        'ends_at' => '20:00',
        'course_name' => 'Bases Datos',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $expired['id'], [
        'weekday' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'course_name' => 'Periodo antiguo',
        'valid_until' => '2026-07-31',
    ]);

    $fixed = new DateTimeImmutable('2026-08-10 16:30:00', new DateTimeZone('America/Santiago'));
    $now = $presenceService->now($userId, $fixed);
    $encodedNow = json_encode($now, JSON_THROW_ON_ERROR);
    friends_now_assert(str_contains($encodedNow, 'Tomas') && str_contains($encodedNow, 'En clase') && str_contains($encodedNow, 'hasta 17:30'), 'Now did not detect friend in class.');
    friends_now_assert(str_contains($encodedNow, 'Felipe') && str_contains($encodedNow, 'Libre hasta 19:00'), 'Now did not detect free friend between blocks.');
    friends_now_assert(str_contains($encodedNow, 'Diego') && str_contains($encodedNow, 'Sin clases hoy'), 'Now did not detect friend without classes.');
    friends_now_assert(str_contains($encodedNow, 'Valentina') && str_contains($encodedNow, 'Fuera del periodo de vigencia'), 'Now did not apply validity period.');

    $today = $presenceService->today($userId, $fixed);
    $felipeBlocks = [];
    foreach ($today as $row) {
        if (($row['friend']['name'] ?? '') === 'Felipe') {
            $felipeBlocks = $row['blocks'];
        }
    }
    friends_now_assert(count($felipeBlocks) === 2, 'Today did not list Felipe blocks.');
    friends_now_assert($felipeBlocks[0]['course_name'] === 'Fisica' && $felipeBlocks[0]['state'] === 'Finalizada', 'Today did not order or mark finished block.');
    friends_now_assert($felipeBlocks[1]['course_name'] === 'Bases Datos' && $felipeBlocks[1]['state'] === 'Proxima', 'Today did not mark next block.');

    $dashboard = (new DashboardSummaryService(
        $config,
        new TaskService(new TaskRepository($pdo)),
        $userId,
        new ReminderService(new ReminderRepository($pdo), 'America/Santiago'),
        new NotificationService(new NotificationRepository($pdo), 'America/Santiago'),
        $presenceService
    ))->summary();
    $dashboardFriends = json_encode($dashboard['sections']['friends']['items'] ?? [], JSON_THROW_ON_ERROR);
    friends_now_assert(str_contains($dashboardFriends, 'Tomas'), 'Dashboard did not receive friends summary.');

    $loginPage = friends_now_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = friends_now_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => friends_now_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    friends_now_assert($login['status'] === 302, 'Test user could not log in.');

    $nowPage = friends_now_request('http://127.0.0.1/index.php?section=friends&tab=now', 'GET', null, $cookieFile);
    friends_now_assert($nowPage['status'] === 200 && str_contains($nowPage['body'], 'Segun horario'), 'Now page did not render.');

    $todayPage = friends_now_request('http://127.0.0.1/index.php?section=friends&tab=today', 'GET', null, $cookieFile);
    friends_now_assert($todayPage['status'] === 200 && str_contains($todayPage['body'], 'Hoy'), 'Today page did not render.');

    $weekPage = friends_now_request('http://127.0.0.1/index.php?section=friends&tab=week&target=friend:' . (int) $tomas['id'] . '&week=2026-08-17', 'GET', null, $cookieFile);
    friends_now_assert($weekPage['status'] === 200 && str_contains($weekPage['body'], 'Semana real') && str_contains($weekPage['body'], 'Bloques efectivos'), 'Week page did not render.');

    $schedulePage = friends_now_request('http://127.0.0.1/index.php?section=friends&tab=schedules&target=friend:' . (int) $tomas['id'] . '&show=all', 'GET', null, $cookieFile);
    friends_now_assert($schedulePage['status'] === 200, 'Schedule page did not load.');
    friends_now_assert(str_contains($schedulePage['body'], 'data-schedule-detail-duplicate') && str_contains($schedulePage['body'], 'data-schedule-detail-close'), 'Schedule detail actions were not rendered.');
    friends_now_assert(str_contains($schedulePage['body'], 'role="dialog"') && str_contains($schedulePage['body'], 'aria-haspopup="dialog"'), 'Schedule detail popover accessibility attributes were not rendered.');
    friends_now_assert(str_contains($schedulePage['body'], 'data-owner-label="Tomas"') && str_contains($schedulePage['body'], 'data-room="B201"'), 'Schedule block detail data was not rendered.');
    friends_now_assert(str_contains($schedulePage['body'], 'data-schedule-import-form'), 'Schedule import form was not rendered.');
    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    friends_now_assert(is_string($appCss) && str_contains($appCss, '.schedule-detail') && str_contains($appCss, 'position: fixed'), 'Schedule detail is not styled as a floating popover.');
    friends_now_assert(is_string($appCss) && str_contains($appCss, '.schedule-detail--sheet') && str_contains($appCss, '.schedule-block.is-selected'), 'Schedule detail responsive sheet or selected state is missing.');
    friends_now_assert(is_string($appJs) && str_contains($appJs, 'getBoundingClientRect') && str_contains($appJs, "event.key === 'Escape'"), 'Schedule detail positioning or Escape handling is missing.');
    friends_now_assert(is_string($appJs) && str_contains($appJs, 'aria-expanded') && str_contains($appJs, 'preventScroll'), 'Schedule detail accessibility or no-scroll focus handling is missing.');
    $csrfHeader = ['X-CSRF-Token: ' . friends_now_screen_csrf($schedulePage['body'])];

    $validJson = json_encode([
        'version' => 1,
        'schedule' => [
            [
                'weekday' => 'monday',
                'starts_at' => '08:30',
                'ends_at' => '10:00',
                'course_name' => 'Bases de Datos',
                'course_code' => 'INF-210',
                'room' => 'B201',
                'campus' => 'San Joaquin',
                'valid_from' => '2026-08-01',
                'valid_until' => '2026-12-20',
            ],
            [
                'weekday' => 'tuesday',
                'starts_at' => '12:00',
                'ends_at' => '13:30',
                'course_name' => 'Fisica',
                'room' => 'A102',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $preview = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import-preview&target_type=friend&friend_id=' . (int) $tomas['id'], 'POST', ['json' => $validJson], $cookieFile, $csrfHeader);
    friends_now_assert($preview['status'] === 200 && ($preview['json']['data']['total'] ?? 0) === 2 && ($preview['json']['data']['target']['label'] ?? '') === 'Tomas', 'Import preview for friend failed.');

    $import = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import&target_type=friend&friend_id=' . (int) $tomas['id'], 'POST', ['json' => $validJson], $cookieFile, $csrfHeader);
    friends_now_assert($import['status'] === 200 && ($import['json']['data']['imported'] ?? -1) === 2, 'Valid JSON import for friend failed.');

    $duplicate = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import&target_type=friend&friend_id=' . (int) $tomas['id'], 'POST', ['json' => $validJson], $cookieFile, $csrfHeader);
    friends_now_assert($duplicate['status'] === 200 && ($duplicate['json']['data']['skipped_duplicates'] ?? -1) === 2, 'Duplicate import was not skipped.');

    $userJson = json_encode([
        'version' => 1,
        'schedule' => [
            [
                'weekday' => 'wednesday',
                'starts_at' => '10:00',
                'ends_at' => '11:00',
                'course_name' => 'Mi horario JSON',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $importUser = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import&target_type=user', 'POST', ['json' => $userJson], $cookieFile, $csrfHeader);
    friends_now_assert($importUser['status'] === 200 && ($importUser['json']['data']['imported'] ?? -1) === 1, 'Valid JSON import for user schedule failed.');

    $invalidJson = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import-preview&target_type=user', 'POST', ['json' => '{bad'], $cookieFile, $csrfHeader);
    friends_now_assert($invalidJson['status'] === 422, 'Invalid JSON was not rejected.');

    $badWeekday = json_encode(['version' => 1, 'schedule' => [['weekday' => 'lunes', 'starts_at' => '08:00', 'ends_at' => '09:00', 'course_name' => 'Malo']]], JSON_THROW_ON_ERROR);
    $badWeekdayResponse = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import-preview&target_type=user', 'POST', ['json' => $badWeekday], $cookieFile, $csrfHeader);
    friends_now_assert($badWeekdayResponse['status'] === 422, 'Invalid weekday was not rejected.');

    $badHours = json_encode(['version' => 1, 'schedule' => [['weekday' => 'monday', 'starts_at' => '10:00', 'ends_at' => '09:00', 'course_name' => 'Malo']]], JSON_THROW_ON_ERROR);
    $badHoursResponse = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import-preview&target_type=user', 'POST', ['json' => $badHours], $cookieFile, $csrfHeader);
    friends_now_assert($badHoursResponse['status'] === 422, 'Invalid hours were not rejected.');

    $beforeCount = count($scheduleService->listEntries($userId, 'user', null, ['show' => 'all']));
    $mixedInvalid = json_encode(['version' => 1, 'schedule' => [
        ['weekday' => 'friday', 'starts_at' => '08:00', 'ends_at' => '09:00', 'course_name' => 'Valido no debe entrar'],
        ['weekday' => 'friday', 'starts_at' => '09:00', 'ends_at' => '08:00', 'course_name' => 'Invalido'],
    ]], JSON_THROW_ON_ERROR);
    $mixedResponse = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import&target_type=user', 'POST', ['json' => $mixedInvalid], $cookieFile, $csrfHeader);
    $afterCount = count($scheduleService->listEntries($userId, 'user', null, ['show' => 'all']));
    friends_now_assert($mixedResponse['status'] === 422 && $beforeCount === $afterCount, 'Invalid import was not transactional.');

    $jsonWithOwner = json_encode(['version' => 1, 'schedule' => [['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '09:00', 'course_name' => 'Malo', 'friend_id' => (int) $otherFriend['id']]]], JSON_THROW_ON_ERROR);
    $ownerResponse = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import-preview&target_type=user', 'POST', ['json' => $jsonWithOwner], $cookieFile, $csrfHeader);
    friends_now_assert($ownerResponse['status'] === 422, 'JSON was allowed to choose friend_id.');

    $foreignImport = friends_now_request('http://127.0.0.1/api/friends/schedule.php?action=import&target_type=friend&friend_id=' . (int) $otherFriend['id'], 'POST', ['json' => $validJson], $cookieFile, $csrfHeader);
    friends_now_assert($foreignImport['status'] === 422, 'Import allowed another user friend target.');

    echo "Friends now today import: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends now today import: FAILED\n");
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
