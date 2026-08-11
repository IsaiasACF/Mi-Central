<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Organization\TaskValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$username = 'test_task_' . bin2hex(random_bytes(4));
$otherUsername = 'test_task_other_' . bin2hex(random_bytes(4));
$exitCode = 1;

function assert_task_service(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insert_task_test_user(PDO $pdo, string $username): int
{
    $hash = password_hash('test-secret-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);

    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash test password.');
    }

    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);

    return (int) $pdo->lastInsertId();
}

function task_space_id(PDO $pdo, int $userId, string $slug = 'universidad'): int
{
    $statement = $pdo->prepare(
        'SELECT id FROM organization_spaces WHERE user_id = :user_id AND slug = :slug LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'slug' => $slug,
    ]);

    return (int) $statement->fetchColumn();
}

function insert_task_project(PDO $pdo, int $userId, int $spaceId, string $title): int
{
    $statement = $pdo->prepare(
        'INSERT INTO organization_projects (user_id, space_id, title, status)
         VALUES (:user_id, :space_id, :title, :status)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'title' => $title,
        'status' => 'active',
    ]);

    return (int) $pdo->lastInsertId();
}

function expect_task_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (TaskValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $userId = insert_task_test_user($pdo, $username);
    $otherUserId = insert_task_test_user($pdo, $otherUsername);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaceId = task_space_id($pdo, $userId);
    $otherOwnSpaceId = task_space_id($pdo, $userId, 'amigos');
    $otherSpaceId = task_space_id($pdo, $otherUserId);
    assert_task_service($spaceId > 0 && $otherOwnSpaceId > 0 && $otherSpaceId > 0, 'Seeded spaces were not found.');

    $projectId = insert_task_project($pdo, $userId, $spaceId, 'Proyecto servicio');
    $otherProjectId = insert_task_project($pdo, $otherUserId, $otherSpaceId, 'Proyecto otro usuario');
    $service = new TaskService(new TaskRepository($pdo));

    $inboxTask = $service->create($userId, [
        'title' => 'Tarea de bandeja',
        'priority' => 'normal',
        'starts_at' => '2026-08-10 14:00:00',
        'ends_at' => '2026-08-10 14:30:00',
        'due_at' => '2026-08-10 15:00:00',
    ]);
    assert_task_service($inboxTask['space_id'] === null, 'Inbox task did not keep space_id NULL.');
    assert_task_service($inboxTask['status'] === 'pending', 'Created task did not default to pending.');
    assert_task_service($inboxTask['starts_at'] === '2026-08-10 18:00:00' && $inboxTask['ends_at'] === '2026-08-10 18:30:00', 'Task time range was not stored in UTC.');
    assert_task_service($inboxTask['starts_at_local'] === '2026-08-10 14:00:00' && $inboxTask['starts_at_input'] === '2026-08-10T14:00', 'Task local derived dates were not exposed.');

    $spaceTask = $service->create($userId, [
        'title' => 'Tarea con espacio',
        'space_id' => $spaceId,
        'priority' => 'high',
    ]);
    assert_task_service((int) $spaceTask['space_id'] === $spaceId, 'Task was not associated with the user space.');

    $projectTask = $service->create($userId, [
        'title' => 'Tarea de proyecto',
        'space_id' => $spaceId,
        'project_id' => $projectId,
        'priority' => 'low',
    ]);
    assert_task_service((int) $projectTask['project_id'] === $projectId, 'Task was not associated with the project.');

    $fetched = $service->get($userId, (int) $projectTask['id']);
    assert_task_service(is_array($fetched) && $fetched['title'] === 'Tarea de proyecto', 'Task could not be fetched.');

    $filteredBySpace = $service->list($userId, ['space_id' => $spaceId]);
    assert_task_service(count($filteredBySpace) >= 2, 'Listing by space did not return expected tasks.');

    $filteredByProject = $service->list($userId, ['project_id' => $projectId, 'priority' => 'low']);
    assert_task_service(count($filteredByProject) === 1, 'Listing by project and priority returned unexpected tasks.');

    $independentTasks = $service->list($userId, ['project_id' => 'none', 'parent_task_id' => 'none']);
    $independentTitles = array_column($independentTasks, 'title');
    assert_task_service(in_array('Tarea de bandeja', $independentTitles, true), 'Independent list missed inbox task.');
    assert_task_service(!in_array('Tarea de proyecto', $independentTitles, true), 'Independent list included project task.');

    $filteredByDue = $service->list($userId, [
        'status' => 'pending',
        'due_from' => '2026-08-10 00:00:00',
        'due_to' => '2026-08-11 00:00:00',
    ]);
    assert_task_service(count($filteredByDue) === 1, 'Listing by due range returned unexpected tasks.');

    $updated = $service->update($userId, (int) $projectTask['id'], [
        'title' => 'Tarea actualizada',
        'priority' => 'high',
        'starts_at' => '2026-08-12 08:30:00',
        'ends_at' => '2026-08-12 09:00:00',
        'due_at' => '2026-08-12 09:30:00',
    ]);
    assert_task_service(
        is_array($updated) && $updated['title'] === 'Tarea actualizada' && $updated['priority'] === 'high' && $updated['ends_at'] === '2026-08-12 13:00:00' && $updated['ends_at_local'] === '2026-08-12 09:00:00',
        'Task update did not persist editable fields.'
    );

    $completed = $service->complete($userId, (int) $projectTask['id']);
    assert_task_service(
        is_array($completed) && $completed['status'] === 'completed' && $completed['completed_at'] !== null,
        'Completing a task did not set completed_at.'
    );

    $reopened = $service->reopen($userId, (int) $projectTask['id']);
    assert_task_service(
        is_array($reopened) && $reopened['status'] === 'pending' && $reopened['completed_at'] === null,
        'Reopening a task did not clear completed_at.'
    );

    $parent = $service->create($userId, ['title' => 'Tarea padre']);
    $child = $service->create($userId, [
        'title' => 'Subtarea valida',
        'parent_task_id' => (int) $parent['id'],
    ]);
    assert_task_service((int) $child['parent_task_id'] === (int) $parent['id'], 'Valid parent_task_id was not stored.');

    expect_task_validation(
        static fn () => $service->update($userId, (int) $parent['id'], ['parent_task_id' => (int) $parent['id']]),
        'Self parent relationship was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->update($userId, (int) $parent['id'], ['parent_task_id' => (int) $child['id']]),
        'Parent cycle was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->create($userId, ['title' => 'Prioridad invalida', 'priority' => 'urgent']),
        'Invalid priority was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->create($userId, [
            'title' => 'Rango invalido',
            'starts_at' => '2026-08-12 10:00:00',
            'ends_at' => '2026-08-12 09:00:00',
        ]),
        'Invalid date range was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->create($userId, ['title' => 'Relacion ajena', 'space_id' => $otherSpaceId]),
        'Relation owned by another user was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->create($userId, [
            'title' => 'Espacio distinto al proyecto',
            'project_id' => $projectId,
            'space_id' => $otherOwnSpaceId,
        ]),
        'Different project space was not rejected.'
    );
    expect_task_validation(
        static fn () => $service->create($userId, ['title' => 'Proyecto ajeno', 'project_id' => $otherProjectId]),
        'Project owned by another user was not rejected.'
    );

    $otherTask = $service->create($otherUserId, ['title' => 'Tarea del otro usuario']);
    assert_task_service($service->get($userId, (int) $otherTask['id']) === null, 'User A could read user B task.');
    assert_task_service($service->update($userId, (int) $otherTask['id'], ['title' => 'No permitido']) === null, 'User A could update user B task.');
    assert_task_service(!$service->delete($userId, (int) $otherTask['id']), 'User A could delete user B task.');

    assert_task_service($service->delete($userId, (int) $projectTask['id']), 'Task was not deleted.');
    assert_task_service($service->get($userId, (int) $projectTask['id']) === null, 'Deleted task was still readable.');

    echo "Organization task service: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization task service: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
