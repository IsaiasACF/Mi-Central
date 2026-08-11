<?php
declare(strict_types=1);

use App\Database\Connection;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$username = 'test_org_' . bin2hex(random_bytes(4));
$otherUsername = 'test_org_other_' . bin2hex(random_bytes(4));
$exitCode = 1;

function fail_organization_schema(string $message): void
{
    fwrite(STDERR, "Organization schema: FAILED\n");
    fwrite(STDERR, $message . "\n");
}

function table_exists(\PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function column_exists(\PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $statement->execute([
        'table' => $table,
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function insert_test_user(\PDO $pdo, string $username): int
{
    $hash = password_hash('test-secret-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);

    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash test password.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);

    return (int) $pdo->lastInsertId();
}

try {
    $expectedTables = [
        'organization_spaces',
        'organization_categories',
        'organization_projects',
        'organization_tasks',
        'organization_notes',
        'organization_reminders',
        'notifications',
        'organization_events',
    ];

    foreach ($expectedTables as $table) {
        if (!table_exists($pdo, $table)) {
            throw new RuntimeException("Missing table: {$table}");
        }
    }

    foreach (['starts_at', 'ends_at', 'due_at'] as $taskDateColumn) {
        if (!column_exists($pdo, 'organization_tasks', $taskDateColumn)) {
            throw new RuntimeException("Missing task date column: {$taskDateColumn}");
        }
    }

    foreach (['task_id', 'project_id', 'remind_at', 'status', 'recurrence_type', 'recurrence_interval', 'recurrence_until', 'next_remind_at'] as $reminderColumn) {
        if (!column_exists($pdo, 'organization_reminders', $reminderColumn)) {
            throw new RuntimeException("Missing reminder column: {$reminderColumn}");
        }
    }

    foreach (['user_id', 'reminder_id', 'type', 'title', 'message', 'scheduled_at', 'read_at'] as $notificationColumn) {
        if (!column_exists($pdo, 'notifications', $notificationColumn)) {
            throw new RuntimeException("Missing notification column: {$notificationColumn}");
        }
    }

    $userId = insert_test_user($pdo, $username);
    $otherUserId = insert_test_user($pdo, $otherUsername);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';

    $seed($pdo);
    $seed($pdo);

    $statement = $pdo->prepare('SELECT COUNT(*) FROM organization_spaces WHERE user_id = :user_id');
    $statement->execute(['user_id' => $userId]);

    if ((int) $statement->fetchColumn() !== 4) {
        throw new RuntimeException('Initial spaces were duplicated or not created.');
    }

    $statement = $pdo->prepare(
        "SELECT id FROM organization_spaces WHERE user_id = :user_id AND slug = 'universidad'"
    );
    $statement->execute(['user_id' => $userId]);
    $spaceId = (int) $statement->fetchColumn();

    if ($spaceId <= 0) {
        throw new RuntimeException('Expected user space was not found.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO organization_categories (user_id, space_id, name)
         VALUES (:user_id, :space_id, :name)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'name' => 'Categoria de prueba',
    ]);
    $categoryId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO organization_projects (user_id, space_id, title, description, status, starts_on, due_on)
         VALUES (:user_id, :space_id, :title, :description, :status, :starts_on, :due_on)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'title' => 'Proyecto de prueba',
        'description' => 'Descripcion de prueba',
        'status' => 'active',
        'starts_on' => '2026-08-08',
        'due_on' => '2026-08-20',
    ]);
    $projectId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO organization_tasks (user_id, title)
         VALUES (:user_id, :title)'
    );
    $statement->execute([
        'user_id' => $userId,
        'title' => 'Tarea de bandeja',
    ]);
    $inboxTaskId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare('SELECT space_id FROM organization_tasks WHERE id = :id');
    $statement->execute(['id' => $inboxTaskId]);

    if ($statement->fetchColumn() !== null) {
        throw new RuntimeException('Inbox task did not keep space_id as NULL.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO organization_tasks
            (user_id, space_id, category_id, project_id, title, status, priority, due_at, position)
         VALUES
            (:user_id, :space_id, :category_id, :project_id, :title, :status, :priority, :due_at, :position)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'category_id' => $categoryId,
        'project_id' => $projectId,
        'title' => 'Tarea de proyecto',
        'status' => 'pending',
        'priority' => 'high',
        'due_at' => '2026-08-10 15:00:00',
        'position' => 1,
    ]);
    $projectTaskId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO organization_tasks (user_id, parent_task_id, title, priority)
         VALUES (:user_id, :parent_task_id, :title, :priority)'
    );
    $statement->execute([
        'user_id' => $userId,
        'parent_task_id' => $projectTaskId,
        'title' => 'Subtarea de prueba',
        'priority' => 'normal',
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO organization_reminders
            (user_id, task_id, title, remind_at, status, recurrence_type, recurrence_interval, next_remind_at)
         VALUES
            (:user_id, :task_id, :title, :remind_at, :status, :recurrence_type, :recurrence_interval, :next_remind_at)'
    );
    $statement->execute([
        'user_id' => $userId,
        'task_id' => $projectTaskId,
        'title' => 'Recordatorio de prueba',
        'remind_at' => '2026-08-09 12:00:00',
        'status' => 'pending',
        'recurrence_type' => 'weekly',
        'recurrence_interval' => 2,
        'next_remind_at' => '2026-08-09 12:00:00',
    ]);
    $reminderId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO notifications (user_id, reminder_id, type, title, message, scheduled_at)
         VALUES (:user_id, :reminder_id, :type, :title, :message, :scheduled_at)'
    );
    $statement->execute([
        'user_id' => $userId,
        'reminder_id' => $reminderId,
        'type' => 'reminder_due',
        'title' => 'Notificacion de prueba',
        'message' => 'Mensaje de prueba',
        'scheduled_at' => '2026-08-09 12:00:00',
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO organization_notes (user_id, space_id, category_id, title, content)
         VALUES (:user_id, :space_id, :category_id, :title, :content)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'category_id' => $categoryId,
        'title' => 'Nota de prueba',
        'content' => 'Contenido de prueba',
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO organization_events
            (user_id, space_id, category_id, title, starts_at, ends_at, all_day, location)
         VALUES
            (:user_id, :space_id, :category_id, :title, :starts_at, :ends_at, :all_day, :location)'
    );
    $statement->execute([
        'user_id' => $userId,
        'space_id' => $spaceId,
        'category_id' => $categoryId,
        'title' => 'Evento de prueba',
        'starts_at' => '2026-08-11 09:00:00',
        'ends_at' => '2026-08-11 10:00:00',
        'all_day' => 0,
        'location' => 'Sala de prueba',
    ]);

    $failedForeignKey = false;

    try {
        $statement = $pdo->prepare(
            'INSERT INTO organization_projects (user_id, space_id, title, status)
             VALUES (:user_id, :space_id, :title, :status)'
        );
        $statement->execute([
            'user_id' => $otherUserId,
            'space_id' => 999999999,
            'title' => 'Proyecto invalido',
            'status' => 'active',
        ]);
    } catch (\PDOException) {
        $failedForeignKey = true;
    }

    if (!$failedForeignKey) {
        throw new RuntimeException('Foreign key validation did not reject an invalid space.');
    }

    echo "Organization schema: OK\n";
    $exitCode = 0;
} catch (\Throwable $exception) {
    fail_organization_schema($exception->getMessage());
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
