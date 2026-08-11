<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class NoteRepository
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
            'INSERT INTO organization_notes (user_id, space_id, category_id, title, content)
             VALUES (:user_id, :space_id, :category_id, :title, :content)'
        );
        $statement->execute([
            'user_id' => $userId,
            'space_id' => $data['space_id'],
            'category_id' => null,
            'title' => $data['title'],
            'content' => $data['content'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $noteId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, category_id, title, content, created_at, updated_at
             FROM organization_notes
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $noteId,
            'user_id' => $userId,
        ]);

        $note = $statement->fetch();

        return is_array($note) ? $note : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (array_key_exists('space_id', $filters)) {
            $where[] = 'space_id = :space_id';
            $params['space_id'] = $filters['space_id'];
        }

        if (!empty($filters['space_id_is_null'])) {
            $where[] = 'space_id IS NULL';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, space_id, category_id, title, content, created_at, updated_at
             FROM organization_notes
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC, created_at DESC, id DESC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $noteId, array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $sets = [];
        $params = [
            'id' => $noteId,
            'user_id' => $userId,
        ];

        foreach (['space_id', 'title', 'content'] as $field) {
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
            'UPDATE organization_notes
             SET ' . implode(', ', $sets) . '
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $noteId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM organization_notes WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $noteId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function spaceBelongsToUser(int $userId, int $spaceId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM organization_spaces WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute([
            'id' => $spaceId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
