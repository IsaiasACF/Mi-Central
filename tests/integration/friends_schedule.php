<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$service = new FriendScheduleService(new FriendScheduleRepository($pdo));
$username = 'test_friends_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_other_' . bin2hex(random_bytes(4));
$exitCode = 1;

function friends_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friends_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (FriendValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function friends_insert_user(PDO $pdo, string $username): int
{
    $hash = password_hash('test-secret-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);

    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash password.');
    }

    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);

    return (int) $pdo->lastInsertId();
}

function friends_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function friends_expect_pdo_failure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/202608080007_create_friends_schedule_tables.php';
    $exceptionMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080008_add_user_schedule_exceptions.php';
    $migration($pdo);
    $exceptionMigration($pdo);
    $migration($pdo);
    $exceptionMigration($pdo);

    foreach (['friends', 'friend_schedule_entries', 'friend_schedule_exceptions', 'user_schedule_entries', 'user_schedule_exceptions'] as $table) {
        friends_assert(friends_table_exists($pdo, $table), 'Missing friends table: ' . $table);
    }

    $userId = friends_insert_user($pdo, $username);
    $otherUserId = friends_insert_user($pdo, $otherUsername);

    $friend = $service->createFriend($userId, [
        'name' => 'Amiga U',
        'university' => 'Universidad de prueba',
        'default_campus' => 'Campus Central',
        'notes' => 'Horario manual',
    ]);
    friends_assert((int) $friend['user_id'] === $userId, 'Friend was not assigned to the user.');
    friends_assert($service->getFriend($otherUserId, (int) $friend['id']) === null, 'Another user could read friend.');

    $otherFriend = $service->createFriend($otherUserId, [
        'name' => 'Amigo ajeno',
    ]);

    $entry = $service->createFriendScheduleEntry($userId, (int) $friend['id'], [
        'weekday' => '1',
        'starts_at' => '09:00',
        'ends_at' => '10:30',
        'course_name' => 'Calculo I',
        'course_code' => 'MAT101',
        'room' => 'A-101',
        'campus' => 'Campus Norte',
        'valid_from' => '2026-03-01',
        'valid_until' => '2026-07-31',
    ]);
    friends_assert((int) $entry['friend_id'] === (int) $friend['id'], 'Schedule entry was not linked to friend.');
    friends_assert($entry['starts_at'] === '09:00:00' && $entry['ends_at'] === '10:30:00', 'Schedule entry did not keep weekly TIME.');
    friends_assert($entry['campus'] === 'Campus Norte', 'Specific campus was not stored.');
    friends_assert($entry['valid_from'] === '2026-03-01' && $entry['valid_until'] === '2026-07-31', 'Valid period was not stored.');

    $entryWithoutCampus = $service->createFriendScheduleEntry($userId, (int) $friend['id'], [
        'weekday' => 7,
        'starts_at' => '11:00:00',
        'ends_at' => '12:00:00',
        'course_name' => 'Laboratorio',
    ]);
    $entries = $service->listFriendScheduleEntries($userId, (int) $friend['id']);
    $effectiveCampus = null;
    foreach ($entries as $listedEntry) {
        if ((int) $listedEntry['id'] === (int) $entryWithoutCampus['id']) {
            $effectiveCampus = $listedEntry['effective_campus'] ?? null;
        }
    }
    friends_assert($effectiveCampus === 'Campus Central', 'Default campus did not apply when entry campus was empty.');

    friends_reject(
        fn () => $service->createFriendScheduleEntry($userId, (int) $friend['id'], [
            'weekday' => 2,
            'starts_at' => '12:00',
            'ends_at' => '11:00',
            'course_name' => 'Rango malo',
        ]),
        'Service accepted ends_at before starts_at.'
    );
    friends_reject(
        fn () => $service->createFriendScheduleEntry($userId, (int) $friend['id'], [
            'weekday' => 0,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'course_name' => 'Dia malo',
        ]),
        'Service accepted weekday 0.'
    );
    friends_reject(
        fn () => $service->createFriendScheduleEntry($userId, (int) $friend['id'], [
            'weekday' => 8,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'course_name' => 'Dia malo',
        ]),
        'Service accepted weekday 8.'
    );
    friends_reject(
        fn () => $service->createFriendScheduleEntry($userId, (int) $otherFriend['id'], [
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'course_name' => 'Ajeno',
        ]),
        'Service accepted another user friend.'
    );

    $exception = $service->createFriendScheduleException($userId, (int) $friend['id'], [
        'schedule_entry_id' => (string) $entry['id'],
        'exception_date' => '2026-04-15',
        'type' => 'modified',
        'starts_at' => '10:00',
        'ends_at' => '11:00',
        'course_name' => 'Calculo I recuperacion',
        'room' => 'B-202',
        'campus' => 'Campus Sur',
        'notes' => 'Cambio puntual',
    ]);
    friends_assert((int) $exception['schedule_entry_id'] === (int) $entry['id'], 'Linked exception was not stored.');
    friends_assert($exception['type'] === 'modified', 'Exception type was not stored.');

    friends_reject(
        fn () => $service->createFriendScheduleException($userId, (int) $friend['id'], [
            'schedule_entry_id' => (string) $entryWithoutCampus['id'],
            'exception_date' => '2026-04-16',
            'type' => 'cancelled',
            'starts_at' => '12:00',
            'ends_at' => '11:00',
        ]),
        'Service accepted invalid exception time range.'
    );

    $ownEntry = $service->createUserScheduleEntry($userId, [
        'weekday' => 3,
        'starts_at' => '14:00',
        'ends_at' => '15:30',
        'course_name' => 'Mi ramo',
        'course_code' => 'USR100',
        'room' => 'C-303',
        'campus' => 'Campus Central',
        'valid_from' => '2026-03-01',
        'valid_until' => '2026-07-31',
    ]);
    friends_assert((int) $ownEntry['user_id'] === $userId, 'User schedule was not linked to owner.');
    friends_assert(count($service->listUserScheduleEntries($otherUserId)) === 0, 'Another user saw owner schedule.');

    friends_expect_pdo_failure(
        fn () => $pdo->exec(
            "INSERT INTO friend_schedule_entries
                (friend_id, weekday, starts_at, ends_at, course_name)
             VALUES (999999999, 1, '09:00:00', '10:00:00', 'FK invalida')"
        ),
        'Database accepted schedule entry for missing friend.'
    );
    friends_expect_pdo_failure(
        fn () => $pdo->prepare(
            'INSERT INTO friend_schedule_exceptions
                (friend_id, schedule_entry_id, exception_date, type)
             VALUES (:friend_id, :schedule_entry_id, :exception_date, :type)'
        )->execute([
            'friend_id' => (int) $otherFriend['id'],
            'schedule_entry_id' => (int) $entry['id'],
            'exception_date' => '2026-04-20',
            'type' => 'cancelled',
        ]),
        'Database accepted exception linked to entry from another friend.'
    );
    friends_expect_pdo_failure(
        fn () => $pdo->exec(
            "INSERT INTO user_schedule_entries
                (user_id, weekday, starts_at, ends_at, course_name)
             VALUES (999999999, 1, '09:00:00', '10:00:00', 'FK invalida')"
        ),
        'Database accepted user schedule entry for missing user.'
    );

    echo "Friends schedule: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends schedule: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
