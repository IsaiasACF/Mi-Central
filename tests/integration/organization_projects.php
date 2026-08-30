<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Organization\TaskValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$taskService = new TaskService(new TaskRepository($pdo));
$projectService = new ProjectService(new ProjectRepository($pdo));
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_projects_' . bin2hex(random_bytes(4));
$otherUsername = 'test_projects_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-projects-');
$exitCode = 1;

function projects_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function projects_form_request(string $url, array $postFields, string $cookieFile): array
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

function projects_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function projects_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function projects_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function projects_expect_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (TaskValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $userSpaces = $spaceRepository->listForUser($userId);
    $spaceId = (int) $userSpaces[0]['id'];
    $secondSpaceId = (int) $userSpaces[1]['id'];
    $otherSpaceId = (int) $spaceRepository->listForUser($otherUserId)[0]['id'];

    $loginPage = projects_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = projects_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => projects_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    projects_assert($login['status'] === 302, 'Test user could not log in.');

    $organizationPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=projects', 'GET', null, $cookieFile);
    projects_assert($organizationPage['status'] === 200, 'Organization projects page did not load.');
    projects_assert(str_contains($organizationPage['body'], 'Calendario'), 'Organization internal tabs were not rendered.');
    projects_assert(str_contains($organizationPage['body'], 'organization-tab is-active'), 'Projects tab was not active.');
    projects_assert(str_contains($organizationPage['body'], 'data-project-form'), 'Project form was not rendered.');
    $csrfHeader = ['X-CSRF-Token: ' . projects_screen_csrf($organizationPage['body'])];

    $createProject = projects_request(
        'http://127.0.0.1/api/organization/projects.php',
        'POST',
        [
            'title' => 'Proyecto Redes',
            'description' => 'Proyecto de prueba',
            'space_id' => (string) $spaceId,
            'starts_on' => '2026-08-10',
            'due_on' => '2026-08-30',
        ],
        $cookieFile,
        $csrfHeader
    );
    projects_assert($createProject['status'] === 201 && $createProject['json']['ok'] === true, 'Project API did not create project.');
    $projectId = (int) $createProject['json']['data']['id'];
    projects_assert($createProject['json']['data']['tasks_total'] === 0, 'New project progress was not 0 tasks.');

    $createTask = projects_request(
        'http://127.0.0.1/api/organization/tasks.php',
        'POST',
        [
            'title' => 'Tarea del proyecto',
            'project_id' => (string) $projectId,
        ],
        $cookieFile,
        $csrfHeader
    );
    projects_assert($createTask['status'] === 201, 'Task inside project was not created.');
    $projectTaskId = (int) $createTask['json']['data']['id'];
    projects_assert((int) $createTask['json']['data']['project_id'] === $projectId, 'Task did not keep project_id.');
    projects_assert((int) $createTask['json']['data']['space_id'] === $spaceId, 'Task did not inherit coherent project space.');

    projects_expect_validation(
        static fn () => $taskService->create($userId, [
            'title' => 'Tarea con espacio incoherente',
            'project_id' => $projectId,
            'space_id' => $secondSpaceId,
        ]),
        'Task accepted a space different from its project.'
    );

    $secondTask = $taskService->create($userId, [
        'title' => 'Segunda tarea del proyecto',
        'project_id' => $projectId,
    ]);

    $progress = $projectService->get($userId, $projectId);
    projects_assert($progress !== null && (int) $progress['tasks_total'] === 2 && (int) $progress['progress_percent'] === 0, 'Project progress 0% was wrong.');

    $taskService->complete($userId, $projectTaskId);
    $partialProgress = $projectService->get($userId, $projectId);
    projects_assert((int) $partialProgress['tasks_completed'] === 1 && (int) $partialProgress['progress_percent'] === 50, 'Project partial progress was wrong.');

    $taskService->complete($userId, (int) $secondTask['id']);
    $fullProgress = $projectService->get($userId, $projectId);
    projects_assert((int) $fullProgress['tasks_completed'] === 2 && (int) $fullProgress['progress_percent'] === 100, 'Project 100% progress was wrong.');
    projects_assert($fullProgress['status'] === 'active', 'Project was completed automatically.');

    $parent = $taskService->create($userId, [
        'title' => 'Tarea padre subtareas',
        'project_id' => $projectId,
    ]);
    $subtask = $taskService->create($userId, [
        'title' => 'Subtarea de prueba',
        'project_id' => $projectId,
        'parent_task_id' => (int) $parent['id'],
    ]);
    projects_assert((int) $subtask['parent_task_id'] === (int) $parent['id'], 'Subtask was not created.');
    $completedSubtask = $taskService->complete($userId, (int) $subtask['id']);
    projects_assert($completedSubtask !== null && $completedSubtask['status'] === 'completed', 'Subtask complete failed.');
    $reopenedSubtask = $taskService->reopen($userId, (int) $subtask['id']);
    projects_assert($reopenedSubtask !== null && $reopenedSubtask['status'] === 'pending', 'Subtask reopen failed.');
    projects_expect_validation(
        static fn () => $taskService->update($userId, (int) $parent['id'], ['parent_task_id' => (int) $parent['id']]),
        'Self parent was not rejected.'
    );
    projects_expect_validation(
        static fn () => $taskService->update($userId, (int) $parent['id'], ['parent_task_id' => (int) $subtask['id']]),
        'Parent cycle was not rejected.'
    );

    $otherProject = $projectService->create($otherUserId, [
        'title' => 'Proyecto ajeno',
        'space_id' => $otherSpaceId,
    ]);
    projects_assert($projectService->get($userId, (int) $otherProject['id']) === null, 'User could read another project.');
    projects_expect_validation(
        static fn () => $projectService->create($userId, ['title' => 'Proyecto espacio ajeno', 'space_id' => $otherSpaceId]),
        'Other user space was not rejected for project.'
    );
    projects_expect_validation(
        static fn () => $taskService->create($userId, ['title' => 'Tarea proyecto ajeno', 'project_id' => (int) $otherProject['id']]),
        'Other user project was not rejected for task.'
    );

    $tasksTabPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=tasks&status=all', 'GET', null, $cookieFile);
    projects_assert(!str_contains($tasksTabPage['body'], 'Proyecto Redes'), 'Tasks tab showed project card.');
    projects_assert(!str_contains($tasksTabPage['body'], 'Tarea padre subtareas'), 'Tasks tab showed project task.');
    projects_assert(!str_contains($tasksTabPage['body'], 'Subtarea de prueba'), 'Tasks tab showed subtask as a main item.');

    $calendarPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=calendar', 'GET', null, $cookieFile);
    projects_assert($calendarPage['status'] === 200 && str_contains($calendarPage['body'], 'Calendario'), 'Calendar tab was not rendered.');

    $typeProjectsPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=projects&status=all', 'GET', null, $cookieFile);
    projects_assert(str_contains($typeProjectsPage['body'], 'Proyecto Redes') && !str_contains($typeProjectsPage['body'], 'Tarea padre subtareas'), 'Projects tab filter failed.');

    $inboxProjectsPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=projects&space=inbox&status=all', 'GET', null, $cookieFile);
    projects_assert(!str_contains($inboxProjectsPage['body'], 'Proyecto Redes'), 'Inbox space filter should not show projects.');

    $detailPage = projects_request('http://127.0.0.1/index.php?section=organization&tab=projects&project=' . $projectId, 'GET', null, $cookieFile);
    projects_assert(str_contains($detailPage['body'], 'Proyecto Redes') && str_contains($detailPage['body'], 'data-project-task-new'), 'Project detail was not rendered.');
    projects_assert(str_contains($detailPage['body'], 'Tarea padre subtareas') && str_contains($detailPage['body'], 'Subtarea de prueba'), 'Project detail did not show task hierarchy.');

    $updateProject = projects_request(
        'http://127.0.0.1/api/organization/projects.php?id=' . $projectId,
        'PATCH',
        ['title' => 'Proyecto Redes editado', 'description' => '', 'space_id' => (string) $secondSpaceId, 'status' => 'active'],
        $cookieFile,
        $csrfHeader
    );
    projects_assert($updateProject['status'] === 200 && $updateProject['json']['data']['title'] === 'Proyecto Redes editado', 'Project edit failed.');
    $syncedTask = $taskService->get($userId, $projectTaskId);
    projects_assert($syncedTask !== null && (int) $syncedTask['space_id'] === $secondSpaceId, 'Project space change did not sync task spaces.');

    $completeProject = projects_request('http://127.0.0.1/api/organization/projects.php?id=' . $projectId . '&action=complete', 'POST', [], $cookieFile, $csrfHeader);
    projects_assert($completeProject['status'] === 200 && $completeProject['json']['data']['status'] === 'completed', 'Project complete failed.');

    $archiveProject = projects_request('http://127.0.0.1/api/organization/projects.php?id=' . $projectId . '&action=archive', 'POST', [], $cookieFile, $csrfHeader);
    projects_assert($archiveProject['status'] === 200 && $archiveProject['json']['data']['status'] === 'archived', 'Project archive failed.');

    $deleteProject = projects_request('http://127.0.0.1/api/organization/projects.php?id=' . $projectId, 'DELETE', [], $cookieFile, $csrfHeader);
    projects_assert($deleteProject['status'] === 200, 'Project delete failed.');
    $orphanedTask = $taskService->get($userId, $projectTaskId);
    projects_assert($orphanedTask !== null && $orphanedTask['project_id'] === null, 'Deleting project did not preserve task with project_id NULL.');

    echo "Organization projects: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization projects: FAILED\n");
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
