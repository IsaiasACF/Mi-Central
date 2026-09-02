<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $statement->execute([
        'table_name' => 'user_schedule_entries',
        'index_name' => 'user_schedule_entries_id_user_unique',
    ]);

    if ((int) $statement->fetchColumn() > 0) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE user_schedule_entries
         ADD UNIQUE KEY user_schedule_entries_id_user_unique (id, user_id)'
    );
};
