<?php
declare(strict_types=1);

namespace Modules\Friends;

use PDO;

final class FriendRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int
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
    public function findByIdForUser(int $userId, int $friendId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE f.id = :id AND f.user_id = :user_id
             LIMIT 1');
        $statement->execute([
            'id' => $friendId,
            'user_id' => $userId,
        ]);

        $friend = $statement->fetch();

        return is_array($friend) ? $friend : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['f.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (($filters['status'] ?? 'active') === 'active') {
            $where[] = 'f.is_active = 1';
        } elseif (($filters['status'] ?? 'active') === 'inactive') {
            $where[] = 'f.is_active = 0';
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY f.is_active DESC, f.name ASC, f.id DESC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $friendId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $friendId,
            'user_id' => $userId,
        ];

        foreach (['name', 'university', 'default_campus', 'notes', 'is_active'] as $field) {
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
            'UPDATE friends
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function setActive(int $userId, int $friendId, bool $active): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE friends
             SET is_active = :is_active
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'is_active' => $active ? 1 : 0,
            'id' => $friendId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $friendId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM friends WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $friendId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    private function selectSql(): string
    {
        return 'SELECT
                    f.id,
                    f.user_id,
                    f.name,
                    f.university,
                    f.default_campus,
                    f.notes,
                    f.is_active,
                    f.created_at,
                    f.updated_at,
                    (SELECT COUNT(*) FROM friend_schedule_entries e WHERE e.friend_id = f.id) AS schedule_entries_count,
                    (SELECT COUNT(*) FROM friend_schedule_exceptions x WHERE x.friend_id = f.id) AS schedule_exceptions_count
                FROM friends f';
    }
}
