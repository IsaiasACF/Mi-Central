<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_transcriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            video_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_language VARCHAR(10) NOT NULL,
            detected_language VARCHAR(10) NULL,
            model VARCHAR(64) NOT NULL,
            progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            full_text LONGTEXT NULL,
            error_message VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX video_transcriptions_user_video_index (user_id, video_id, created_at),
            INDEX video_transcriptions_status_index (status, created_at),
            CONSTRAINT video_transcriptions_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT video_transcriptions_video_fk
                FOREIGN KEY (video_id) REFERENCES video_files (id)
                ON DELETE CASCADE,
            CONSTRAINT video_transcriptions_status_check
                CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
            CONSTRAINT video_transcriptions_language_check
                CHECK (requested_language IN ('auto', 'es', 'en')),
            CONSTRAINT video_transcriptions_progress_check
                CHECK (progress_percent >= 0 AND progress_percent <= 100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_transcription_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transcription_id BIGINT UNSIGNED NOT NULL,
            segment_index INT UNSIGNED NOT NULL,
            start_seconds DECIMAL(12,3) NOT NULL,
            end_seconds DECIMAL(12,3) NOT NULL,
            text TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY video_transcription_segments_order_unique (transcription_id, segment_index),
            INDEX video_transcription_segments_time_index (transcription_id, start_seconds),
            CONSTRAINT video_transcription_segments_transcription_fk
                FOREIGN KEY (transcription_id) REFERENCES video_transcriptions (id)
                ON DELETE CASCADE,
            CONSTRAINT video_transcription_segments_range_check
                CHECK (start_seconds >= 0 AND start_seconds < end_seconds)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
