<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $columnStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $columnStatement->execute([
        'table_name' => 'friend_schedule_exceptions',
        'column_name' => 'course_code',
    ]);

    if ((int) $columnStatement->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE friend_schedule_exceptions
             ADD COLUMN course_code VARCHAR(80) NULL AFTER course_name'
        );
    }

    $indexStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $indexStatement->execute([
        'table_name' => 'user_schedule_entries',
        'index_name' => 'user_schedule_entries_id_user_unique',
    ]);

    if ((int) $indexStatement->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE user_schedule_entries
             ADD UNIQUE KEY user_schedule_entries_id_user_unique (id, user_id)'
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_schedule_exceptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            schedule_entry_id BIGINT UNSIGNED NULL,
            exception_date DATE NOT NULL,
            type VARCHAR(24) NOT NULL,
            starts_at TIME NULL,
            ends_at TIME NULL,
            course_name VARCHAR(180) NULL,
            course_code VARCHAR(80) NULL,
            room VARCHAR(120) NULL,
            campus VARCHAR(180) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX user_schedule_exceptions_user_date_index (user_id, exception_date),
            INDEX user_schedule_exceptions_entry_index (schedule_entry_id),
            CONSTRAINT user_schedule_exceptions_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT user_schedule_exceptions_entry_fk
                FOREIGN KEY (schedule_entry_id, user_id) REFERENCES user_schedule_entries (id, user_id)
                ON DELETE CASCADE,
            CONSTRAINT user_schedule_exceptions_type_check
                CHECK (type IN ('cancelled', 'absent', 'modified')),
            CONSTRAINT user_schedule_exceptions_time_range_check
                CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
