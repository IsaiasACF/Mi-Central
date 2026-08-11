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
$username = 'test_tasks_page_' . bin2hex(random_bytes(4));
$otherUsername = 'test_tasks_page_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-tasks-page-');
$exitCode = 1;

function tasks_page_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function tasks_page_form_request(string $url, array $postFields, string $cookieFile): array
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

function tasks_page_csrf_from_html(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function tasks_page_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Task page CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function assert_tasks_page(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tasks_page_app_js_count(string $html): int
{
    return preg_match_all('/src="\/assets\/js\/app\.js(?:\?[^"]*)?"/', $html);
}

function count_user_tasks(PDO $pdo, int $userId, string $title): int
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

function task_test_due_at(string $modifier): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('America/Santiago')))->format('Y-m-d H:i:s');
}

try {
    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

    if (!is_string($appJs)) {
        throw new RuntimeException('Could not read app.js.');
    }

    assert_tasks_page(str_contains($appJs, 'page.dataset.tasksInitialized'), 'Task JavaScript is not protected against repeated initialization.');
    assert_tasks_page(str_contains($appJs, 'formPending'), 'Task form is not protected against double submit.');
    assert_tasks_page(str_contains($appJs, 'setFormPending(true)'), 'Task form does not disable controls while saving.');
    assert_tasks_page(str_contains($appJs, 'navToggles.forEach'), 'Sidebar collapsible behavior was not registered.');

    $unauthenticated = tasks_page_request('http://127.0.0.1/index.php?section=organization', 'GET', null, $cookieFile);
    assert_tasks_page(
        $unauthenticated['status'] === 302 && str_contains($unauthenticated['headers'], 'Location: /login.php'),
        'Tasks page did not require authentication.'
    );

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaces = $spaceRepository->listForUser($userId);
    $otherSpaces = $spaceRepository->listForUser($otherUserId);
    $spaceId = (int) $spaces[0]['id'];
    $otherSpaceId = (int) $otherSpaces[0]['id'];
    $statement = $pdo->prepare(
        'INSERT INTO organization_projects (user_id, space_id, title, status)
         VALUES (:user_id, :space_id, :title, :status)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'title' => 'Proyecto oculto en tareas',
        'status' => 'active',
    ]);
    $projectId = (int) $pdo->lastInsertId();

    $unsafeTitle = '<script>alert(1)</script>';
    $taskService->create($userId, [
        'title' => $unsafeTitle,
        'description' => '<b>detalle</b>',
        'space_id' => $spaceId,
        'priority' => 'normal',
    ]);
    $completedTask = $taskService->create($userId, [
        'title' => 'Tarea completada sin espacio',
        'priority' => 'high',
        'status' => 'completed',
    ]);
    $todayTask = $taskService->create($userId, [
        'title' => 'Filtro hoy universidad alta',
        'space_id' => $spaceId,
        'priority' => 'high',
        'due_at' => task_test_due_at('today 23:59'),
    ]);
    $overdueTask = $taskService->create($userId, [
        'title' => 'Filtro vencida bandeja',
        'priority' => 'normal',
        'due_at' => task_test_due_at('-1 hour'),
    ]);
    $taskService->create($userId, [
        'title' => 'Filtro sin vencimiento',
        'priority' => 'low',
    ]);
    $taskService->create($userId, [
        'title' => 'Tarea de proyecto no mezclada',
        'project_id' => $projectId,
    ]);
    $taskService->create($otherUserId, [
        'title' => 'Tarea de otro usuario',
        'space_id' => $otherSpaceId,
    ]);

    $loginPage = tasks_page_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $loginToken = tasks_page_csrf_from_html($loginPage['body']);
    $login = tasks_page_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    assert_tasks_page($login['status'] === 302, 'Test user could not log in.');

    $homePage = tasks_page_request('http://127.0.0.1/index.php', 'GET', null, $cookieFile);
    assert_tasks_page($homePage['status'] === 200, 'Authenticated home page did not load.');
    assert_tasks_page(tasks_page_app_js_count($homePage['body']) === 1, 'App JavaScript was loaded more than once.');
    assert_tasks_page(substr_count($homePage['body'], 'data-nav-toggle') === 2, 'Sidebar collapsible buttons were not rendered.');
    assert_tasks_page(substr_count($homePage['body'], 'data-nav-panel hidden') === 2, 'Sidebar groups were not collapsed on home.');
    assert_tasks_page(str_contains($homePage['body'], 'href="/index.php?section=organization"'), 'Organization direct sidebar link was not rendered.');
    assert_tasks_page(str_contains($homePage['body'], 'href="/index.php?section=friends"'), 'Friends direct sidebar link was not rendered.');
    foreach (['organization-inbox', 'organization-tasks', 'organization-university', 'organization-work'] as $legacySection) {
        assert_tasks_page(!str_contains($homePage['body'], $legacySection), 'Legacy organization sidebar section was rendered.');
    }
    foreach (['friends-now', 'friends-today', 'friends-week', 'friends-coincidences', 'friends-people', 'friends-schedules'] as $legacySection) {
        assert_tasks_page(!str_contains($homePage['body'], $legacySection), 'Legacy friends sidebar section was rendered.');
    }

    $tasksPage = tasks_page_request('http://127.0.0.1/index.php?section=organization', 'GET', null, $cookieFile);
    assert_tasks_page($tasksPage['status'] === 200, 'Authenticated tasks page did not load.');
    assert_tasks_page(str_contains($tasksPage['body'], 'Organizacion'), 'Organization page title was not rendered.');
    assert_tasks_page(str_contains($tasksPage['body'], 'Tareas') && str_contains($tasksPage['body'], 'Proyectos') && str_contains($tasksPage['body'], 'Notas') && str_contains($tasksPage['body'], 'Calendario'), 'Organization tabs were not rendered.');
    assert_tasks_page(str_contains($tasksPage['body'], 'organization-tab is-active'), 'Tasks tab was not active by default.');
    assert_tasks_page(str_contains($tasksPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'User content was not escaped.');
    assert_tasks_page(!str_contains($tasksPage['body'], $unsafeTitle), 'Raw user content appeared in HTML.');
    assert_tasks_page(!str_contains($tasksPage['body'], 'Tarea de otro usuario'), 'Task owned by another user appeared.');
    assert_tasks_page(!str_contains($tasksPage['body'], 'Tarea de proyecto no mezclada'), 'Project task appeared in general tasks tab.');
    assert_tasks_page(tasks_page_app_js_count($tasksPage['body']) === 1, 'Task page loaded app JavaScript more than once.');
    assert_tasks_page(str_contains($tasksPage['body'], 'aria-current="page"'), 'Active sidebar link was not marked.');
    foreach (['Todos', 'Hoy', 'Esta semana', 'Vencidos', 'Bandeja', 'Universidad', 'Amigos', 'Personal', 'Trabajo'] as $label) {
        assert_tasks_page(str_contains($tasksPage['body'], $label), "Missing organization filter label: {$label}");
    }

    $completedPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&status=completed',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($completedPage['body'], 'Tarea completada sin espacio'), 'Completed filter did not show completed task.');
    assert_tasks_page(!str_contains($completedPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Completed filter showed pending task.');

    $inboxSpacePage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&status=all&space=inbox',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($inboxSpacePage['body'], 'Tarea completada sin espacio'), 'Inbox filter did not show no-space task.');
    assert_tasks_page(!str_contains($inboxSpacePage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Inbox filter showed task with space.');

    $spacePage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&space=universidad',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($spacePage['body'], 'Filtro hoy universidad alta'), 'Universidad filter did not show university task.');
    assert_tasks_page(!str_contains($spacePage['body'], 'Tarea completada sin espacio'), 'Universidad filter showed inbox task.');

    $priorityPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&status=all&priority=high',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($priorityPage['body'], 'Filtro hoy universidad alta'), 'High priority filter did not show high task.');
    assert_tasks_page(!str_contains($priorityPage['body'], 'Filtro sin vencimiento'), 'High priority filter showed low task.');

    $todayPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&time=today',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($todayPage['body'], 'Filtro hoy universidad alta'), 'Today filter did not show due-today task.');
    assert_tasks_page(!str_contains($todayPage['body'], 'Filtro sin vencimiento'), 'Today filter showed task without due_at.');

    $weekPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&time=week',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($weekPage['body'], 'Filtro hoy universidad alta'), 'Week filter did not show due-this-week task.');

    $overduePage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&time=overdue',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($overduePage['body'], 'Filtro vencida bandeja'), 'Overdue filter did not show overdue task.');
    assert_tasks_page(!str_contains($overduePage['body'], 'Filtro hoy universidad alta'), 'Overdue filter showed non-overdue task.');

    $combinedPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&space=universidad&status=pending&priority=high&time=week',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($combinedPage['body'], 'Filtro hoy universidad alta'), 'Combined filters did not show matching task.');
    assert_tasks_page(!str_contains($combinedPage['body'], 'Filtro vencida bandeja'), 'Combined filters showed non-matching task.');

    $screenToken = tasks_page_screen_csrf($tasksPage['body']);
    $csrfHeader = ['X-CSRF-Token: ' . $screenToken];
    $badCsrf = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        ['title' => 'CSRF invalido'],
        $cookieFile,
        ['X-CSRF-Token: invalid-token']
    );
    assert_tasks_page($badCsrf['status'] === 403, 'Invalid CSRF was not rejected.');

    $createTitle = 'Creada desde interfaz HTTP';
    $beforeCreateCount = count_user_tasks($pdo, $userId, $createTitle);
    $create = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        [
            'title' => $createTitle,
            'description' => 'Descripcion inicial',
            'space_id' => (string) $spaceId,
            'priority' => 'low',
            'starts_at' => '2026-08-18T12:30',
            'ends_at' => '2026-08-18T13:00',
            'due_at' => '2026-08-18T13:30',
        ],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($create['status'] === 201 && $create['json']['ok'] === true, 'Task could not be created from HTTP flow.');
    $createdTaskId = (int) $create['json']['data']['id'];
    assert_tasks_page($create['json']['data']['starts_at'] === '2026-08-18 16:30:00' && $create['json']['data']['ends_at'] === '2026-08-18 17:00:00', 'Task time range was not stored as UTC from HTTP flow.');
    assert_tasks_page($create['json']['data']['starts_at_local'] === '2026-08-18 12:30:00' && $create['json']['data']['starts_at_input'] === '2026-08-18T12:30', 'Task time range did not return local API fields.');
    $afterCreateCount = count_user_tasks($pdo, $userId, $createTitle);
    assert_tasks_page($afterCreateCount === $beforeCreateCount + 1, 'A single create request did not create exactly one task.');

    $createdPage = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&status=all',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(str_contains($createdPage['body'], 'Creada desde interfaz HTTP'), 'Created task did not appear on page.');

    $update = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $createdTaskId,
        'PATCH',
        [
            'title' => 'Editada desde interfaz HTTP',
            'description' => 'Descripcion editada',
            'space_id' => '',
            'priority' => 'high',
            'starts_at' => '',
            'ends_at' => '',
            'due_at' => '',
        ],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($update['status'] === 200 && $update['json']['data']['title'] === 'Editada desde interfaz HTTP', 'Task edit failed.');

    $invalidRange = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $createdTaskId,
        'PATCH',
        [
            'starts_at' => '2026-08-18T14:00',
            'ends_at' => '2026-08-18T13:00',
        ],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($invalidRange['status'] === 422, 'Invalid task time range was not rejected.');

    $complete = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $createdTaskId . '&action=complete',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($complete['status'] === 200 && $complete['json']['data']['status'] === 'completed', 'Task complete failed.');

    $reopen = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $createdTaskId . '&action=reopen',
        'POST',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($reopen['status'] === 200 && $reopen['json']['data']['status'] === 'pending', 'Task reopen failed.');

    $delete = tasks_page_request(
        'http://127.0.0.1/api/organization/tasks.php?id=' . $createdTaskId,
        'DELETE',
        [],
        $cookieFile,
        $csrfHeader
    );
    assert_tasks_page($delete['status'] === 200 && $delete['json']['data']['deleted'] === true, 'Task delete failed.');

    $afterDelete = tasks_page_request(
        'http://127.0.0.1/index.php?section=organization&status=all',
        'GET',
        null,
        $cookieFile
    );
    assert_tasks_page(!str_contains($afterDelete['body'], 'Editada desde interfaz HTTP'), 'Deleted task still appeared on page.');
    assert_tasks_page((int) $completedTask['id'] > 0, 'Completed fixture was not created.');
    assert_tasks_page((int) $todayTask['id'] > 0 && (int) $overdueTask['id'] > 0, 'Temporal fixtures were not created.');

    echo "Organization tasks page: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization tasks page: FAILED\n");
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
