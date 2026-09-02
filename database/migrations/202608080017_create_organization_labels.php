<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_labels (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            normalized_name VARCHAR(120) NOT NULL,
            color CHAR(7) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY organization_labels_user_name_unique (user_id, normalized_name),
            INDEX organization_labels_user_index (user_id),
            CONSTRAINT organization_labels_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_labels_color_check
                CHECK (color REGEXP '^#[0-9A-F]{6}$')
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_task_labels (
            user_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NOT NULL,
            label_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, label_id),
            INDEX organization_task_labels_user_label_index (user_id, label_id),
            CONSTRAINT organization_task_labels_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_task_labels_task_fk
                FOREIGN KEY (task_id) REFERENCES organization_tasks (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_task_labels_label_fk
                FOREIGN KEY (label_id) REFERENCES organization_labels (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_project_labels (
            user_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            label_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, label_id),
            INDEX organization_project_labels_user_label_index (user_id, label_id),
            CONSTRAINT organization_project_labels_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_project_labels_project_fk
                FOREIGN KEY (project_id) REFERENCES organization_projects (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_project_labels_label_fk
                FOREIGN KEY (label_id) REFERENCES organization_labels (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_note_labels (
            user_id BIGINT UNSIGNED NOT NULL,
            note_id BIGINT UNSIGNED NOT NULL,
            label_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (note_id, label_id),
            INDEX organization_note_labels_user_label_index (user_id, label_id),
            CONSTRAINT organization_note_labels_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_note_labels_note_fk
                FOREIGN KEY (note_id) REFERENCES organization_notes (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_note_labels_label_fk
                FOREIGN KEY (label_id) REFERENCES organization_labels (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
