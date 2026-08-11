<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            reminder_id BIGINT UNSIGNED NULL,
            type VARCHAR(48) NOT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NULL,
            scheduled_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX notifications_user_index (user_id),
            INDEX notifications_reminder_index (reminder_id),
            INDEX notifications_scheduled_at_index (scheduled_at),
            UNIQUE KEY notifications_reminder_occurrence_unique (reminder_id, type, scheduled_at),
            CONSTRAINT notifications_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT notifications_reminder_fk
                FOREIGN KEY (reminder_id) REFERENCES organization_reminders (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
