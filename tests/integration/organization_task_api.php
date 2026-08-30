<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$username = 'test_task_api_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-task-api-');
$exitCode = 1;

function task_api_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $responseBody = substr($response, $headerSize);
    $json = json_decode($responseBody, true);

    return [
        'status' => (int) $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
    ];
}

function task_api_form_request(string $url, array $postFields, string $cookieFile): array
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

function task_api_csrf_from_html(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function assert_task_api(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $userId = $auth->createUser($username, $password);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $unauthenticated = task_api_request('http://127.0.0.1/api/organization/tasks.php');
    assert_task_api($unauthenticated['status'] === 401, 'Task API did not require authentication.');

    $loginPage = task_api_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    assert_task_api($loginPage['status'] === 200, 'Login page did not load.');

    $csrfToken = task_api_csrf_from_html($loginPage['body']);
    $login = task_api_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => $csrfToken,
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    assert_task_api($login['status'] === 302, 'Test user could not log in.');

    $missingCsrf = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        ['title' => 'Sin CSRF'],
        $cookieFile
    );
    assert_task_api($missingCsrf['status'] === 403, 'Task API write did not require CSRF.');

    $statement = $pdo->prepare(
        "SELECT id FROM organization_spaces WHERE user_id = :user_id AND slug = 'universidad' LIMIT 1"
    );
    $statement->execute(['user_id' => $userId]);
    $spaceId = (int) $statement->fetchColumn();
    assert_task_api($spaceId > 0, 'Seeded space was not found.');

    $csrfHeader = ['X-CSRF-Token: ' . $csrfToken];
    $create = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        [
            'title' => 'Tarea desde API',
            'space_id' => $spaceId,
            'due_at' => '2026-08-15 12:00:00',
        ],
        $cookieFile,
        $csrfHeader
    );
    assert_task_api($create['status'] === 201 && $create['json']['ok'] === true, 'Task API did not create a task.');
    $taskId = (int) $create['json']['data']['id'];

    $get = task_api_request('http://127.0.0.1/api/organization/tasks.php?id=' . $taskId, 'GET', null, $cookieFile);
    assert_task_api($get['status'] === 200 && $get['json']['data']['title'] === 'Tarea desde API', 'Task API did not fetch a task.');

    $list = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php?space_id=' . $spaceId,
        'GET',
        null,
        $cookieFile
    );
    assert_task_api($list['status'] === 200 && count($list['json']['data']) >= 1, 'Task API list filters did not work.');

    $update = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId,
        'PATCH',
        ['title' => 'Tarea actualizada por API', 'priority' => 'urgent'],
        $cookieFile,
        $csrfHeader
    );
    assert_task_api(
        $update['status'] === 200 && $update['json']['data']['title'] === 'Tarea actualizada por API',
        'Task API did not update a task.'
    );

    $complete = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId . '&action=complete',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_task_api(
        $complete['status'] === 200
        && $complete['json']['data']['status'] === 'completed'
        && $complete['json']['data']['completed_at'] !== null,
        'Task API did not complete a task.'
    );

    $reopen = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId . '&action=reopen',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_task_api(
        $reopen['status'] === 200
        && $reopen['json']['data']['status'] === 'pending'
        && $reopen['json']['data']['completed_at'] === null,
        'Task API did not reopen a task.'
    );

    $delete = task_api_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId,
        'DELETE',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_task_api($delete['status'] === 200 && $delete['json']['data']['deleted'] === true, 'Task API did not delete a task.');

    $deletedGet = task_api_request('http://127.0.0.1/api/organization/tasks.php?id=' . $taskId, 'GET', null, $cookieFile);
    assert_task_api($deletedGet['status'] === 404, 'Task API returned a deleted task.');

    echo "Organization task API: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization task API: FAILED\n");
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
