<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS friends (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(180) NOT NULL,
            university VARCHAR(180) NULL,
            default_campus VARCHAR(180) NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX friends_user_index (user_id),
            CONSTRAINT friends_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT friends_is_active_check
                CHECK (is_active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS friend_schedule_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            friend_id BIGINT UNSIGNED NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL,
            starts_at TIME NOT NULL,
            ends_at TIME NOT NULL,
            course_name VARCHAR(180) NOT NULL,
            course_code VARCHAR(80) NULL,
            room VARCHAR(120) NULL,
            campus VARCHAR(180) NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX friend_schedule_entries_friend_weekday_index (friend_id, weekday),
            UNIQUE KEY friend_schedule_entries_id_friend_unique (id, friend_id),
            CONSTRAINT friend_schedule_entries_friend_fk
                FOREIGN KEY (friend_id) REFERENCES friends (id)
                ON DELETE CASCADE,
            CONSTRAINT friend_schedule_entries_weekday_check
                CHECK (weekday BETWEEN 1 AND 7),
            CONSTRAINT friend_schedule_entries_time_range_check
                CHECK (ends_at > starts_at),
            CONSTRAINT friend_schedule_entries_valid_period_check
                CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS friend_schedule_exceptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            friend_id BIGINT UNSIGNED NOT NULL,
            schedule_entry_id BIGINT UNSIGNED NULL,
            exception_date DATE NOT NULL,
            type VARCHAR(24) NOT NULL,
            starts_at TIME NULL,
            ends_at TIME NULL,
            course_name VARCHAR(180) NULL,
            room VARCHAR(120) NULL,
            campus VARCHAR(180) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX friend_schedule_exceptions_friend_date_index (friend_id, exception_date),
            INDEX friend_schedule_exceptions_entry_index (schedule_entry_id),
            CONSTRAINT friend_schedule_exceptions_friend_fk
                FOREIGN KEY (friend_id) REFERENCES friends (id)
                ON DELETE CASCADE,
            CONSTRAINT friend_schedule_exceptions_entry_fk
                FOREIGN KEY (schedule_entry_id, friend_id) REFERENCES friend_schedule_entries (id, friend_id)
                ON DELETE CASCADE,
            CONSTRAINT friend_schedule_exceptions_type_check
                CHECK (type IN ('cancelled', 'absent', 'modified')),
            CONSTRAINT friend_schedule_exceptions_time_range_check
                CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_schedule_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL,
            starts_at TIME NOT NULL,
            ends_at TIME NOT NULL,
            course_name VARCHAR(180) NOT NULL,
            course_code VARCHAR(80) NULL,
            room VARCHAR(120) NULL,
            campus VARCHAR(180) NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX user_schedule_entries_user_weekday_index (user_id, weekday),
            CONSTRAINT user_schedule_entries_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT user_schedule_entries_weekday_check
                CHECK (weekday BETWEEN 1 AND 7),
            CONSTRAINT user_schedule_entries_time_range_check
                CHECK (ends_at > starts_at),
            CONSTRAINT user_schedule_entries_valid_period_check
                CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
