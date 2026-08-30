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
            'table' => 'notifications',
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
            'table' => 'notifications',
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    };

    if (!$columnExists('source_module')) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN source_module VARCHAR(32) NOT NULL DEFAULT 'reminders' AFTER type");
    }

    if (!$columnExists('entity_type')) {
        $pdo->exec('ALTER TABLE notifications ADD COLUMN entity_type VARCHAR(48) NULL AFTER source_module');
    }

    if (!$columnExists('entity_id')) {
        $pdo->exec('ALTER TABLE notifications ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type');
    }

    if (!$columnExists('dedupe_key')) {
        $pdo->exec('ALTER TABLE notifications ADD COLUMN dedupe_key VARCHAR(180) NULL AFTER entity_id');
    }

    $pdo->exec(
        "UPDATE notifications
         SET source_module = 'reminders',
             entity_type = COALESCE(entity_type, 'reminder'),
             entity_id = COALESCE(entity_id, reminder_id),
             dedupe_key = COALESCE(dedupe_key, CONCAT('reminder_due:', COALESCE(reminder_id, id), ':', DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:%i:%s')))
         WHERE source_module = '' OR source_module IS NULL OR dedupe_key IS NULL"
    );

    if (!$indexExists('notifications_source_index')) {
        $pdo->exec('ALTER TABLE notifications ADD INDEX notifications_source_index (user_id, source_module, entity_type, entity_id)');
    }

    if (!$indexExists('notifications_dedupe_unique')) {
        $pdo->exec('ALTER TABLE notifications ADD UNIQUE KEY notifications_dedupe_unique (user_id, dedupe_key)');
    }
};
