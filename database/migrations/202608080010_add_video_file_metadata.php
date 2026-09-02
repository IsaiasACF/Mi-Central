<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    video_metadata_add_column($pdo, 'duration_seconds', 'DECIMAL(12,3) NULL AFTER status');
    video_metadata_add_column($pdo, 'width', 'INT UNSIGNED NULL AFTER duration_seconds');
    video_metadata_add_column($pdo, 'height', 'INT UNSIGNED NULL AFTER width');
    video_metadata_add_column($pdo, 'fps', 'DECIMAL(8,3) NULL AFTER height');
    video_metadata_add_column($pdo, 'video_codec', 'VARCHAR(64) NULL AFTER fps');
    video_metadata_add_column($pdo, 'audio_codec', 'VARCHAR(64) NULL AFTER video_codec');
    video_metadata_add_column($pdo, 'container_format', 'VARCHAR(120) NULL AFTER audio_codec');
    video_metadata_add_column($pdo, 'bitrate', 'BIGINT UNSIGNED NULL AFTER container_format');
    video_metadata_add_column($pdo, 'metadata_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER bitrate");
    video_metadata_add_column($pdo, 'metadata_error', 'VARCHAR(500) NULL AFTER metadata_status');
    video_metadata_add_column($pdo, 'analyzed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER metadata_error');

    $pdo->exec("UPDATE video_files SET metadata_status = 'pending' WHERE metadata_status IS NULL OR metadata_status = ''");

    if (!video_metadata_index_exists($pdo, 'video_files_metadata_status_index')) {
        $pdo->exec('CREATE INDEX video_files_metadata_status_index ON video_files (metadata_status)');
    }

    if (!video_metadata_constraint_exists($pdo, 'video_files_metadata_status_check')) {
        $pdo->exec(
            "ALTER TABLE video_files
             ADD CONSTRAINT video_files_metadata_status_check
             CHECK (metadata_status IN ('pending', 'processing', 'ready', 'failed'))"
        );
    }
};

function video_metadata_add_column(\PDO $pdo, string $column, string $definition): void
{
    if (video_metadata_column_exists($pdo, $column)) {
        return;
    }

    $pdo->exec("ALTER TABLE video_files ADD COLUMN {$column} {$definition}");
}

function video_metadata_column_exists(\PDO $pdo, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $statement->execute([
        'table' => 'video_files',
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function video_metadata_index_exists(\PDO $pdo, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
    );
    $statement->execute([
        'table' => 'video_files',
        'index_name' => $index,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function video_metadata_constraint_exists(\PDO $pdo, string $constraint): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.check_constraints
         WHERE constraint_schema = DATABASE() AND constraint_name = :constraint_name'
    );
    $statement->execute(['constraint_name' => $constraint]);

    return (int) $statement->fetchColumn() > 0;
}
