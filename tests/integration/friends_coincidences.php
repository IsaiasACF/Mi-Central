<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Friends\CoincidenceService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleResolver;
use Modules\Friends\FriendScheduleService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
$coincidences = new CoincidenceService(
    new FriendRepository($pdo),
    new FriendScheduleResolver($scheduleService, 'America/Santiago')
);
$username = 'test_friends_coincidences_' . bin2hex(random_bytes(4));
$otherUsername = 'test_friends_coincidences_other_' . bin2hex(random_bytes(4));
$exitCode = 1;

function friends_coincidences_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friends_coincidences_insert_user(PDO $pdo, string $username): int
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

/**
 * @param array<int, array<string, mixed>> $items
 */
function friends_coincidences_find(array $items, string $friendName, ?string $startsAt = null): ?array
{
    foreach ($items as $item) {
        if (($item['friend_name'] ?? '') === $friendName && ($startsAt === null || ($item['starts_at'] ?? '') === $startsAt)) {
            return $item;
        }
    }

    return null;
}

try {
    $baseMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080007_create_friends_schedule_tables.php';
    $exceptionMigration = require dirname(__DIR__, 2) . '/database/migrations/202608080008_add_user_schedule_exceptions.php';
    $baseMigration($pdo);
    $exceptionMigration($pdo);

    $rawFree = $coincidences->calculateFreeIntervals([
        ['starts_at' => '10:00:00', 'ends_at' => '11:30:00', 'effective_campus' => 'CSJ', 'counts_as_class' => true],
        ['starts_at' => '14:00:00', 'ends_at' => '15:30:00', 'effective_campus' => 'CSJ', 'counts_as_class' => true],
    ]);
    friends_coincidences_assert(count($rawFree) === 1 && $rawFree[0]['starts_at'] === '11:30' && $rawFree[0]['ends_at'] === '14:00', 'Free interval helper should remain available.');

    $userId = friends_coincidences_insert_user($pdo, $username);
    $otherUserId = friends_coincidences_insert_user($pdo, $otherUsername);
    $date = '2026-08-12';

    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'room' => 'K200',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'user', null, [
        'weekday' => 3,
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Telecomunicaciones',
        'course_code' => 'TEL351',
        'room' => 'A010',
        'campus' => 'CSJ',
    ]);

    $exact = $scheduleService->createFriend($userId, ['name' => 'Exacto', 'default_campus' => 'CSJ']);
    $sameTime = $scheduleService->createFriend($userId, ['name' => 'Yoyo', 'default_campus' => 'CSJ']);
    $partial = $scheduleService->createFriend($userId, ['name' => 'Parcial', 'default_campus' => 'CSJ']);
    $differentCampus = $scheduleService->createFriend($userId, ['name' => 'Campus distinto', 'default_campus' => 'Casa Central']);
    $unknownCampus = $scheduleService->createFriend($userId, ['name' => 'Sin campus']);
    $cancelled = $scheduleService->createFriend($userId, ['name' => 'Cancelado', 'default_campus' => 'CSJ']);
    $absent = $scheduleService->createFriend($userId, ['name' => 'Ausente', 'default_campus' => 'CSJ']);
    $modified = $scheduleService->createFriend($userId, ['name' => 'Modificado', 'default_campus' => 'CSJ']);
    $inactive = $scheduleService->createFriend($userId, ['name' => 'Inactivo', 'is_active' => false]);
    $otherFriend = $scheduleService->createFriend($otherUserId, ['name' => 'Ajeno']);

    $scheduleService->createEntry($userId, 'friend', (int) $exact['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'inf309',
        'room' => 'B038',
        'campus' => 'csj',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $sameTime['id'], [
        'weekday' => 3,
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Arquitectura',
        'course_code' => 'INF325',
        'room' => 'A014',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $partial['id'], [
        'weekday' => 3,
        'starts_at' => '15:30',
        'ends_at' => '16:00',
        'course_name' => 'Fisica',
        'course_code' => 'FIS140',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $differentCampus['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'campus' => 'Casa Central',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $unknownCampus['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Base de Datos',
        'course_code' => 'INF309',
        'campus' => '',
    ]);
    $cancelEntry = $scheduleService->createEntry($userId, 'friend', (int) $cancelled['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Cancelada',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $cancelled['id'], [
        'schedule_entry_id' => (string) $cancelEntry['id'],
        'exception_date' => $date,
        'type' => 'cancelled',
    ]);
    $absentEntry = $scheduleService->createEntry($userId, 'friend', (int) $absent['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Ausente',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $absent['id'], [
        'schedule_entry_id' => (string) $absentEntry['id'],
        'exception_date' => $date,
        'type' => 'absent',
    ]);
    $modifiedEntry = $scheduleService->createEntry($userId, 'friend', (int) $modified['id'], [
        'weekday' => 3,
        'starts_at' => '12:00',
        'ends_at' => '13:00',
        'course_name' => 'Original',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createException($userId, 'friend', (int) $modified['id'], [
        'schedule_entry_id' => (string) $modifiedEntry['id'],
        'exception_date' => $date,
        'type' => 'modified',
        'starts_at' => '16:05',
        'ends_at' => '17:15',
        'course_name' => 'Telecomunicaciones',
        'course_code' => 'tel351',
        'room' => 'A011',
        'campus' => 'CSJ',
    ]);
    $scheduleService->createEntry($userId, 'friend', (int) $inactive['id'], [
        'weekday' => 3,
        'starts_at' => '14:40',
        'ends_at' => '15:50',
        'course_name' => 'Inactiva',
        'campus' => 'CSJ',
    ]);

    $all = $coincidences->getCoincidencesForDate($userId, $date);
    $exactItem = friends_coincidences_find($all, 'Exacto');
    friends_coincidences_assert($exactItem !== null && $exactItem['starts_at'] === '14:40' && $exactItem['ends_at'] === '15:50', 'Exact block intersection failed.');
    friends_coincidences_assert($exactItem['same_course'] === true && $exactItem['overlap_type'] === 'exact_course', 'Same course normalized detection failed.');
    friends_coincidences_assert($exactItem['same_campus'] === true && ($exactItem['user_block']['room'] ?? '') === 'K200' && ($exactItem['friend_block']['room'] ?? '') === 'B038', 'Coincidence block details failed.');

    $sameTimeItem = friends_coincidences_find($all, 'Yoyo');
    friends_coincidences_assert($sameTimeItem !== null && $sameTimeItem['starts_at'] === '16:05' && $sameTimeItem['ends_at'] === '17:15', 'Same-time different-course coincidence failed.');
    friends_coincidences_assert($sameTimeItem['same_course'] === false && $sameTimeItem['overlap_type'] === 'same_time', 'Same-time overlap classification failed.');

    $partialItem = friends_coincidences_find($all, 'Parcial');
    friends_coincidences_assert($partialItem !== null && $partialItem['starts_at'] === '15:30' && $partialItem['ends_at'] === '15:50' && $partialItem['duration_minutes'] === 20, 'Partial overlap failed.');
    friends_coincidences_assert($partialItem['overlap_type'] === 'partial', 'Partial overlap classification failed.');

    foreach ($all as $item) {
        friends_coincidences_assert(!(($item['starts_at'] ?? '') === '15:50' && ($item['ends_at'] ?? '') === '16:05'), 'Break between classes should not be a coincidence.');
    }

    friends_coincidences_assert(friends_coincidences_find($all, 'Cancelado') === null, 'Cancelled block should not create coincidence.');
    friends_coincidences_assert(friends_coincidences_find($all, 'Ausente') === null, 'Absent block should not create coincidence.');
    $modifiedItem = friends_coincidences_find($all, 'Modificado');
    friends_coincidences_assert($modifiedItem !== null && $modifiedItem['starts_at'] === '16:05' && $modifiedItem['same_course'] === true, 'Modified exception was not used.');
    friends_coincidences_assert(friends_coincidences_find($all, 'Inactivo') === null, 'Inactive friend should be ignored.');
    friends_coincidences_assert($coincidences->getCoincidencesForDate($userId, $date, [(int) $otherFriend['id']]) === [], 'Foreign friend id should not be exposed.');

    $differentCampusItem = friends_coincidences_find($all, 'Campus distinto');
    friends_coincidences_assert($differentCampusItem !== null && $differentCampusItem['same_campus'] === false && $differentCampusItem['friend_campus'] === 'Casa Central', 'Different campus handling failed.');
    $unknownCampusItem = friends_coincidences_find($all, 'Sin campus');
    friends_coincidences_assert($unknownCampusItem !== null && $unknownCampusItem['friend_campus'] === null && $unknownCampusItem['same_campus'] === false, 'Unknown campus handling failed.');

    $groupA = $scheduleService->createFriend($userId, ['name' => 'Grupo A', 'default_campus' => 'CSJ']);
    $groupB = $scheduleService->createFriend($userId, ['name' => 'Grupo B', 'default_campus' => 'CSJ']);
    $groupC = $scheduleService->createFriend($userId, ['name' => 'Grupo C', 'default_campus' => 'CSJ']);
    $scheduleService->createEntry($userId, 'friend', (int) $groupA['id'], ['weekday' => 3, 'starts_at' => '14:40', 'ends_at' => '15:50', 'course_name' => 'GA', 'campus' => 'CSJ']);
    $scheduleService->createEntry($userId, 'friend', (int) $groupB['id'], ['weekday' => 3, 'starts_at' => '14:40', 'ends_at' => '15:30', 'course_name' => 'GB', 'campus' => 'CSJ']);
    $scheduleService->createEntry($userId, 'friend', (int) $groupC['id'], ['weekday' => 3, 'starts_at' => '15:30', 'ends_at' => '15:50', 'course_name' => 'GC', 'campus' => 'CSJ']);

    $groups = $coincidences->getGroupCoincidencesForDate($userId, $date, [(int) $groupA['id'], (int) $groupB['id'], (int) $groupC['id']]);
    friends_coincidences_assert(count($groups) === 2, 'Group coincidences should split when friend set changes.');
    friends_coincidences_assert($groups[0]['starts_at'] === '14:40' && $groups[0]['ends_at'] === '15:30' && count($groups[0]['friends']) === 2, 'First group interval failed.');
    friends_coincidences_assert($groups[1]['starts_at'] === '15:30' && $groups[1]['ends_at'] === '15:50' && count($groups[1]['friends']) === 2, 'Second group interval failed.');
    friends_coincidences_assert(($groups[0]['same_campus'] ?? false) === true && is_array($groups[0]['user_block'] ?? null), 'Group campus or user block failed.');

    echo "Friends coincidences: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Friends coincidences: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
