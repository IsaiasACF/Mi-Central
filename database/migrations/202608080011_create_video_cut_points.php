<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_cut_points (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            video_id BIGINT UNSIGNED NOT NULL,
            position_seconds DECIMAL(12,3) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX video_cut_points_video_position_index (video_id, position_seconds),
            CONSTRAINT video_cut_points_video_fk
                FOREIGN KEY (video_id) REFERENCES video_files (id)
                ON DELETE CASCADE,
            CONSTRAINT video_cut_points_position_check
                CHECK (position_seconds > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
