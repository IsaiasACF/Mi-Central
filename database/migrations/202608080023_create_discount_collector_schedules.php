<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_collector_schedules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            collector_key VARCHAR(120) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            interval_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
            next_run_at DATETIME NULL,
            last_started_at DATETIME NULL,
            last_completed_at DATETIME NULL,
            last_status VARCHAR(32) NOT NULL DEFAULT 'never',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY discount_collector_schedules_key_unique (collector_key),
            INDEX discount_collector_schedules_due_index (enabled, next_run_at, last_status),
            CONSTRAINT discount_collector_schedules_enabled_check
                CHECK (enabled IN (0, 1)),
            CONSTRAINT discount_collector_schedules_interval_check
                CHECK (interval_minutes >= 1),
            CONSTRAINT discount_collector_schedules_status_check
                CHECK (last_status IN ('never', 'running', 'success', 'failed', 'not_registered'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
