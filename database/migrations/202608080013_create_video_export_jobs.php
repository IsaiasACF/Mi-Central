<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_export_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            video_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            output_name VARCHAR(255) NOT NULL,
            output_stored_name VARCHAR(96) NOT NULL,
            output_path VARCHAR(255) NULL,
            estimated_duration_seconds DECIMAL(12,3) NOT NULL,
            output_size_bytes BIGINT UNSIGNED NULL,
            error_message VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY video_export_jobs_output_stored_name_unique (output_stored_name),
            INDEX video_export_jobs_user_video_index (user_id, video_id, created_at),
            INDEX video_export_jobs_status_index (status, created_at),
            CONSTRAINT video_export_jobs_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT video_export_jobs_video_fk
                FOREIGN KEY (video_id) REFERENCES video_files (id)
                ON DELETE CASCADE,
            CONSTRAINT video_export_jobs_status_check
                CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
            CONSTRAINT video_export_jobs_duration_check
                CHECK (estimated_duration_seconds > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_export_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            export_job_id BIGINT UNSIGNED NOT NULL,
            source_start_seconds DECIMAL(12,3) NOT NULL,
            source_end_seconds DECIMAL(12,3) NOT NULL,
            sort_order INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX video_export_segments_job_order_index (export_job_id, sort_order),
            CONSTRAINT video_export_segments_job_fk
                FOREIGN KEY (export_job_id) REFERENCES video_export_jobs (id)
                ON DELETE CASCADE,
            CONSTRAINT video_export_segments_range_check
                CHECK (source_start_seconds >= 0 AND source_start_seconds < source_end_seconds)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
