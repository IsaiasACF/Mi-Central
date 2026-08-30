<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $columnExists = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $statement->execute([
            'table' => 'organization_notes',
            'column' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    };
    $indexExists = static function (string $index) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index_name'
        );
        $statement->execute([
            'table' => 'organization_notes',
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    };

    if (!$columnExists('status')) {
        $pdo->exec("ALTER TABLE organization_notes ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'active' AFTER content");
    }

    if (!$columnExists('completed_at')) {
        $pdo->exec('ALTER TABLE organization_notes ADD COLUMN completed_at DATETIME NULL AFTER status');
    }

    if (!$indexExists('organization_notes_status_index')) {
        $pdo->exec('ALTER TABLE organization_notes ADD INDEX organization_notes_status_index (status)');
    }
};
