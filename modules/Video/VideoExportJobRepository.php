<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoExportJobRepository
{
    private const JOB_COLUMNS = 'vej.id, vej.user_id, vej.video_id, vej.status, vej.output_name, vej.output_stored_name, vej.output_path, vej.estimated_duration_seconds, vej.output_size_bytes, vej.error_message, vej.progress_percent, vej.processed_seconds, vej.speed, vej.output_duration_seconds, vej.expires_at, vej.created_at, vej.started_at, vej.completed_at, vej.updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $started = !$this->pdo->inTransaction();

        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($started) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForVideoForUser(int $userId, int $videoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . '
             FROM video_export_jobs vej
             WHERE vej.user_id = :user_id AND vej.video_id = :video_id
             ORDER BY vej.created_at DESC, vej.id DESC'
        );
        $statement->execute([
            'user_id' => $userId,
            'video_id' => $videoId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . ',
                    vf.original_name AS video_original_name
             FROM video_export_jobs vej
             INNER JOIN video_files vf ON vf.id = vej.video_id AND vf.user_id = vej.user_id
             WHERE vej.user_id = :user_id
             ORDER BY vej.created_at DESC, vej.id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM video_export_jobs WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, int $jobId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . '
             FROM video_export_jobs vej
             WHERE vej.user_id = :user_id AND vej.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $jobId,
        ]);
        $job = $statement->fetch();

        return is_array($job) ? $job : null;
    }

    /**
     * @param array<int, array{source_start_seconds: float, source_end_seconds: float}> $sequence
     */
    public function createWithSegments(int $userId, int $videoId, string $outputName, string $outputStoredName, float $estimatedDuration, array $sequence): int
    {
        return $this->transaction(function () use ($userId, $videoId, $outputName, $outputStoredName, $estimatedDuration, $sequence): int {
            $statement = $this->pdo->prepare(
                "INSERT INTO video_export_jobs (
                    user_id,
                    video_id,
                    status,
                    output_name,
                    output_stored_name,
                    estimated_duration_seconds,
                    progress_percent
                ) VALUES (
                    :user_id,
                    :video_id,
                    'pending',
                    :output_name,
                    :output_stored_name,
                    :estimated_duration_seconds,
                    0
                )"
            );
            $statement->execute([
                'user_id' => $userId,
                'video_id' => $videoId,
                'output_name' => $outputName,
                'output_stored_name' => $outputStoredName,
                'estimated_duration_seconds' => number_format($estimatedDuration, 3, '.', ''),
            ]);
            $jobId = (int) $this->pdo->lastInsertId();
            $segmentStatement = $this->pdo->prepare(
                'INSERT INTO video_export_segments (
                    export_job_id,
                    source_start_seconds,
                    source_end_seconds,
                    sort_order
                ) VALUES (
                    :export_job_id,
                    :source_start_seconds,
                    :source_end_seconds,
                    :sort_order
                )'
            );

            foreach (array_values($sequence) as $index => $segment) {
                $segmentStatement->execute([
                    'export_job_id' => $jobId,
                    'source_start_seconds' => number_format((float) $segment['source_start_seconds'], 3, '.', ''),
                    'source_end_seconds' => number_format((float) $segment['source_end_seconds'], 3, '.', ''),
                    'sort_order' => $index + 1,
                ]);
            }

            return $jobId;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reserveNextPending(): ?array
    {
        return $this->transaction(function (): ?array {
            $active = $this->pdo->query("SELECT id FROM video_export_jobs WHERE status = 'processing' LIMIT 1 FOR UPDATE");

            if ($active !== false && $active->fetchColumn() !== false) {
                return null;
            }

            $statement = $this->pdo->query(
                "SELECT id
                 FROM video_export_jobs
                 WHERE status = 'pending'
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $jobId = $statement === false ? false : $statement->fetchColumn();

            if ($jobId === false) {
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE video_export_jobs
                 SET status = 'processing',
                     started_at = UTC_TIMESTAMP(),
                     progress_percent = 0,
                     processed_seconds = NULL,
                     speed = NULL,
                     error_message = NULL
                 WHERE id = :id AND status = 'pending'"
            );
            $update->execute(['id' => (int) $jobId]);

            if ($update->rowCount() !== 1) {
                return null;
            }

            return $this->findById((int) $jobId);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $jobId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . '
             FROM video_export_jobs vej
             WHERE vej.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $jobId]);
        $job = $statement->fetch();

        return is_array($job) ? $job : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSnapshotSegments(int $jobId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, export_job_id, source_start_seconds, source_end_seconds, sort_order, created_at
             FROM video_export_segments
             WHERE export_job_id = :export_job_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['export_job_id' => $jobId]);

        return $statement->fetchAll();
    }

    public function updateProgress(int $jobId, float $percent, ?float $processedSeconds, ?string $speed): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_export_jobs
             SET progress_percent = :progress_percent,
                 processed_seconds = :processed_seconds,
                 speed = :speed
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'progress_percent' => number_format($this->clampPercent($percent), 2, '.', ''),
            'processed_seconds' => $processedSeconds === null ? null : number_format(max(0.0, $processedSeconds), 3, '.', ''),
            'speed' => $speed === null ? null : substr($speed, 0, 32),
            'id' => $jobId,
        ]);
    }

    public function markCompleted(int $jobId, string $outputPath, int $outputSizeBytes, float $outputDurationSeconds, int $retentionDays): void
    {
        $retentionDays = max(1, min(3650, $retentionDays));
        $statement = $this->pdo->prepare(
            "UPDATE video_export_jobs
             SET status = 'completed',
                 output_path = :output_path,
                 output_size_bytes = :output_size_bytes,
                 progress_percent = 100,
                 processed_seconds = estimated_duration_seconds,
                 output_duration_seconds = :output_duration_seconds,
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$retentionDays} DAY),
                 error_message = NULL,
                 completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'output_path' => $outputPath,
            'output_size_bytes' => $outputSizeBytes,
            'output_duration_seconds' => number_format($outputDurationSeconds, 3, '.', ''),
            'id' => $jobId,
        ]);
    }

    public function markFailed(int $jobId, string $errorMessage): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_export_jobs
             SET status = 'failed',
                 error_message = :error_message,
                 completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'error_message' => substr($errorMessage, 0, 500),
            'id' => $jobId,
        ]);
    }

    public function deleteForUser(int $userId, int $jobId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM video_export_jobs WHERE id = :id AND user_id = :user_id');
        $statement->execute([
            'id' => $jobId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteById(int $jobId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM video_export_jobs WHERE id = :id');
        $statement->execute(['id' => $jobId]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array<int, int>
     */
    public function processingJobIds(): array
    {
        $statement = $this->pdo->query("SELECT id FROM video_export_jobs WHERE status = 'processing'");

        if ($statement === false) {
            return [];
        }

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function expiredCompletedJobs(): array
    {
        $statement = $this->pdo->query(
            "SELECT " . self::JOB_COLUMNS . "
             FROM video_export_jobs vej
             WHERE vej.status = 'completed'
               AND vej.expires_at IS NOT NULL
               AND vej.expires_at <= UTC_TIMESTAMP()
             ORDER BY vej.expires_at ASC, vej.id ASC"
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    private function clampPercent(float $percent): float
    {
        if (!is_finite($percent)) {
            return 0.0;
        }

        return max(0.0, min(100.0, round($percent, 2)));
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
