<?php
declare(strict_types=1);

namespace Modules\Organization;

use PDO;

final class LabelRepository
{
    private const ENTITY_TABLES = [
        'task' => ['table' => 'organization_task_labels', 'column' => 'task_id'],
        'project' => ['table' => 'organization_project_labels', 'column' => 'project_id'],
        'note' => ['table' => 'organization_note_labels', 'column' => 'note_id'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $name, string $normalizedName, string $color): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO organization_labels (user_id, name, normalized_name, color)
             VALUES (:user_id, :name, :normalized_name, :color)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'color' => $color,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $labelId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, color, created_at, updated_at
             FROM organization_labels
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $labelId,
            'user_id' => $userId,
        ]);

        $label = $statement->fetch();

        return is_array($label) ? $label : null;
    }

    public function normalizedNameExists(int $userId, string $normalizedName, ?int $exceptId = null): bool
    {
        $where = 'user_id = :user_id AND normalized_name = :normalized_name';
        $params = [
            'user_id' => $userId,
            'normalized_name' => $normalizedName,
        ];

        if ($exceptId !== null) {
            $where .= ' AND id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM organization_labels WHERE ' . $where . ' LIMIT 1'
        );
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, normalized_name, color, created_at, updated_at
             FROM organization_labels
             WHERE user_id = :user_id
             ORDER BY name ASC, id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function update(int $userId, int $labelId, string $name, string $normalizedName, string $color): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE organization_labels
             SET name = :name, normalized_name = :normalized_name, color = :color
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $labelId,
            'user_id' => $userId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'color' => $color,
        ]);

        return $statement->rowCount() > 0;
    }

    public function delete(int $userId, int $labelId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM organization_labels WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $labelId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<int, int> $labelIds
     */
    public function allLabelsBelongToUser(int $userId, array $labelIds): bool
    {
        $labelIds = array_values(array_unique(array_filter($labelIds, static fn (int $id): bool => $id > 0)));

        if ($labelIds === []) {
            return true;
        }

        $placeholders = implode(', ', array_fill(0, count($labelIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM organization_labels WHERE user_id = ? AND id IN ({$placeholders})"
        );
        $statement->execute(array_merge([$userId], $labelIds));

        return (int) $statement->fetchColumn() === count($labelIds);
    }

    /**
     * @param array<int, int> $labelIds
     */
    public function syncForEntity(int $userId, string $entityType, int $entityId, array $labelIds): void
    {
        $config = self::ENTITY_TABLES[$entityType] ?? null;

        if ($config === null) {
            throw new TaskValidationException('Tipo de etiqueta invalido.');
        }

        $table = $config['table'];
        $column = $config['column'];

        $delete = $this->pdo->prepare(
            "DELETE FROM {$table} WHERE user_id = :user_id AND {$column} = :entity_id"
        );
        $delete->execute([
            'user_id' => $userId,
            'entity_id' => $entityId,
        ]);

        $labelIds = array_values(array_unique(array_filter($labelIds, static fn (int $id): bool => $id > 0)));

        if ($labelIds === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$table} (user_id, {$column}, label_id)
             VALUES (:user_id, :entity_id, :label_id)"
        );

        foreach ($labelIds as $labelId) {
            $insert->execute([
                'user_id' => $userId,
                'entity_id' => $entityId,
                'label_id' => $labelId,
            ]);
        }
    }

    /**
     * @param array<int, int> $entityIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function labelsForEntities(int $userId, string $entityType, array $entityIds): array
    {
        $config = self::ENTITY_TABLES[$entityType] ?? null;

        if ($config === null) {
            throw new TaskValidationException('Tipo de etiqueta invalido.');
        }

        $entityIds = array_values(array_unique(array_filter($entityIds, static fn (int $id): bool => $id > 0)));

        if ($entityIds === []) {
            return [];
        }

        $table = $config['table'];
        $column = $config['column'];
        $placeholders = implode(', ', array_fill(0, count($entityIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT pivot.{$column} AS entity_id, labels.id, labels.name, labels.color
             FROM {$table} pivot
             INNER JOIN organization_labels labels
                ON labels.id = pivot.label_id AND labels.user_id = pivot.user_id
             WHERE pivot.user_id = ? AND pivot.{$column} IN ({$placeholders})
             ORDER BY labels.name ASC, labels.id ASC"
        );
        $statement->execute(array_merge([$userId], $entityIds));

        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            $entityId = (int) $row['entity_id'];
            $grouped[$entityId][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'color' => (string) $row['color'],
            ];
        }

        return $grouped;
    }
}
