<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$taskService = new TaskService(new TaskRepository($pdo));
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_inbox_' . bin2hex(random_bytes(4));
$otherUsername = 'test_inbox_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-inbox-');
$exitCode = 1;

function inbox_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function inbox_form_request(string $url, array $postFields, string $cookieFile): array
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

function inbox_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function inbox_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function inbox_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inbox_count_by_title(PDO $pdo, int $userId, string $title): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM organization_tasks WHERE user_id = :user_id AND title = :title'
    );
    $statement->execute([
        'user_id' => $userId,
        'title' => $title,
    ]);

    return (int) $statement->fetchColumn();
}

try {
    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

    if (!is_string($appJs)) {
        throw new RuntimeException('Could not read app.js.');
    }

    inbox_assert(str_contains($appJs, 'inboxQuickInitialized'), 'Dashboard quick inbox form is not protected against repeated initialization.');
    inbox_assert(str_contains($appJs, 'var pending = false'), 'Dashboard quick inbox form is not protected against double submit.');
    inbox_assert(str_contains($appJs, 'setQuickPending(true)'), 'Dashboard quick inbox form does not disable controls while saving.');

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaces = $spaceRepository->listForUser($userId);
    $otherSpaces = $spaceRepository->listForUser($otherUserId);
    $spaceId = (int) $spaces[0]['id'];
    $otherSpaceId = (int) $otherSpaces[0]['id'];

    $taskService->create($userId, [
        'title' => 'Tarea con espacio fuera de Bandeja',
        'space_id' => $spaceId,
    ]);
    $taskService->create($otherUserId, [
        'title' => 'Inbox de otro usuario',
        'space_id' => null,
    ]);

    $loginPage = inbox_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $loginToken = inbox_login_csrf($loginPage['body']);
    $login = inbox_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    inbox_assert($login['status'] === 302, 'Test user could not log in.');

    $homePage = inbox_request('http://127.0.0.1/index.php', 'GET', null, $cookieFile);
    inbox_assert($homePage['status'] === 200, 'Dashboard did not load.');
    inbox_assert(str_contains($homePage['body'], 'data-inbox-quick'), 'Dashboard quick inbox form was not rendered.');
    inbox_assert(str_contains($homePage['body'], 'Bandeja vacia. No tienes nada pendiente de organizar.'), 'Dashboard inbox empty state was not rendered.');

    $csrfHeader = ['X-CSRF-Token: ' . inbox_screen_csrf($homePage['body'])];
    $quickTitle = 'Captura rapida HTTP';
    $beforeCount = inbox_count_by_title($pdo, $userId, $quickTitle);
    $create = inbox_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        [
            'title' => $quickTitle,
            'space_id' => '',
            'due_at' => '',
        ],
        $cookieFile,
        $csrfHeader
    );
    inbox_assert($create['status'] === 201 && $create['json']['ok'] === true, 'Quick inbox task was not created.');
    $task = $create['json']['data'];
    $taskId = (int) $task['id'];
    inbox_assert(inbox_count_by_title($pdo, $userId, $quickTitle) === $beforeCount + 1, 'Quick inbox create did not create exactly one task.');
    inbox_assert($task['space_id'] === null, 'Quick inbox task did not keep space_id NULL.');
    inbox_assert($task['status'] === 'pending', 'Quick inbox task did not default to pending.');
    inbox_assert($task['priority'] === 'normal', 'Quick inbox task did not keep legacy priority default.');
    inbox_assert($task['due_at'] === null, 'Quick inbox task unexpectedly has a due_at value.');

    $dashboardAfterCreate = inbox_request('http://127.0.0.1/index.php', 'GET', null, $cookieFile);
    inbox_assert(str_contains($dashboardAfterCreate['body'], $quickTitle), 'Dashboard did not show recent inbox task.');
    inbox_assert(str_contains($dashboardAfterCreate['body'], 'pendiente por organizar'), 'Dashboard did not show inbox count.');

    $inboxPage = inbox_request('http://127.0.0.1/index.php?section=organization&space=inbox&status=all', 'GET', null, $cookieFile);
    inbox_assert($inboxPage['status'] === 200, 'Inbox page did not load.');
    inbox_assert(str_contains($inboxPage['body'], 'Organizacion'), 'Organization page title was not rendered.');
    inbox_assert(str_contains($inboxPage['body'], 'value="inbox"') && str_contains($inboxPage['body'], 'data-space-id="none"'), 'Inbox filter was not rendered.');
    inbox_assert(str_contains($inboxPage['body'], $quickTitle), 'Inbox task did not appear in Bandeja.');
    inbox_assert(!str_contains($inboxPage['body'], 'Tarea con espacio fuera de Bandeja'), 'Task with space appeared in Bandeja.');
    inbox_assert(!str_contains($inboxPage['body'], 'Inbox de otro usuario'), 'Other user inbox task appeared.');

    $complete = inbox_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId . '&action=complete',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    inbox_assert($complete['status'] === 200 && $complete['json']['data']['status'] === 'completed', 'Inbox complete failed.');

    $reopen = inbox_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId . '&action=reopen',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    inbox_assert($reopen['status'] === 200 && $reopen['json']['data']['status'] === 'pending', 'Inbox reopen failed.');

    $organize = inbox_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $taskId,
        'PATCH',
        [
            'title' => $quickTitle,
            'description' => '',
            'space_id' => (string) $spaceId,
            'due_at' => '2026-08-20T10:30',
        ],
        $cookieFile,
        $csrfHeader
    );
    inbox_assert($organize['status'] === 200 && (int) $organize['json']['data']['space_id'] === $spaceId, 'Inbox organize did not assign space.');

    $inboxAfterOrganize = inbox_request('http://127.0.0.1/index.php?section=organization&space=inbox&status=all', 'GET', null, $cookieFile);
    inbox_assert(!str_contains($inboxAfterOrganize['body'], $quickTitle), 'Organized task still appeared in Bandeja.');

    $listInbox = inbox_request('http://127.0.0.1/api/organization/tasks.php?space_id=none', 'GET', null, $cookieFile);
    inbox_assert($listInbox['status'] === 200, 'Inbox API list failed.');

    foreach ($listInbox['json']['data'] as $row) {
        inbox_assert($row['space_id'] === null, 'Inbox API returned a task with space_id.');
    }

    echo "Organization inbox: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization inbox: FAILED\n");
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
