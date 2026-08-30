<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_collector_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            collector_key VARCHAR(120) NOT NULL,
            trigger_type VARCHAR(24) NOT NULL,
            status VARCHAR(24) NOT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            duration_ms INT UNSIGNED NULL,
            collected_count INT UNSIGNED NOT NULL DEFAULT 0,
            normalized_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            warning_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_type VARCHAR(32) NULL,
            error_message VARCHAR(1200) NULL,
            warning_summary TEXT NULL,
            error_summary TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX discount_collector_runs_collector_started_index (collector_key, started_at),
            INDEX discount_collector_runs_status_started_index (status, started_at),
            CONSTRAINT discount_collector_runs_trigger_check
                CHECK (trigger_type IN ('scheduled', 'manual', 'dry_run')),
            CONSTRAINT discount_collector_runs_status_check
                CHECK (status IN ('running', 'success', 'partial', 'failed')),
            CONSTRAINT discount_collector_runs_error_type_check
                CHECK (error_type IS NULL OR error_type IN ('configuration', 'http', 'timeout', 'parse', 'normalization', 'validation', 'persistence', 'interrupted', 'unknown'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (discount_collector_runs_check_exists($pdo, 'discount_collector_schedules_status_check')) {
        $pdo->exec('ALTER TABLE discount_collector_schedules DROP CONSTRAINT discount_collector_schedules_status_check');
    }

    $pdo->exec(
        "ALTER TABLE discount_collector_schedules
         ADD CONSTRAINT discount_collector_schedules_status_check
         CHECK (last_status IN ('never', 'running', 'success', 'partial', 'failed', 'not_registered'))"
    );
};

function discount_collector_runs_check_exists(\PDO $pdo, string $constraint): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.check_constraints
         WHERE constraint_schema = DATABASE()
           AND constraint_name = :constraint_name'
    );
    $statement->execute(['constraint_name' => $constraint]);

    return (int) $statement->fetchColumn() > 0;
}
