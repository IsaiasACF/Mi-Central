<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_edit_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            video_id BIGINT UNSIGNED NOT NULL,
            source_start_seconds DECIMAL(12,3) NOT NULL,
            source_end_seconds DECIMAL(12,3) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_included TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX video_edit_segments_video_source_index (video_id, source_start_seconds, source_end_seconds),
            INDEX video_edit_segments_video_order_index (video_id, is_included, sort_order),
            CONSTRAINT video_edit_segments_video_fk
                FOREIGN KEY (video_id) REFERENCES video_files (id)
                ON DELETE CASCADE,
            CONSTRAINT video_edit_segments_range_check
                CHECK (source_start_seconds >= 0 AND source_start_seconds < source_end_seconds)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
