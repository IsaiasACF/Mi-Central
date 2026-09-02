<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_files (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(180) NOT NULL,
            stored_name VARCHAR(120) NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            extension VARCHAR(16) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending_metadata',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY video_files_stored_name_unique (stored_name),
            INDEX video_files_user_index (user_id),
            INDEX video_files_user_created_index (user_id, created_at),
            INDEX video_files_status_index (status),
            CONSTRAINT video_files_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT video_files_status_check
                CHECK (status IN ('pending_metadata', 'uploaded'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
