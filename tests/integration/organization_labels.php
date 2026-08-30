<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\LabelRepository;
use Modules\Organization\LabelService;
use Modules\Organization\NoteRepository;
use Modules\Organization\NoteService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Organization\TaskValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$labelService = new LabelService(new LabelRepository($pdo));
$taskService = new TaskService(new TaskRepository($pdo), 'America/Santiago', $labelService);
$projectService = new ProjectService(new ProjectRepository($pdo), 'America/Santiago', $labelService);
$noteService = new NoteService(new NoteRepository($pdo), $labelService);
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_labels_' . bin2hex(random_bytes(4));
$otherUsername = 'test_labels_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-labels-');
$exitCode = 1;

function labels_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function labels_form_request(string $url, array $postFields, string $cookieFile): array
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

function labels_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function labels_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function labels_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function labels_expect_validation(callable $callback, string $message): void
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

    $spaceId = (int) $spaceRepository->listForUser($userId)[0]['id'];
    $otherSpaceId = (int) $spaceRepository->listForUser($otherUserId)[0]['id'];

    $inf = $labelService->create($userId, ['name' => 'INF295', 'color' => '#3366cc']);
    labels_assert($inf['color'] === '#3366CC', 'Label color was not normalized.');

    $edited = $labelService->update($userId, (int) $inf['id'], ['name' => 'Inteligencia Artificial', 'color' => '#AA00BB']);
    labels_assert($edited !== null && $edited['name'] === 'Inteligencia Artificial' && $edited['color'] === '#AA00BB', 'Label edit failed.');

    labels_expect_validation(
        static fn () => $labelService->create($userId, ['name' => 'Color malo', 'color' => 'blue']),
        'Invalid HEX color was accepted.'
    );
    labels_expect_validation(
        static fn () => $labelService->create($userId, ['name' => ' inteligencia   artificial ', 'color' => '#112233']),
        'Duplicate normalized label name was accepted.'
    );

    $university = $labelService->create($userId, ['name' => 'Universidad', 'color' => '#22C55E']);
    $otherLabel = $labelService->create($otherUserId, ['name' => 'Ajena', 'color' => '#F97316']);
    labels_assert($labelService->get($userId, (int) $otherLabel['id']) === null, 'User could read another user label.');

    $task = $taskService->create($userId, [
        'title' => 'Tarea con varias etiquetas',
        'space_id' => $spaceId,
        'due_at' => '2026-08-13T12:00',
        'label_ids' => [(int) $edited['id'], (int) $university['id']],
    ]);
    labels_assert(count($task['labels']) === 2, 'Task did not keep multiple labels.');

    $project = $projectService->create($userId, [
        'title' => 'Proyecto con etiqueta',
        'space_id' => $spaceId,
        'due_on' => '2026-08-20',
        'label_ids' => [(int) $edited['id']],
    ]);
    labels_assert(count($project['labels']) === 1, 'Project did not keep label.');

    $note = $noteService->create($userId, [
        'title' => 'Nota con etiqueta',
        'content' => 'Contenido',
        'space_id' => $spaceId,
        'label_ids' => [(int) $university['id']],
    ]);
    labels_assert(count($note['labels']) === 1, 'Note did not keep label.');

    $updatedTask = $taskService->update($userId, (int) $task['id'], ['label_ids' => [(int) $edited['id']]]);
    labels_assert(is_array($updatedTask) && count($updatedTask['labels']) === 1, 'Removing a label from task failed.');

    labels_assert(count($taskService->list($userId, ['label_id' => (string) $edited['id']])) >= 1, 'Task label filter failed.');
    labels_assert(count($projectService->list($userId, ['label_id' => (string) $edited['id']])) === 1, 'Project label filter failed.');
    labels_assert(count($noteService->list($userId, ['label_id' => (string) $university['id']])) === 1, 'Note label filter failed.');

    labels_expect_validation(
        static fn () => $taskService->create($userId, [
            'title' => 'Etiqueta ajena',
            'space_id' => $spaceId,
            'label_ids' => [(int) $otherLabel['id']],
        ]),
        'Task accepted another user label.'
    );
    labels_expect_validation(
        static fn () => $projectService->create($userId, [
            'title' => 'Proyecto etiqueta ajena',
            'space_id' => $spaceId,
            'label_ids' => [(int) $otherLabel['id']],
        ]),
        'Project accepted another user label.'
    );
    labels_expect_validation(
        static fn () => $noteService->create($userId, [
            'title' => 'Nota etiqueta ajena',
            'content' => '',
            'space_id' => $spaceId,
            'label_ids' => [(int) $otherLabel['id']],
        ]),
        'Note accepted another user label.'
    );

    labels_assert($labelService->delete($userId, (int) $edited['id']), 'Label delete failed.');
    labels_assert($taskService->get($userId, (int) $task['id']) !== null, 'Deleting label deleted task.');
    labels_assert($projectService->get($userId, (int) $project['id']) !== null, 'Deleting label deleted project.');
    labels_assert($noteService->get($userId, (int) $note['id']) !== null, 'Deleting label deleted note.');
    labels_assert(count($taskService->get($userId, (int) $task['id'])['labels'] ?? []) === 0, 'Deleting label did not remove task relation.');

    $loginPage = labels_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = labels_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => labels_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    labels_assert($login['status'] === 302, 'Test user could not log in.');

    $labelsPage = labels_request('http://127.0.0.1/index.php?section=organization&tab=labels&status=all', 'GET', null, $cookieFile);
    labels_assert($labelsPage['status'] === 200, 'Labels tab did not load.');
    labels_assert(str_contains($labelsPage['body'], 'data-label-form') && str_contains($labelsPage['body'], 'Universidad'), 'Labels UI was not rendered.');
    labels_assert(!str_contains($labelsPage['body'], 'Ajena'), 'Another user label was visible.');
    $csrfHeader = ['X-CSRF-Token: ' . labels_screen_csrf($labelsPage['body'])];

    $missingCsrf = labels_request('http://127.0.0.1/api/organization/labels.php', 'POST', ['name' => 'Sin CSRF', 'color' => '#123456'], $cookieFile);
    labels_assert($missingCsrf['status'] === 403, 'Label API write did not require CSRF.');

    $apiCreate = labels_request('http://127.0.0.1/api/organization/labels.php', 'POST', ['name' => 'Personal', 'color' => '#ABCDEF'], $cookieFile, $csrfHeader);
    labels_assert($apiCreate['status'] === 201 && ($apiCreate['json']['data']['color'] ?? '') === '#ABCDEF', 'Label API did not create label.');
    $apiLabelId = (int) $apiCreate['json']['data']['id'];

    $apiDuplicate = labels_request('http://127.0.0.1/api/organization/labels.php', 'POST', ['name' => ' personal ', 'color' => '#111111'], $cookieFile, $csrfHeader);
    labels_assert($apiDuplicate['status'] === 422, 'Label API accepted duplicate label.');

    $apiInvalid = labels_request('http://127.0.0.1/api/organization/labels.php', 'POST', ['name' => 'Invalida', 'color' => '#XYZXYZ'], $cookieFile, $csrfHeader);
    labels_assert($apiInvalid['status'] === 422, 'Label API accepted invalid HEX.');

    $apiUpdate = labels_request('http://127.0.0.1/api/organization/labels.php?id=' . $apiLabelId, 'PATCH', ['name' => 'Personal editada', 'color' => '#123456'], $cookieFile, $csrfHeader);
    labels_assert($apiUpdate['status'] === 200 && ($apiUpdate['json']['data']['name'] ?? '') === 'Personal editada', 'Label API did not update label.');

    $apiDelete = labels_request('http://127.0.0.1/api/organization/labels.php?id=' . $apiLabelId, 'DELETE', [], $cookieFile, $csrfHeader);
    labels_assert($apiDelete['status'] === 200 && ($apiDelete['json']['data']['deleted'] ?? false) === true, 'Label API did not delete label.');

    labels_expect_validation(
        static fn () => $projectService->create($userId, ['title' => 'Espacio ajeno', 'space_id' => $otherSpaceId]),
        'Other user space validation broke while testing labels.'
    );

    echo "Organization labels: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization labels: FAILED\n");
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
