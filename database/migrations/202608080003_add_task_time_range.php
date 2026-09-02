<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $columnExists = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $statement->execute([
            'table' => 'organization_tasks',
            'column' => $column,
        ]);

        return $statement->fetchColumn() !== false;
    };

    $indexExists = static function (string $index) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name
             LIMIT 1'
        );
        $statement->execute([
            'table' => 'organization_tasks',
            'index_name' => $index,
        ]);

        return $statement->fetchColumn() !== false;
    };

    if (!$columnExists('starts_at')) {
        $pdo->exec(
            'ALTER TABLE organization_tasks
             ADD COLUMN starts_at DATETIME NULL AFTER priority'
        );
    }

    if (!$columnExists('ends_at')) {
        $pdo->exec(
            'ALTER TABLE organization_tasks
             ADD COLUMN ends_at DATETIME NULL AFTER starts_at'
        );
    }

    if (!$indexExists('organization_tasks_starts_at_index')) {
        $pdo->exec(
            'CREATE INDEX organization_tasks_starts_at_index
             ON organization_tasks (starts_at)'
        );
    }

    if (!$indexExists('organization_tasks_ends_at_index')) {
        $pdo->exec(
            'CREATE INDEX organization_tasks_ends_at_index
             ON organization_tasks (ends_at)'
        );
    }
};
