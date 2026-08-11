<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    video_export_progress_add_column($pdo, 'progress_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
    video_export_progress_add_column($pdo, 'processed_seconds', 'DECIMAL(12,3) NULL');
    video_export_progress_add_column($pdo, 'speed', 'VARCHAR(32) NULL');
    video_export_progress_add_column($pdo, 'output_duration_seconds', 'DECIMAL(12,3) NULL');
    video_export_progress_add_column($pdo, 'expires_at', 'TIMESTAMP NULL');

    if (!video_export_progress_index_exists($pdo, 'video_export_jobs_expires_index')) {
        $pdo->exec('CREATE INDEX video_export_jobs_expires_index ON video_export_jobs (status, expires_at)');
    }
};

function video_export_progress_add_column(\PDO $pdo, string $column, string $definition): void
{
    if (video_export_progress_column_exists($pdo, $column)) {
        return;
    }

    $pdo->exec("ALTER TABLE video_export_jobs ADD COLUMN {$column} {$definition}");
}

function video_export_progress_column_exists(\PDO $pdo, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute([
        'table' => 'video_export_jobs',
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function video_export_progress_index_exists(\PDO $pdo, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index_name'
    );
    $statement->execute([
        'table' => 'video_export_jobs',
        'index_name' => $index,
    ]);

    return (int) $statement->fetchColumn() > 0;
}
