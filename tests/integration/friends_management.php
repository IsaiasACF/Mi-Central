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
$username = 'test_friends_ui_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_ui_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-friends-');
$exitCode = 1;

function friends_management_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function friends_management_form_request(string $url, array $postFields, string $cookieFile): array
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

function friends_management_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function friends_management_screen_csrf(string $html): string
{
    if (preg_match('/data-friends-page[^>]+data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Friends CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function friends_management_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friends_management_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (FriendValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);

    $activeFriend = $friendService->create($userId, [
        'name' => 'Tomas',
        'university' => 'USM',
        'default_campus' => 'San Joaquin',
        'notes' => 'Nota privada',
        'is_active' => '1',
    ]);
    $inactiveFriend = $friendService->create($userId, [
        'name' => 'Felipe',
        'university' => 'USM',
        'default_campus' => 'Casa Central',
        'is_active' => '0',
    ]);
    $otherFriend = $friendService->create($otherUserId, [
        'name' => 'Amigo ajeno',
    ]);

    friends_management_assert((int) $activeFriend['user_id'] === $userId, 'Friend was not assigned to owner.');
    friends_management_assert(count($friendService->list($userId, ['status' => 'active'])) === 1, 'Active filter failed.');
    friends_management_assert(count($friendService->list($userId, ['status' => 'all'])) === 2, 'All filter failed.');
    friends_management_assert(count($friendService->list($userId, ['status' => 'inactive'])) === 1, 'Inactive filter failed.');

    $updated = $friendService->update($userId, (int) $activeFriend['id'], [
        'name' => 'Tomas',
        'university' => 'USM',
        'default_campus' => 'San Joaquin',
        'notes' => 'Editado',
        'is_active' => '1',
    ]);
    friends_management_assert($updated !== null && $updated['name'] === 'Tomas', 'Friend update failed.');

    $deactivated = $friendService->setActive($userId, (int) $activeFriend['id'], false);
    friends_management_assert($deactivated !== null && (int) $deactivated['is_active'] === 0, 'Friend deactivation failed.');
    $reactivated = $friendService->setActive($userId, (int) $activeFriend['id'], true);
    friends_management_assert($reactivated !== null && (int) $reactivated['is_active'] === 1, 'Friend reactivation failed.');

    friends_management_reject(
        fn () => $friendService->create($userId, ['name' => '']),
        'Service accepted empty friend name.'
    );

    friends_management_assert($friendService->get($userId, (int) $otherFriend['id']) === null, 'User could read another user friend.');
    friends_management_assert($friendService->update($userId, (int) $otherFriend['id'], ['name' => 'Ajeno editado']) === null, 'User could update another user friend.');
    friends_management_assert($friendService->setActive($userId, (int) $otherFriend['id'], false) === null, 'User could change another user friend status.');
    friends_management_assert(!$friendService->delete($userId, (int) $otherFriend['id']), 'User could delete another user friend.');

    $entry = $scheduleService->createFriendScheduleEntry($userId, (int) $activeFriend['id'], [
        'weekday' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'course_name' => 'Calculo',
    ]);
    $scheduleService->createFriendScheduleException($userId, (int) $activeFriend['id'], [
        'schedule_entry_id' => (string) $entry['id'],
        'exception_date' => '2026-08-10',
        'type' => 'cancelled',
    ]);
    $withCounts = $friendService->get($userId, (int) $activeFriend['id']);
    friends_management_assert(
        $withCounts !== null
        && (int) $withCounts['schedule_entries_count'] === 1
        && (int) $withCounts['schedule_exceptions_count'] === 1,
        'Friend did not expose related schedule counts.'
    );

    $scriptName = '<script>alert(1)</script>';
    $friendService->create($userId, [
        'name' => $scriptName,
        'is_active' => '1',
    ]);

    $unauthenticatedApi = friends_management_request('http://127.0.0.1/api/friends/friends.php');
    friends_management_assert($unauthenticatedApi['status'] === 401, 'Friends API did not require authentication.');

    $unauthenticatedPage = friends_management_request('http://127.0.0.1/index.php?section=friends');
    friends_management_assert($unauthenticatedPage['status'] === 302 && str_contains($unauthenticatedPage['headers'], 'Location: /login.php'), 'Friends page did not reject unauthenticated user.');

    $loginPage = friends_management_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = friends_management_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => friends_management_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    friends_management_assert($login['status'] === 302, 'Test user could not log in.');

    $friendsPage = friends_management_request('http://127.0.0.1/index.php?section=friends&tab=friends', 'GET', null, $cookieFile);
    friends_management_assert($friendsPage['status'] === 200, 'Friends page did not load.');
    friends_management_assert(str_contains($friendsPage['body'], 'data-friend-form') && str_contains($friendsPage['body'], 'Horarios'), 'Friends UI was not rendered.');
    friends_management_assert(!str_contains($friendsPage['body'], $scriptName), 'Friend content was rendered without escaping.');
    friends_management_assert(str_contains($friendsPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Escaped friend content was not shown.');
    foreach (['friends-now', 'friends-today', 'friends-week', 'friends-coincidences', 'friends-people', 'friends-schedules'] as $oldSection) {
        friends_management_assert(!str_contains($friendsPage['body'], $oldSection), 'Old friends sidebar section was still rendered.');
    }

    $csrfHeader = ['X-CSRF-Token: ' . friends_management_screen_csrf($friendsPage['body'])];

    $missingCsrf = friends_management_request('http://127.0.0.1/api/friends/friends.php', 'POST', [
        'name' => 'Sin token',
    ], $cookieFile);
    friends_management_assert($missingCsrf['status'] === 403, 'Friends API write did not require CSRF.');

    $apiCreate = friends_management_request('http://127.0.0.1/api/friends/friends.php', 'POST', [
        'name' => 'Camila',
        'university' => 'USM',
        'default_campus' => 'San Joaquin',
        'notes' => 'Creada por API',
        'is_active' => '1',
    ], $cookieFile, $csrfHeader);
    friends_management_assert($apiCreate['status'] === 201 && ($apiCreate['json']['ok'] ?? false) === true, 'Friends API did not create friend.');
    $apiFriendId = (int) $apiCreate['json']['data']['id'];

    $apiInactiveList = friends_management_request('http://127.0.0.1/api/friends/friends.php?status=inactive', 'GET', null, $cookieFile);
    friends_management_assert($apiInactiveList['status'] === 200 && count($apiInactiveList['json']['data']) === 1, 'Friends API inactive filter failed.');

    $apiPatch = friends_management_request('http://127.0.0.1/api/friends/friends.php?id=' . $apiFriendId, 'PATCH', [
        'name' => 'Camila editada',
        'university' => '',
        'default_campus' => 'Casa Central',
        'notes' => 'Editada por API',
        'is_active' => '1',
    ], $cookieFile, $csrfHeader);
    friends_management_assert($apiPatch['status'] === 200 && $apiPatch['json']['data']['name'] === 'Camila editada', 'Friends API update failed.');

    $apiDeactivate = friends_management_request('http://127.0.0.1/api/friends/friends.php?id=' . $apiFriendId . '&action=deactivate', 'POST', [], $cookieFile, $csrfHeader);
    friends_management_assert($apiDeactivate['status'] === 200 && (int) $apiDeactivate['json']['data']['is_active'] === 0, 'Friends API deactivate failed.');

    $apiReactivate = friends_management_request('http://127.0.0.1/api/friends/friends.php?id=' . $apiFriendId . '&action=activate', 'POST', [], $cookieFile, $csrfHeader);
    friends_management_assert($apiReactivate['status'] === 200 && (int) $apiReactivate['json']['data']['is_active'] === 1, 'Friends API activate failed.');

    $foreignDelete = friends_management_request('http://127.0.0.1/api/friends/friends.php?id=' . (int) $otherFriend['id'], 'DELETE', [], $cookieFile, $csrfHeader);
    friends_management_assert($foreignDelete['status'] === 404, 'Friends API allowed deleting another user friend.');

    $apiDelete = friends_management_request('http://127.0.0.1/api/friends/friends.php?id=' . $apiFriendId, 'DELETE', [], $cookieFile, $csrfHeader);
    friends_management_assert($apiDelete['status'] === 200 && ($apiDelete['json']['data']['deleted'] ?? false) === true, 'Friends API delete failed.');
    friends_management_assert($friendService->get($userId, $apiFriendId) === null, 'Deleted API friend still exists.');

    friends_management_assert($friendService->delete($userId, (int) $activeFriend['id']), 'Service delete failed.');
    $remainingEntries = $pdo->prepare('SELECT COUNT(*) FROM friend_schedule_entries WHERE friend_id = :friend_id');
    $remainingEntries->execute(['friend_id' => (int) $activeFriend['id']]);
    friends_management_assert((int) $remainingEntries->fetchColumn() === 0, 'Deleting friend did not cascade schedule entries.');

    echo "Friends management: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends management: FAILED\n");
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
