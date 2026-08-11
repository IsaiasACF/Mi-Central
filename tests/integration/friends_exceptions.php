<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Friends\FriendPresenceService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleResolver;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$config = require dirname(__DIR__, 2) . '/config/app.php';
$pdo = Connection::get();
$repository = new FriendScheduleRepository($pdo);
$service = new FriendScheduleService($repository);
$resolver = new FriendScheduleResolver($service, 'America/Santiago');
$presence = new FriendPresenceService(new FriendRepository($pdo), $service, 'America/Santiago', $resolver);
$username = 'test_friends_exceptions_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_exceptions_other_' . bin2hex(random_bytes(4));
$exitCode = 1;

function friends_exceptions_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friends_exceptions_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (FriendValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function friends_exceptions_insert_user(PDO $pdo, string $username): int
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

try {
    $baseMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080007_create_friends_schedule_tables.php';
    $exceptionMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080008_add_user_schedule_exceptions.php';
    $baseMigration($pdo);
    $exceptionMigration($pdo);

    $userId = friends_exceptions_insert_user($pdo, $username);
    $otherUserId = friends_exceptions_insert_user($pdo, $otherUsername);
    $friend = $service->createFriend($userId, ['name' => 'Tomas', 'default_campus' => 'CSJ']);
    $otherFriend = $service->createFriend($otherUserId, ['name' => 'Ajeno']);
    $entry = $service->createEntry($userId, 'friend', (int) $friend['id'], [
        'weekday' => 3,
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'TEL351',
        'course_code' => 'TEL351',
        'room' => 'K306',
        'valid_from' => '2026-08-01',
        'valid_until' => '2026-12-20',
    ]);

    $cancelled = $service->createException($userId, 'friend', (int) $friend['id'], [
        'schedule_entry_id' => (string) $entry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'cancelled',
    ]);
    $effective = $resolver->effectiveForDate($userId, 'friend', (int) $friend['id'], '2026-08-19');
    friends_exceptions_assert(count($effective) === 1 && ($effective[0]['exception_type'] ?? '') === 'cancelled', 'Cancelled exception was not applied.');
    friends_exceptions_assert(empty($effective[0]['counts_as_class']), 'Cancelled exception should not count as class.');
    $normal = $resolver->effectiveForDate($userId, 'friend', (int) $friend['id'], '2026-08-26');
    friends_exceptions_assert(count($normal) === 1 && ($normal[0]['exception_type'] ?? null) === null, 'Different date did not keep normal schedule.');
    friends_exceptions_assert($service->deleteException($userId, 'friend', (int) $cancelled['id']), 'Could not delete cancelled exception.');
    $restored = $resolver->effectiveForDate($userId, 'friend', (int) $friend['id'], '2026-08-19');
    friends_exceptions_assert(count($restored) === 1 && ($restored[0]['exception_type'] ?? null) === null, 'Deleting exception did not restore normal schedule.');

    $absent = $service->createException($userId, 'friend', (int) $friend['id'], [
        'schedule_entry_id' => (string) $entry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'absent',
    ]);
    $absentNow = $presence->now($userId, new DateTimeImmutable('2026-08-19 16:30:00', new DateTimeZone('America/Santiago')));
    friends_exceptions_assert(($absentNow[0]['status'] ?? '') === 'No asiste', 'Now did not respect absent exception.');
    friends_exceptions_assert($service->deleteException($userId, 'friend', (int) $absent['id']), 'Could not delete absent exception.');

    $modified = $service->createException($userId, 'friend', (int) $friend['id'], [
        'schedule_entry_id' => (string) $entry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'modified',
        'starts_at' => '18:00',
        'ends_at' => '19:10',
        'course_name' => 'TEL351 recuperacion',
        'course_code' => 'TEL351-R',
        'room' => 'B201',
        'campus' => 'CSJ',
    ]);
    $modifiedDay = $resolver->effectiveForDate($userId, 'friend', (int) $friend['id'], '2026-08-19');
    friends_exceptions_assert(count($modifiedDay) === 1, 'Modified exception should not duplicate original block.');
    friends_exceptions_assert(($modifiedDay[0]['starts_at'] ?? '') === '18:00:00' && ($modifiedDay[0]['room'] ?? '') === 'B201', 'Modified exception did not override time or room.');
    $modifiedNow = $presence->now($userId, new DateTimeImmutable('2026-08-19 18:30:00', new DateTimeZone('America/Santiago')));
    friends_exceptions_assert(($modifiedNow[0]['status'] ?? '') === 'En clase' && ($modifiedNow[0]['room'] ?? '') === 'B201', 'Now did not use modified exception.');
    $today = $presence->today($userId, new DateTimeImmutable('2026-08-19 18:30:00', new DateTimeZone('America/Santiago')));
    friends_exceptions_assert(($today[0]['blocks'][0]['state'] ?? '') === 'Ahora' && ($today[0]['blocks'][0]['exception_type'] ?? '') === 'modified', 'Today did not use effective modified schedule.');

    $ownEntry = $service->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '10:00',
        'ends_at' => '11:10',
        'course_name' => 'Mi clase',
    ]);
    $service->createException($userId, 'user', null, [
        'schedule_entry_id' => (string) $ownEntry['id'],
        'exception_date' => '2026-08-19',
        'type' => 'cancelled',
    ]);
    $ownEffective = $resolver->effectiveForDate($userId, 'user', null, '2026-08-19');
    friends_exceptions_assert(($ownEffective[0]['exception_type'] ?? '') === 'cancelled', 'Own schedule exception was not applied.');

    $dashboard = (new DashboardSummaryService($config, null, $userId, null, null, $presence))->summary();
    $dashboardFriends = json_encode($dashboard['sections']['friends']['items'] ?? [], JSON_THROW_ON_ERROR);
    friends_exceptions_assert(str_contains($dashboardFriends, 'Tomas'), 'Dashboard did not receive effective friends summary.');

    friends_exceptions_reject(
        fn () => $service->createException($userId, 'friend', (int) $otherFriend['id'], [
            'schedule_entry_id' => (string) $entry['id'],
            'exception_date' => '2026-08-19',
            'type' => 'cancelled',
        ]),
        'Service accepted exception for another user friend.'
    );
    friends_exceptions_reject(
        fn () => $service->createException($otherUserId, 'user', null, [
            'schedule_entry_id' => (string) $ownEntry['id'],
            'exception_date' => '2026-08-19',
            'type' => 'cancelled',
        ]),
        'Service accepted exception for another user schedule entry.'
    );

    echo "Friends exceptions: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends exceptions: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
