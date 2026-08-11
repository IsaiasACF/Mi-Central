<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_reminders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            remind_at DATETIME NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_reminders_user_index (user_id),
            INDEX organization_reminders_task_index (task_id),
            INDEX organization_reminders_project_index (project_id),
            INDEX organization_reminders_status_index (status),
            INDEX organization_reminders_remind_at_index (remind_at),
            CONSTRAINT organization_reminders_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_reminders_task_fk
                FOREIGN KEY (task_id) REFERENCES organization_tasks (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_reminders_project_fk
                FOREIGN KEY (project_id) REFERENCES organization_projects (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_reminders_status_check
                CHECK (status IN ('pending', 'dismissed', 'completed')),
            CONSTRAINT organization_reminders_single_target_check
                CHECK (task_id IS NULL OR project_id IS NULL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
