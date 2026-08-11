<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$friendService = new FriendService(new FriendRepository($pdo));
$scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
$username = 'test_friends_coincidences_page_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_coincidences_page_other_' . bin2hex(random_bytes(4));
$emptyUsername = 'test_friends_coincidences_page_empty_' . bin2hex(random_bytes(4));
$noOwnUsername = 'test_friends_coincidences_page_no_own_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$emptyPassword = 'test-secret-' . bin2hex(random_bytes(8));
$noOwnPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidences-page-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidences-page-other-');
$emptyCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidences-page-empty-');
$noOwnCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-coincidences-page-no-own-');
$exitCode = 1;

function friends_coincidences_page_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friends_coincidences_page_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null): array
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

function friends_coincidences_page_form_request(string $url, array $postFields, string $cookieFile): array
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

function friends_coincidences_page_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function friends_coincidences_page_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = friends_coincidences_page_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = friends_coincidences_page_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => friends_coincidences_page_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    friends_coincidences_page_assert($login['status'] === 302, 'Test user could not log in.');
}

function friends_coincidences_page_add_pair(FriendScheduleService $service, int $userId, string $targetType, ?int $friendId, int $weekday, string $firstStart, string $firstEnd, string $secondStart, string $secondEnd, string $campus): void
{
    $service->createEntry($userId, $targetType, $friendId, [
        'weekday' => $weekday,
        'starts_at' => $firstStart,
        'ends_at' => $firstEnd,
        'course_name' => 'Bloque 1',
        'campus' => $campus,
    ]);
    $service->createEntry($userId, $targetType, $friendId, [
        'weekday' => $weekday,
        'starts_at' => $secondStart,
        'ends_at' => $secondEnd,
        'course_name' => 'Bloque 2',
        'campus' => $campus,
    ]);
}

function friends_coincidences_page_time(int $minutes): string
{
    $minutes = max(0, min(1439, $minutes));

    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

try {
    $baseMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080007_create_friends_schedule_tables.php';
    $exceptionMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080008_add_user_schedule_exceptions.php';
    $baseMigration($pdo);
    $exceptionMigration($pdo);

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $emptyUserId = $auth->createUser($emptyUsername, $emptyPassword);
    $noOwnUserId = $auth->createUser($noOwnUsername, $noOwnPassword);

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
    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Telecomunicaciones',
        'course_code' => 'TEL351',
        'room' => 'A010',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($emptyUserId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Mi horario',
        'campus' => 'CSJ',
    ]);

    $tomas = $friendService->create($userId, ['name' => 'Tomas', 'default_campus' => 'CSJ']);
    $felipe = $friendService->create($userId, ['name' => 'Felipe', 'default_campus' => 'CSJ']);
    $paula = $friendService->create($userId, ['name' => 'Paula', 'default_campus' => 'Casa Central']);
    $unknown = $friendService->create($userId, ['name' => 'Sin campus']);
    $noSchedule = $friendService->create($userId, ['name' => 'Sin horario']);
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
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Arquitectura',
        'course_code' => 'INF325',
        'room' => 'A014',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $paula['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'campus' => 'Casa Central',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $unknown['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'campus' => '',
    ]);

    $modified = $friendService->create($userId, ['name' => 'Modificado', 'default_campus' => 'CSJ']);
    $modifiedEntry = $scheduleService->createEntry($userId, 'friend', (int) $modified['id'], [
        'weekday' => 3,
        'starts_at' => '12:00',
        'ends_at' => '13:00',
        'course_name' => 'Original',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $modified['id'], [
        'weekday' => 3,
        'starts_at' => '18:00',
        'ends_at' => '19:00',
        'course_name' => 'Despues',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $modified['id'], [
        'schedule_entry_id' => (string) $modifiedEntry['id'],
        'exception_date' => $date,
        'type' => 'modified',
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Telecomunicaciones',
        'course_code' => 'tel351',
    ]);

    $noOwnFriend = $friendService->create($noOwnUserId, ['name' => 'Con horario']);
    friends_coincidences_page_add_pair($scheduleService, $noOwnUserId, 'friend', (int) $noOwnFriend['id'], 3, '09:00', '10:00', '12:00', '13:00', 'CSJ');

    $unauthenticated = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences');
    friends_coincidences_page_assert($unauthenticated['status'] === 302, 'Coincidences page should require authentication.');

    friends_coincidences_page_login($username, $password, $cookieFile);

    $page = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date, 'GET', null, $cookieFile);
    friends_coincidences_page_assert($page['status'] === 200 && str_contains($page['body'], 'Coincidencias para'), 'Coincidences page did not render.');
    friends_coincidences_page_assert(!str_contains($page['body'], 'Duracion minima') && !str_contains($page['body'], 'minimum_minutes') && str_contains($page['body'], 'Mismo campus'), 'Coincidence filters did not render correctly.');
    friends_coincidences_page_assert(str_contains($page['body'], 'Tomas') && str_contains($page['body'], '14:40 - 15:50'), 'Individual coincidences were not rendered.');
    friends_coincidences_page_assert(str_contains($page['body'], 'Mismo ramo · INF309') && str_contains($page['body'], 'Coincidencia de horario'), 'Coincidence classification was not rendered.');
    friends_coincidences_page_assert(str_contains($page['body'], '<strong>Tu</strong> INF309 · K200') && str_contains($page['body'], '<strong>Felipe</strong> INF325 · A014'), 'Coincidence block details were not rendered.');
    friends_coincidences_page_assert(str_contains($page['body'], 'Coincidencias grupales') && str_contains($page['body'], 'personas con actividad simultanea'), 'Group coincidences were not rendered.');
    friends_coincidences_page_assert(str_contains($page['body'], 'Campus no determinado'), 'Unknown campus state was not rendered.');
    friends_coincidences_page_assert(str_contains($page['body'], 'Modificado') && str_contains($page['body'], '16:05 - 17:15'), 'Exceptions were not reflected on coincidences page.');
    friends_coincidences_page_assert(!str_contains($page['body'], '15:50 - 16:05'), 'Breaks should not be rendered as coincidences.');
    friends_coincidences_page_assert(!str_contains($page['body'], '<h3>Sin horario</h3>'), 'Friend without schedule was treated as free.');

    $friendFiltered = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date . '&friend_id=' . (int) $tomas['id'], 'GET', null, $cookieFile);
    friends_coincidences_page_assert($friendFiltered['status'] === 200 && str_contains($friendFiltered['body'], '<h3>Tomas</h3>') && !str_contains($friendFiltered['body'], '<h3>Felipe</h3>'), 'Friend filter did not limit rendered results.');

    $sameCampus = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date . '&same_campus_only=1', 'GET', null, $cookieFile);
    friends_coincidences_page_assert($sameCampus['status'] === 200 && str_contains($sameCampus['body'], 'Mismo campus segun horario'), 'Same campus filter did not keep same-campus results.');
    friends_coincidences_page_assert(!str_contains($sameCampus['body'], '<h3>Paula</h3>') && !str_contains($sameCampus['body'], '<h3>Sin campus</h3>'), 'Same campus filter included different or unknown campus.');

    $invalidDate = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=bad-date', 'GET', null, $cookieFile);
    friends_coincidences_page_assert($invalidDate['status'] === 422 && str_contains($invalidDate['body'], 'Parametros invalidos'), 'Invalid date was not rejected.');

    $foreignFriend = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date . '&friend_id=' . (int) $otherFriend['id'], 'GET', null, $cookieFile);
    friends_coincidences_page_assert($foreignFriend['status'] === 422, 'Foreign friend filter was not rejected.');

    $today = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $todayDate = $today->format('Y-m-d');
    $todayWeekday = (int) $today->format('N');
    $nowMinutes = ((int) $today->format('H') * 60) + (int) $today->format('i');
    $classStart = max(0, $nowMinutes - 20);
    $classEnd = min(1439, max($nowMinutes + 40, $classStart + 10));

    $currentFriend = $friendService->create($userId, ['name' => 'Actual', 'default_campus' => 'CSJ']);
    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => $todayWeekday,
        'starts_at' => friends_coincidences_page_time($classStart),
        'ends_at' => friends_coincidences_page_time($classEnd),
        'course_name' => 'Clase actual',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $currentFriend['id'], [
        'weekday' => $todayWeekday,
        'starts_at' => friends_coincidences_page_time($classStart),
        'ends_at' => friends_coincidences_page_time($classEnd),
        'course_name' => 'Clase actual amigo',
        'campus' => 'CSJ',
    ]);

    $todayPage = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $todayDate . '&friend_id=' . (int) $currentFriend['id'], 'GET', null, $cookieFile);
    friends_coincidences_page_assert($todayPage['status'] === 200 && str_contains($todayPage['body'], 'Coincidencia ahora'), 'Current coincidence summary did not render.');

    if ($nowMinutes >= 90) {
        $pastFriend = $friendService->create($userId, ['name' => 'Pasado', 'default_campus' => 'CSJ']);
        $scheduleService->createEntry($userId, 'user', null, ['weekday' => $todayWeekday, 'starts_at' => '00:00', 'ends_at' => '00:40', 'course_name' => 'Pasada', 'campus' => 'CSJ']);
        $scheduleService->createEntry($userId, 'friend', (int) $pastFriend['id'], ['weekday' => $todayWeekday, 'starts_at' => '00:00', 'ends_at' => '00:40', 'course_name' => 'Pasada amigo', 'campus' => 'CSJ']);
        $pastPage = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $todayDate . '&friend_id=' . (int) $pastFriend['id'], 'GET', null, $cookieFile);
        friends_coincidences_page_assert($pastPage['status'] === 200 && str_contains($pastPage['body'], 'Finalizado'), 'Past intervals were not marked as finished.');
    }

    friends_coincidences_page_login($emptyUsername, $emptyPassword, $emptyCookieFile);
    $emptyPage = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date, 'GET', null, $emptyCookieFile);
    friends_coincidences_page_assert($emptyPage['status'] === 200 && str_contains($emptyPage['body'], 'No tienes amigos activos'), 'No-friends state did not render.');

    friends_coincidences_page_login($noOwnUsername, $noOwnPassword, $noOwnCookieFile);
    $noOwnPage = friends_coincidences_page_request('http://127.0.0.1/index.php?section=friends&tab=coincidences&date=' . $date, 'GET', null, $noOwnCookieFile);
    friends_coincidences_page_assert($noOwnPage['status'] === 200 && str_contains($noOwnPage['body'], 'Necesitas configurar Mi horario'), 'No-own-schedule state did not render.');

    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    friends_coincidences_page_assert(is_string($appCss) && str_contains($appCss, '.coincidence-layout') && str_contains($appCss, '.task-filters--coincidences'), 'Coincidences responsive styles are missing.');

    echo "Friends coincidences page: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends coincidences page: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ([$cookieFile, $otherCookieFile, $emptyCookieFile, $noOwnCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username, :empty_username, :no_own_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'empty_username' => $emptyUsername,
        'no_own_username' => $noOwnUsername,
    ]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username, :empty_username, :no_own_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'empty_username' => $emptyUsername,
        'no_own_username' => $noOwnUsername,
    ]);
}

exit($exitCode);
