<?php
declare(strict_types=1);

namespace Modules\Friends;

use PDO;

final class FriendScheduleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFriend(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO friends
                (user_id, name, university, default_campus, notes, is_active)
             VALUES
                (:user_id, :name, :university, :default_campus, :notes, :is_active)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $data['name'],
            'university' => $data['university'],
            'default_campus' => $data['default_campus'],
            'notes' => $data['notes'],
            'is_active' => $data['is_active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFriendForUser(int $userId, int $friendId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, university, default_campus, notes, is_active, created_at, updated_at
             FROM friends
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $friendId,
            'user_id' => $userId,
        ]);

        $friend = $statement->fetch();

        return is_array($friend) ? $friend : null;
    }

    public function friendBelongsToUser(int $userId, int $friendId): bool
    {
        return $this->findFriendForUser($userId, $friendId) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveFriendsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, university, default_campus, notes, is_active, created_at, updated_at
             FROM friends
             WHERE user_id = :user_id AND is_active = 1
             ORDER BY name ASC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFriendScheduleEntry(int $friendId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO friend_schedule_entries
                (friend_id, weekday, starts_at, ends_at, course_name, course_code, room, campus, valid_from, valid_until)
             VALUES
                (:friend_id, :weekday, :starts_at, :ends_at, :course_name, :course_code, :room, :campus, :valid_from, :valid_until)'
        );
        $statement->execute([
            'friend_id' => $friendId,
            'weekday' => $data['weekday'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'course_code' => $data['course_code'],
            'room' => $data['room'],
            'campus' => $data['campus'],
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFriendScheduleEntryForUser(int $userId, int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.friend_id, e.weekday, e.starts_at, e.ends_at, e.course_name,
                    e.course_code, e.room, e.campus, e.valid_from, e.valid_until,
                    e.created_at, e.updated_at,
                    f.user_id, f.default_campus
             FROM friend_schedule_entries e
             INNER JOIN friends f ON f.id = e.friend_id
             WHERE e.id = :id AND f.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $entryId,
            'user_id' => $userId,
        ]);

        $entry = $statement->fetch();

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleEntries(int $userId, int $friendId): array
    {
        return $this->listFriendScheduleEntriesFiltered($userId, $friendId, []);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleEntriesFiltered(int $userId, int $friendId, array $filters = []): array
    {
        $where = ['e.friend_id = :friend_id', 'f.user_id = :user_id'];
        $params = [
            'friend_id' => $friendId,
            'user_id' => $userId,
        ];

        if (isset($filters['date']) && is_string($filters['date']) && $filters['date'] !== '') {
            $where[] = '(e.valid_from IS NULL OR e.valid_from <= :active_date_from)';
            $where[] = '(e.valid_until IS NULL OR e.valid_until >= :active_date_until)';
            $params['active_date_from'] = $filters['date'];
            $params['active_date_until'] = $filters['date'];
        }

        $statement = $this->pdo->prepare(
            'SELECT e.id, e.friend_id, e.weekday, e.starts_at, e.ends_at, e.course_name,
                    e.course_code, e.room, e.campus, e.valid_from, e.valid_until,
                    e.created_at, e.updated_at,
                    COALESCE(e.campus, f.default_campus) AS effective_campus
             FROM friend_schedule_entries e
             INNER JOIN friends f ON f.id = e.friend_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.weekday ASC, e.starts_at ASC, e.id ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFriendScheduleEntry(int $userId, int $entryId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $entryId,
            'user_id' => $userId,
        ];

        foreach (['weekday', 'starts_at', 'ends_at', 'course_name', 'course_code', 'room', 'campus', 'valid_from', 'valid_until'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "e.{$field} = :{$field}";
            $params[$field] = $data[$field];
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE friend_schedule_entries e
             INNER JOIN friends f ON f.id = e.friend_id
             SET ' . implode(', ', $sets) . '
             WHERE e.id = :id AND f.user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteFriendScheduleEntry(int $userId, int $entryId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE e
             FROM friend_schedule_entries e
             INNER JOIN friends f ON f.id = e.friend_id
             WHERE e.id = :id AND f.user_id = :user_id'
        );
        $statement->execute([
            'id' => $entryId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function friendScheduleDuplicateExists(int $userId, int $friendId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM friend_schedule_entries e
             INNER JOIN friends f ON f.id = e.friend_id
             WHERE f.user_id = :user_id
               AND e.friend_id = :friend_id
               AND e.weekday = :weekday
               AND e.starts_at = :starts_at
               AND e.ends_at = :ends_at
               AND e.course_name = :course_name
               AND ((e.valid_from IS NULL AND :valid_from IS NULL) OR e.valid_from = :valid_from_match)
               AND ((e.valid_until IS NULL AND :valid_until IS NULL) OR e.valid_until = :valid_until_match)
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'friend_id' => $friendId,
            'weekday' => $data['weekday'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'valid_from' => $data['valid_from'],
            'valid_from_match' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
            'valid_until_match' => $data['valid_until'],
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFriendScheduleException(int $friendId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO friend_schedule_exceptions
                (friend_id, schedule_entry_id, exception_date, type, starts_at, ends_at, course_name, course_code, room, campus, notes)
             VALUES
                (:friend_id, :schedule_entry_id, :exception_date, :type, :starts_at, :ends_at, :course_name, :course_code, :room, :campus, :notes)'
        );
        $statement->execute([
            'friend_id' => $friendId,
            'schedule_entry_id' => $data['schedule_entry_id'],
            'exception_date' => $data['exception_date'],
            'type' => $data['type'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'course_code' => $data['course_code'],
            'room' => $data['room'],
            'campus' => $data['campus'],
            'notes' => $data['notes'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleExceptions(int $userId, int $friendId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT x.id, x.friend_id, x.schedule_entry_id, x.exception_date, x.type,
                    x.starts_at, x.ends_at, x.course_name, x.course_code, x.room, x.campus, x.notes,
                    x.created_at, x.updated_at
             FROM friend_schedule_exceptions x
             INNER JOIN friends f ON f.id = x.friend_id
             WHERE x.friend_id = :friend_id AND f.user_id = :user_id
             ORDER BY x.exception_date ASC, x.id ASC'
        );
        $statement->execute([
            'friend_id' => $friendId,
            'user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleExceptionsForDate(int $userId, int $friendId, string $date): array
    {
        $statement = $this->pdo->prepare(
            'SELECT x.id, x.friend_id, x.schedule_entry_id, x.exception_date, x.type,
                    x.starts_at, x.ends_at, x.course_name, x.course_code, x.room, x.campus, x.notes,
                    x.created_at, x.updated_at
             FROM friend_schedule_exceptions x
             INNER JOIN friends f ON f.id = x.friend_id
             WHERE x.friend_id = :friend_id
               AND f.user_id = :user_id
               AND x.exception_date = :exception_date
             ORDER BY x.exception_date ASC, x.id ASC'
        );
        $statement->execute([
            'friend_id' => $friendId,
            'user_id' => $userId,
            'exception_date' => $date,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFriendScheduleExceptionForUser(int $userId, int $exceptionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT x.id, x.friend_id, x.schedule_entry_id, x.exception_date, x.type,
                    x.starts_at, x.ends_at, x.course_name, x.course_code, x.room, x.campus, x.notes,
                    x.created_at, x.updated_at
             FROM friend_schedule_exceptions x
             INNER JOIN friends f ON f.id = x.friend_id
             WHERE x.id = :id AND f.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $exceptionId,
            'user_id' => $userId,
        ]);

        $exception = $statement->fetch();

        return is_array($exception) ? $exception : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFriendScheduleException(int $userId, int $exceptionId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $exceptionId,
            'user_id' => $userId,
        ];

        foreach (['schedule_entry_id', 'exception_date', 'type', 'starts_at', 'ends_at', 'course_name', 'course_code', 'room', 'campus', 'notes'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "x.{$field} = :{$field}";
            $params[$field] = $data[$field];
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE friend_schedule_exceptions x
             INNER JOIN friends f ON f.id = x.friend_id
             SET ' . implode(', ', $sets) . '
             WHERE x.id = :id AND f.user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteFriendScheduleException(int $userId, int $exceptionId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE x
             FROM friend_schedule_exceptions x
             INNER JOIN friends f ON f.id = x.friend_id
             WHERE x.id = :id AND f.user_id = :user_id'
        );
        $statement->execute([
            'id' => $exceptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createUserScheduleEntry(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_schedule_entries
                (user_id, weekday, starts_at, ends_at, course_name, course_code, room, campus, valid_from, valid_until)
             VALUES
                (:user_id, :weekday, :starts_at, :ends_at, :course_name, :course_code, :room, :campus, :valid_from, :valid_until)'
        );
        $statement->execute([
            'user_id' => $userId,
            'weekday' => $data['weekday'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'course_code' => $data['course_code'],
            'room' => $data['room'],
            'campus' => $data['campus'],
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleEntries(int $userId): array
    {
        return $this->listUserScheduleEntriesFiltered($userId, []);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleEntriesFiltered(int $userId, array $filters = []): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (isset($filters['date']) && is_string($filters['date']) && $filters['date'] !== '') {
            $where[] = '(valid_from IS NULL OR valid_from <= :active_date_from)';
            $where[] = '(valid_until IS NULL OR valid_until >= :active_date_until)';
            $params['active_date_from'] = $filters['date'];
            $params['active_date_until'] = $filters['date'];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, weekday, starts_at, ends_at, course_name, course_code,
                    room, campus, valid_from, valid_until, created_at, updated_at
             FROM user_schedule_entries
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY weekday ASC, starts_at ASC, id ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserScheduleEntryForUser(int $userId, int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, weekday, starts_at, ends_at, course_name, course_code,
                    room, campus, valid_from, valid_until, created_at, updated_at
             FROM user_schedule_entries
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $entryId,
            'user_id' => $userId,
        ]);

        $entry = $statement->fetch();

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUserScheduleEntry(int $userId, int $entryId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $entryId,
            'user_id' => $userId,
        ];

        foreach (['weekday', 'starts_at', 'ends_at', 'course_name', 'course_code', 'room', 'campus', 'valid_from', 'valid_until'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "{$field} = :{$field}";
            $params[$field] = $data[$field];
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_schedule_entries
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteUserScheduleEntry(int $userId, int $entryId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_schedule_entries
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $entryId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function userScheduleDuplicateExists(int $userId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM user_schedule_entries
             WHERE user_id = :user_id
               AND weekday = :weekday
               AND starts_at = :starts_at
               AND ends_at = :ends_at
               AND course_name = :course_name
               AND ((valid_from IS NULL AND :valid_from IS NULL) OR valid_from = :valid_from_match)
               AND ((valid_until IS NULL AND :valid_until IS NULL) OR valid_until = :valid_until_match)
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'weekday' => $data['weekday'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'valid_from' => $data['valid_from'],
            'valid_from_match' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
            'valid_until_match' => $data['valid_until'],
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createUserScheduleException(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_schedule_exceptions
                (user_id, schedule_entry_id, exception_date, type, starts_at, ends_at, course_name, course_code, room, campus, notes)
             VALUES
                (:user_id, :schedule_entry_id, :exception_date, :type, :starts_at, :ends_at, :course_name, :course_code, :room, :campus, :notes)'
        );
        $statement->execute([
            'user_id' => $userId,
            'schedule_entry_id' => $data['schedule_entry_id'],
            'exception_date' => $data['exception_date'],
            'type' => $data['type'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'course_name' => $data['course_name'],
            'course_code' => $data['course_code'],
            'room' => $data['room'],
            'campus' => $data['campus'],
            'notes' => $data['notes'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleExceptions(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, schedule_entry_id, exception_date, type,
                    starts_at, ends_at, course_name, course_code, room, campus, notes,
                    created_at, updated_at
             FROM user_schedule_exceptions
             WHERE user_id = :user_id
             ORDER BY exception_date ASC, id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleExceptionsForDate(int $userId, string $date): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, schedule_entry_id, exception_date, type,
                    starts_at, ends_at, course_name, course_code, room, campus, notes,
                    created_at, updated_at
             FROM user_schedule_exceptions
             WHERE user_id = :user_id AND exception_date = :exception_date
             ORDER BY exception_date ASC, id ASC'
        );
        $statement->execute([
            'user_id' => $userId,
            'exception_date' => $date,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserScheduleExceptionForUser(int $userId, int $exceptionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, schedule_entry_id, exception_date, type,
                    starts_at, ends_at, course_name, course_code, room, campus, notes,
                    created_at, updated_at
             FROM user_schedule_exceptions
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $exceptionId,
            'user_id' => $userId,
        ]);

        $exception = $statement->fetch();

        return is_array($exception) ? $exception : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUserScheduleException(int $userId, int $exceptionId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $exceptionId,
            'user_id' => $userId,
        ];

        foreach (['schedule_entry_id', 'exception_date', 'type', 'starts_at', 'ends_at', 'course_name', 'course_code', 'room', 'campus', 'notes'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "{$field} = :{$field}";
            $params[$field] = $data[$field];
        }

        if ($sets === []) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_schedule_exceptions
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteUserScheduleException(int $userId, int $exceptionId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_schedule_exceptions
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $exceptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }
}
