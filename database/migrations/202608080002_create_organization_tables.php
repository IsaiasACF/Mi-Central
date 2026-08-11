<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_spaces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(140) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY organization_spaces_user_slug_unique (user_id, slug),
            INDEX organization_spaces_user_index (user_id),
            CONSTRAINT organization_spaces_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_categories_user_index (user_id),
            INDEX organization_categories_space_index (space_id),
            CONSTRAINT organization_categories_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_categories_space_fk
                FOREIGN KEY (space_id) REFERENCES organization_spaces (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            starts_on DATE NULL,
            due_on DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_projects_user_index (user_id),
            INDEX organization_projects_space_index (space_id),
            INDEX organization_projects_status_index (status),
            CONSTRAINT organization_projects_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_projects_space_fk
                FOREIGN KEY (space_id) REFERENCES organization_spaces (id)
                ON DELETE RESTRICT,
            CONSTRAINT organization_projects_status_check
                CHECK (status IN ('active', 'completed', 'archived'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            parent_task_id BIGINT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            priority VARCHAR(24) NOT NULL DEFAULT 'normal',
            due_at DATETIME NULL,
            completed_at DATETIME NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_tasks_user_index (user_id),
            INDEX organization_tasks_space_index (space_id),
            INDEX organization_tasks_category_index (category_id),
            INDEX organization_tasks_project_index (project_id),
            INDEX organization_tasks_parent_index (parent_task_id),
            INDEX organization_tasks_status_index (status),
            INDEX organization_tasks_due_at_index (due_at),
            CONSTRAINT organization_tasks_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_tasks_space_fk
                FOREIGN KEY (space_id) REFERENCES organization_spaces (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_tasks_category_fk
                FOREIGN KEY (category_id) REFERENCES organization_categories (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_tasks_project_fk
                FOREIGN KEY (project_id) REFERENCES organization_projects (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_tasks_parent_fk
                FOREIGN KEY (parent_task_id) REFERENCES organization_tasks (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_tasks_status_check
                CHECK (status IN ('pending', 'completed')),
            CONSTRAINT organization_tasks_priority_check
                CHECK (priority IN ('low', 'normal', 'high'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_notes_user_index (user_id),
            INDEX organization_notes_space_index (space_id),
            INDEX organization_notes_category_index (category_id),
            CONSTRAINT organization_notes_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_notes_space_fk
                FOREIGN KEY (space_id) REFERENCES organization_spaces (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_notes_category_fk
                FOREIGN KEY (category_id) REFERENCES organization_categories (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS organization_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            all_day TINYINT(1) NOT NULL DEFAULT 0,
            location VARCHAR(180) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX organization_events_user_index (user_id),
            INDEX organization_events_space_index (space_id),
            INDEX organization_events_category_index (category_id),
            INDEX organization_events_starts_at_index (starts_at),
            CONSTRAINT organization_events_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT organization_events_space_fk
                FOREIGN KEY (space_id) REFERENCES organization_spaces (id)
                ON DELETE SET NULL,
            CONSTRAINT organization_events_category_fk
                FOREIGN KEY (category_id) REFERENCES organization_categories (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
