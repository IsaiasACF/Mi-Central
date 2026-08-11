<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoTranscriptionRepository
{
    private const COLUMNS = 'vt.id, vt.user_id, vt.video_id, vt.export_job_id, vt.source_type, vt.source_video_id, vt.source_display_name, vt.status, vt.requested_language, vt.detected_language, vt.model, vt.progress_percent, vt.full_text, vt.error_message, vt.created_at, vt.started_at, vt.completed_at, vt.updated_at';

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
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM video_transcription_segments vts WHERE vts.transcription_id = vt.id) AS segments_count
             FROM video_transcriptions vt
             WHERE vt.user_id = :user_id
               AND vt.source_video_id = :video_id
             ORDER BY vt.created_at DESC, vt.id DESC'
        );
        $statement->execute([
            'user_id' => $userId,
            'video_id' => $videoId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, int $transcriptionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM video_transcription_segments vts WHERE vts.transcription_id = vt.id) AS segments_count
             FROM video_transcriptions vt
             WHERE vt.user_id = :user_id AND vt.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $transcriptionId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForExportForUser(int $userId, int $transcriptionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ',
                    COALESCE(vt.source_display_name, vf.original_name) AS video_original_name,
                    vf.user_id AS video_owner_id,
                    (SELECT COUNT(*) FROM video_transcription_segments vts WHERE vts.transcription_id = vt.id) AS segments_count
             FROM video_transcriptions vt
             INNER JOIN video_files vf ON vf.id = vt.source_video_id AND vf.user_id = :video_user_id
             WHERE vt.id = :id
               AND vt.user_id = :transcription_user_id
             LIMIT 1'
        );
        $statement->execute([
            'video_user_id' => $userId,
            'transcription_user_id' => $userId,
            'id' => $transcriptionId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $transcriptionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM video_transcriptions vt
             WHERE vt.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $transcriptionId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createForVideo(int $userId, int $videoId, string $displayName, string $requestedLanguage, string $model): int
    {
        return $this->create($userId, $videoId, null, 'video', $videoId, $displayName, $requestedLanguage, $model);
    }

    public function createForExport(int $userId, int $exportJobId, int $sourceVideoId, string $displayName, string $requestedLanguage, string $model): int
    {
        return $this->create($userId, null, $exportJobId, 'export', $sourceVideoId, $displayName, $requestedLanguage, $model);
    }

    private function create(int $userId, ?int $videoId, ?int $exportJobId, string $sourceType, int $sourceVideoId, string $displayName, string $requestedLanguage, string $model): int
    {
        return $this->transaction(function () use ($userId, $videoId, $exportJobId, $sourceType, $sourceVideoId, $displayName, $requestedLanguage, $model): int {
            if (
                ($sourceType === 'video' && ($videoId === null || $exportJobId !== null || $sourceVideoId !== $videoId))
                || ($sourceType === 'export' && ($videoId !== null || $exportJobId === null || $sourceVideoId <= 0))
                || !in_array($sourceType, ['video', 'export'], true)
            ) {
                throw new VideoValidationException('Fuente de transcripcion invalida.');
            }

            if ($this->activeForSourceExists($userId, $sourceType, $videoId, $exportJobId)) {
                throw new VideoValidationException('Este video ya se esta transcribiendo.');
            }

            $statement = $this->pdo->prepare(
                "INSERT INTO video_transcriptions (
                    user_id,
                    video_id,
                    export_job_id,
                    source_type,
                    source_video_id,
                    source_display_name,
                    status,
                    requested_language,
                    model,
                    progress_percent
                ) VALUES (
                    :user_id,
                    :video_id,
                    :export_job_id,
                    :source_type,
                    :source_video_id,
                    :source_display_name,
                    'pending',
                    :requested_language,
                    :model,
                    0
                )"
            );
            $statement->execute([
                'user_id' => $userId,
                'video_id' => $videoId,
                'export_job_id' => $exportJobId,
                'source_type' => $sourceType,
                'source_video_id' => $sourceVideoId,
                'source_display_name' => substr($displayName, 0, 255),
                'requested_language' => $requestedLanguage,
                'model' => substr($model, 0, 64),
            ]);

            return (int) $this->pdo->lastInsertId();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reserveNextPending(): ?array
    {
        return $this->transaction(function (): ?array {
            $active = $this->pdo->query("SELECT id FROM video_transcriptions WHERE status = 'processing' LIMIT 1 FOR UPDATE");

            if ($active !== false && $active->fetchColumn() !== false) {
                return null;
            }

            $statement = $this->pdo->query(
                "SELECT id
                 FROM video_transcriptions
                 WHERE status = 'pending'
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $id = $statement === false ? false : $statement->fetchColumn();

            if ($id === false) {
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE video_transcriptions
                 SET status = 'processing',
                     started_at = UTC_TIMESTAMP(),
                     progress_percent = 1,
                     error_message = NULL
                 WHERE id = :id AND status = 'pending'"
            );
            $update->execute(['id' => (int) $id]);

            if ($update->rowCount() !== 1) {
                return null;
            }

            return $this->findById((int) $id);
        });
    }

    public function updateProgress(int $transcriptionId, float $percent): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_transcriptions
             SET progress_percent = :progress_percent
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'progress_percent' => number_format($this->clampPercent($percent), 2, '.', ''),
            'id' => $transcriptionId,
        ]);
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    public function markCompleted(int $transcriptionId, ?string $detectedLanguage, string $model, string $fullText, array $segments): void
    {
        $this->transaction(function () use ($transcriptionId, $detectedLanguage, $model, $fullText, $segments): void {
            $this->pdo->prepare('DELETE FROM video_transcription_segments WHERE transcription_id = :id')->execute(['id' => $transcriptionId]);
            $segmentStatement = $this->pdo->prepare(
                'INSERT INTO video_transcription_segments (
                    transcription_id,
                    segment_index,
                    start_seconds,
                    end_seconds,
                    text
                ) VALUES (
                    :transcription_id,
                    :segment_index,
                    :start_seconds,
                    :end_seconds,
                    :text
                )'
            );

            foreach (array_values($segments) as $index => $segment) {
                $segmentStatement->execute([
                    'transcription_id' => $transcriptionId,
                    'segment_index' => $index,
                    'start_seconds' => number_format($segment['start_seconds'], 3, '.', ''),
                    'end_seconds' => number_format($segment['end_seconds'], 3, '.', ''),
                    'text' => $segment['text'],
                ]);
            }

            $update = $this->pdo->prepare(
                "UPDATE video_transcriptions
                 SET status = 'completed',
                     detected_language = :detected_language,
                     model = :model,
                     progress_percent = 100,
                     full_text = :full_text,
                     error_message = NULL,
                     completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = 'processing'"
            );
            $update->execute([
                'detected_language' => $detectedLanguage,
                'model' => substr($model, 0, 64),
                'full_text' => $fullText,
                'id' => $transcriptionId,
            ]);
        });
    }

    public function markFailed(int $transcriptionId, string $errorMessage): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_transcriptions
             SET status = 'failed',
                 error_message = :error_message,
                 completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'error_message' => substr($errorMessage, 0, 500),
            'id' => $transcriptionId,
        ]);
    }

    public function deleteForUser(int $userId, int $transcriptionId): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM video_transcriptions
             WHERE id = :id AND user_id = :user_id AND status <> 'processing'"
        );
        $statement->execute([
            'id' => $transcriptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array<int, int>
     */
    public function processingJobIds(): array
    {
        $statement = $this->pdo->query("SELECT id FROM video_transcriptions WHERE status = 'processing'");

        if ($statement === false) {
            return [];
        }

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSegments(int $transcriptionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, transcription_id, segment_index, start_seconds, end_seconds, text, created_at
             FROM video_transcription_segments
             WHERE transcription_id = :transcription_id
             ORDER BY segment_index ASC'
        );
        $statement->execute(['transcription_id' => $transcriptionId]);

        return $statement->fetchAll();
    }

    private function activeForSourceExists(int $userId, string $sourceType, ?int $videoId, ?int $exportJobId): bool
    {
        if ($sourceType === 'video' && $videoId !== null) {
            $statement = $this->pdo->prepare(
                "SELECT id
                 FROM video_transcriptions
                 WHERE user_id = :user_id
                   AND source_type = 'video'
                   AND video_id = :video_id
                   AND status IN ('pending', 'processing')
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute([
                'user_id' => $userId,
                'video_id' => $videoId,
            ]);

            return $statement->fetchColumn() !== false;
        }

        if ($sourceType === 'export' && $exportJobId !== null) {
            $statement = $this->pdo->prepare(
                "SELECT id
                 FROM video_transcriptions
                 WHERE user_id = :user_id
                   AND source_type = 'export'
                   AND export_job_id = :export_job_id
                   AND status IN ('pending', 'processing')
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute([
                'user_id' => $userId,
                'export_job_id' => $exportJobId,
            ]);

            return $statement->fetchColumn() !== false;
        }

        throw new VideoValidationException('Fuente de transcripcion invalida.');
    }

    private function activeForVideoExists(int $userId, int $videoId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id
             FROM video_transcriptions
             WHERE user_id = :user_id
               AND video_id = :video_id
               AND status IN ('pending', 'processing')
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute([
            'user_id' => $userId,
            'video_id' => $videoId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function clampPercent(float $percent): float
    {
        if (!is_finite($percent)) {
            return 0.0;
        }

        return max(0.0, min(100.0, round($percent, 2)));
    }
}
