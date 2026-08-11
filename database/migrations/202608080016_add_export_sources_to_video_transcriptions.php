<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $statement->execute([
            'table' => $table,
            'column' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    };
    $indexExists = static function (string $table, string $index) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
        );
        $statement->execute([
            'table' => $table,
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    };
    $constraintExists = static function (string $constraint) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = :table
               AND constraint_name = :constraint_name'
        );
        $statement->execute([
            'table' => 'video_transcriptions',
            'constraint_name' => $constraint,
        ]);

        return (int) $statement->fetchColumn() === 1;
    };
    $foreignKeyExists = static function (string $constraint) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = :table
               AND constraint_name = :constraint_name'
        );
        $statement->execute([
            'table' => 'video_transcriptions',
            'constraint_name' => $constraint,
        ]);

        return (int) $statement->fetchColumn() === 1;
    };

    if ($foreignKeyExists('video_transcriptions_video_fk')) {
        $pdo->exec('ALTER TABLE video_transcriptions DROP FOREIGN KEY video_transcriptions_video_fk');
    }

    if (!$columnExists('video_transcriptions', 'export_job_id')) {
        $pdo->exec('ALTER TABLE video_transcriptions ADD COLUMN export_job_id BIGINT UNSIGNED NULL AFTER video_id');
    }

    if (!$columnExists('video_transcriptions', 'source_type')) {
        $pdo->exec("ALTER TABLE video_transcriptions ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT 'video' AFTER export_job_id");
    }

    if (!$columnExists('video_transcriptions', 'source_video_id')) {
        $pdo->exec('ALTER TABLE video_transcriptions ADD COLUMN source_video_id BIGINT UNSIGNED NULL AFTER source_type');
        $pdo->exec('UPDATE video_transcriptions SET source_video_id = video_id WHERE source_video_id IS NULL');
    }

    if (!$columnExists('video_transcriptions', 'source_display_name')) {
        $pdo->exec('ALTER TABLE video_transcriptions ADD COLUMN source_display_name VARCHAR(255) NULL AFTER source_video_id');
    }

    $pdo->exec('ALTER TABLE video_transcriptions MODIFY video_id BIGINT UNSIGNED NULL');
    $pdo->exec("UPDATE video_transcriptions SET source_type = 'video' WHERE source_type = '' OR source_type IS NULL");
    $pdo->exec('UPDATE video_transcriptions SET source_video_id = video_id WHERE source_type = ' . $pdo->quote('video') . ' AND source_video_id IS NULL');

    if (!$indexExists('video_transcriptions', 'video_transcriptions_user_export_index')) {
        $pdo->exec('ALTER TABLE video_transcriptions ADD INDEX video_transcriptions_user_export_index (user_id, export_job_id, created_at)');
    }

    if (!$indexExists('video_transcriptions', 'video_transcriptions_source_video_index')) {
        $pdo->exec('ALTER TABLE video_transcriptions ADD INDEX video_transcriptions_source_video_index (user_id, source_video_id, created_at)');
    }

    if (!$foreignKeyExists('video_transcriptions_video_fk')) {
        $pdo->exec(
            'ALTER TABLE video_transcriptions
             ADD CONSTRAINT video_transcriptions_video_fk
             FOREIGN KEY (video_id) REFERENCES video_files (id)
             ON DELETE CASCADE'
        );
    }

    if (!$foreignKeyExists('video_transcriptions_source_video_fk')) {
        $pdo->exec(
            'ALTER TABLE video_transcriptions
             ADD CONSTRAINT video_transcriptions_source_video_fk
             FOREIGN KEY (source_video_id) REFERENCES video_files (id)
             ON DELETE CASCADE'
        );
    }

    if (!$foreignKeyExists('video_transcriptions_export_fk')) {
        $pdo->exec(
            'ALTER TABLE video_transcriptions
             ADD CONSTRAINT video_transcriptions_export_fk
             FOREIGN KEY (export_job_id) REFERENCES video_export_jobs (id)
             ON DELETE SET NULL'
        );
    }

    if (!$constraintExists('video_transcriptions_source_type_check')) {
        $pdo->exec(
            "ALTER TABLE video_transcriptions
             ADD CONSTRAINT video_transcriptions_source_type_check
             CHECK (source_type IN ('video', 'export'))"
        );
    }

    // The XOR source invariant is enforced in VideoTranscriptionRepository.
    // MariaDB rejects the nullable FK expression needed to also allow archived completed exports.
};
