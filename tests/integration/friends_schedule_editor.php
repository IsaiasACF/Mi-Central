<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendService;
use Modules\Friends\FriendValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$friendService = new FriendService(new FriendRepository($pdo));
$scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
$username = 'test_schedule_editor_' . bin2hex(random_bytes(4));
$otherUsername = 'test_schedule_editor_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-schedule-');
$exitCode = 1;

function schedule_editor_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function schedule_editor_form_request(string $url, array $postFields, string $cookieFile): array
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

function schedule_editor_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function schedule_editor_screen_csrf(string $html): string
{
    if (preg_match('/data-friends-page[^>]+data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Friends CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function schedule_editor_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function schedule_editor_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (FriendValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/202608080007_create_friends_schedule_tables.php';
    $exceptionMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080008_add_user_schedule_exceptions.php';
    $migration($pdo);
    $exceptionMigration($pdo);

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $friend = $friendService->create($userId, [
        'name' => 'Tomas',
        'university' => 'USM',
        'default_campus' => 'San Joaquin',
        'is_active' => '1',
    ]);
    $otherFriend = $friendService->create($otherUserId, [
        'name' => 'Ajeno',
        'is_active' => '1',
    ]);

    $friendEntry = $scheduleService->createEntry($userId, 'friend', (int) $friend['id'], [
        'weekday' => 1,
        'starts_at' => '10:00',
        'ends_at' => '11:30',
        'course_name' => 'Bases de Datos',
        'course_code' => 'INF220',
        'room' => 'B201',
        'campus' => '',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
    ]);
    schedule_editor_assert((int) $friendEntry['weekday'] === 1, 'Friend schedule Monday was not stored.');
    schedule_editor_assert($friendEntry['effective_campus'] === 'San Joaquin', 'Friend default campus was not applied visually.');
    schedule_editor_assert($friendEntry['valid_from'] === '2026-01-01' && $friendEntry['valid_until'] === '2026-12-31', 'Validity period was not stored.');

    $sundayEntry = $scheduleService->createEntry($userId, 'friend', (int) $friend['id'], [
        'weekday' => 7,
        'starts_at' => '12:00',
        'ends_at' => '13:00',
        'course_name' => 'Domingo academico',
    ]);
    schedule_editor_assert((int) $sundayEntry['weekday'] === 7, 'Friend schedule Sunday was not accepted.');

    $overlap = $scheduleService->createEntry($userId, 'friend', (int) $friend['id'], [
        'weekday' => 1,
        'starts_at' => '11:00',
        'ends_at' => '12:00',
        'course_name' => 'Clase superpuesta',
    ]);
    schedule_editor_assert(!empty($overlap['has_overlap']), 'Overlap did not generate warning.');
    schedule_editor_assert(str_contains((string) ($overlap['warnings'][0] ?? ''), 'superpone'), 'Overlap warning text was missing.');

    $updatedFriendEntry = $scheduleService->updateEntry($userId, 'friend', (int) $friendEntry['id'], [
        'weekday' => 2,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'course_name' => 'Bases editadas',
        'course_code' => 'INF221',
        'room' => 'B202',
        'campus' => 'Casa Central',
        'valid_from' => '2026-03-01',
        'valid_until' => '2026-07-31',
    ], (int) $friend['id']);
    schedule_editor_assert($updatedFriendEntry !== null && $updatedFriendEntry['course_name'] === 'Bases editadas', 'Friend schedule update failed.');
    $scheduleService->createEntry($userId, 'friend', (int) $friend['id'], [
        'weekday' => 2,
        'starts_at' => '09:30',
        'ends_at' => '10:30',
        'course_name' => 'Ayudantia superpuesta',
    ]);

    $ownEntry = $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '14:00',
        'ends_at' => '15:30',
        'course_name' => 'Mi ramo',
        'room' => 'C301',
        'campus' => 'San Joaquin',
    ]);
    schedule_editor_assert((int) $ownEntry['user_id'] === $userId && (int) $ownEntry['weekday'] === 3, 'Own schedule block was not stored.');

    $updatedOwnEntry = $scheduleService->updateEntry($userId, 'user', (int) $ownEntry['id'], [
        'weekday' => 4,
        'starts_at' => '15:00',
        'ends_at' => '16:00',
        'course_name' => 'Mi ramo editado',
        'course_code' => 'USR200',
        'room' => 'C302',
        'campus' => 'Casa Central',
    ]);
    schedule_editor_assert($updatedOwnEntry !== null && (int) $updatedOwnEntry['weekday'] === 4, 'Own schedule update failed.');

    schedule_editor_reject(
        fn () => $scheduleService->createEntry($userId, 'user', null, [
            'weekday' => 1,
            'starts_at' => '10:00',
            'ends_at' => '10:00',
            'course_name' => 'Rango invalido',
        ]),
        'Service accepted ends_at <= starts_at.'
    );
    schedule_editor_reject(
        fn () => $scheduleService->createEntry($userId, 'friend', (int) $otherFriend['id'], [
            'weekday' => 1,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'course_name' => 'Ajeno',
        ]),
        'Service accepted another user friend schedule.'
    );

    $activeEntries = $scheduleService->listEntries($userId, 'friend', (int) $friend['id'], ['show' => 'active', 'date' => '2026-08-08']);
    $activeNames = json_encode(array_column($activeEntries, 'course_name'), JSON_THROW_ON_ERROR);
    schedule_editor_assert(!str_contains($activeNames, 'Bases editadas') && str_contains($activeNames, 'Clase superpuesta'), 'Active validity filter failed.');

    schedule_editor_assert($scheduleService->deleteEntry($userId, 'friend', (int) $sundayEntry['id']), 'Friend schedule delete failed.');
    schedule_editor_assert($scheduleService->deleteEntry($userId, 'user', (int) $ownEntry['id']), 'Own schedule delete failed.');

    $unauthenticatedApi = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php');
    schedule_editor_assert($unauthenticatedApi['status'] === 401, 'Schedule API did not require authentication.');

    $loginPage = schedule_editor_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = schedule_editor_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => schedule_editor_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    schedule_editor_assert($login['status'] === 302, 'Test user could not log in.');

    $schedulePage = schedule_editor_request('http://127.0.0.1/index.php?section=friends&tab=schedules&target=friend:' . (int) $friend['id'] . '&show=all', 'GET', null, $cookieFile);
    schedule_editor_assert($schedulePage['status'] === 200, 'Schedule page did not load.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'data-schedule-form') && str_contains($schedulePage['body'], 'Horarios'), 'Schedule UI was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'data-schedule-detail-exception') && str_contains($schedulePage['body'], 'data-schedule-exception-form'), 'Schedule exception UI was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'Bases editadas'), 'Schedule block was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'Este bloque se superpone'), 'Overlap warning was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'Mi horario') && str_contains($schedulePage['body'], 'Tomas'), 'Schedule target selector was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'data-schedule-current-weekday=') && str_contains($schedulePage['body'], 'role="tab"'), 'Mobile schedule day selector metadata was not rendered.');
    schedule_editor_assert(str_contains($schedulePage['body'], 'schedule-day-column__mobile-heading'), 'Mobile schedule day heading was not rendered.');
    schedule_editor_assert(!str_contains($schedulePage['body'], '--schedule-offset'), 'Initial schedule render should not rely on inline offset styles.');
    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    schedule_editor_assert(is_string($appCss) && str_contains($appCss, '@media (max-width: 768px)') && str_contains($appCss, '.schedule-mobile-days'), 'Mobile schedule breakpoint or day selector CSS is missing.');
    schedule_editor_assert(is_string($appCss) && str_contains($appCss, '.schedule-block') && str_contains($appCss, 'position: static'), 'Mobile schedule blocks are not rendered in vertical flow.');
    schedule_editor_assert(is_string($appCss) && str_contains($appCss, 'overflow-x: auto') && str_contains($appCss, 'white-space: nowrap'), 'Mobile tabs do not use constrained horizontal scrolling.');
    schedule_editor_assert(is_string($appJs) && str_contains($appJs, 'activateScheduleDay') && str_contains($appJs, 'aria-selected'), 'Mobile day selector JS behavior is missing.');
    schedule_editor_assert(is_string($appJs) && str_contains($appJs, 'layoutScheduleBlocks') && str_contains($appJs, 'requestAnimationFrame'), 'Schedule initial layout recalculation is missing.');
    $csrfHeader = ['X-CSRF-Token: ' . schedule_editor_screen_csrf($schedulePage['body'])];

    $missingExceptionCsrf = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?action=exceptions&target_type=friend&friend_id=' . (int) $friend['id'], 'POST', [
        'schedule_entry_id' => (string) $updatedFriendEntry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'cancelled',
    ], $cookieFile);
    schedule_editor_assert($missingExceptionCsrf['status'] === 403, 'Schedule exception API write did not require CSRF.');

    $apiException = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?action=exceptions&target_type=friend&friend_id=' . (int) $friend['id'], 'POST', [
        'schedule_entry_id' => (string) $updatedFriendEntry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'modified',
        'starts_at' => '18:00',
        'ends_at' => '19:10',
        'course_name' => 'Bases excepcion',
        'room' => 'B201',
    ], $cookieFile, $csrfHeader);
    schedule_editor_assert($apiException['status'] === 201 && ($apiException['json']['data']['type'] ?? '') === 'modified', 'Schedule exception API did not create exception.');

    $foreignException = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?action=exceptions&target_type=friend&friend_id=' . (int) $otherFriend['id'], 'POST', [
        'schedule_entry_id' => (string) $updatedFriendEntry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'cancelled',
    ], $cookieFile, $csrfHeader);
    schedule_editor_assert($foreignException['status'] === 422, 'Schedule exception API accepted another user friend.');

    $missingCsrf = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?target_type=user', 'POST', [
        'weekday' => 1,
        'starts_at' => '08:00',
        'ends_at' => '09:00',
        'course_name' => 'Sin CSRF',
    ], $cookieFile);
    schedule_editor_assert($missingCsrf['status'] === 403, 'Schedule API write did not require CSRF.');

    $apiCreateOwn = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?target_type=user', 'POST', [
        'weekday' => 5,
        'starts_at' => '08:00',
        'ends_at' => '09:00',
        'course_name' => 'API propia',
        'campus' => 'San Joaquin',
    ], $cookieFile, $csrfHeader);
    schedule_editor_assert($apiCreateOwn['status'] === 201 && ($apiCreateOwn['json']['data']['course_name'] ?? '') === 'API propia', 'Schedule API did not create own block.');
    $apiOwnId = (int) $apiCreateOwn['json']['data']['id'];

    $apiPatchOwn = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?target_type=user&id=' . $apiOwnId, 'PATCH', [
        'weekday' => 5,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'course_name' => 'API propia editada',
    ], $cookieFile, $csrfHeader);
    schedule_editor_assert($apiPatchOwn['status'] === 200 && ($apiPatchOwn['json']['data']['course_name'] ?? '') === 'API propia editada', 'Schedule API did not edit own block.');

    $foreignApi = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?target_type=friend&friend_id=' . (int) $otherFriend['id'], 'POST', [
        'weekday' => 1,
        'starts_at' => '08:00',
        'ends_at' => '09:00',
        'course_name' => 'Ajeno API',
    ], $cookieFile, $csrfHeader);
    schedule_editor_assert($foreignApi['status'] === 422, 'Schedule API accepted another user friend.');

    $apiDeleteOwn = schedule_editor_request('http://127.0.0.1/api/friends/schedule.php?target_type=user&id=' . $apiOwnId, 'DELETE', [], $cookieFile, $csrfHeader);
    schedule_editor_assert($apiDeleteOwn['status'] === 200 && ($apiDeleteOwn['json']['data']['deleted'] ?? false) === true, 'Schedule API did not delete own block.');

    echo "Friends schedule editor: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends schedule editor: FAILED\n");
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
