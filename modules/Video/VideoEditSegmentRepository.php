<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoEditSegmentRepository
{
    private const SELECT_COLUMNS = 'ves.id, ves.video_id, ves.source_start_seconds, ves.source_end_seconds, ves.sort_order, ves.is_included, ves.created_at, ves.updated_at';

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
    public function listForVideo(int $videoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_edit_segments ves
             WHERE ves.video_id = :video_id
             ORDER BY ves.source_start_seconds ASC, ves.source_end_seconds ASC, ves.id ASC'
        );
        $statement->execute(['video_id' => $videoId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForVideoForUser(int $userId, int $videoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_edit_segments ves
             INNER JOIN video_files vf ON vf.id = ves.video_id
             WHERE vf.user_id = :user_id AND ves.video_id = :video_id
             ORDER BY
                CASE WHEN ves.is_included = 1 THEN 0 ELSE 1 END ASC,
                CASE WHEN ves.is_included = 1 THEN ves.sort_order ELSE ves.source_start_seconds END ASC,
                ves.source_start_seconds ASC,
                ves.id ASC'
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
    public function findForUser(int $userId, int $segmentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_edit_segments ves
             INNER JOIN video_files vf ON vf.id = ves.video_id
             WHERE vf.user_id = :user_id AND ves.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $segmentId,
        ]);
        $segment = $statement->fetch();

        return is_array($segment) ? $segment : null;
    }

    public function create(int $videoId, float $startSeconds, float $endSeconds, int $sortOrder, bool $isIncluded): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO video_edit_segments (
                video_id,
                source_start_seconds,
                source_end_seconds,
                sort_order,
                is_included
             ) VALUES (
                :video_id,
                :source_start_seconds,
                :source_end_seconds,
                :sort_order,
                :is_included
             )'
        );
        $statement->execute([
            'video_id' => $videoId,
            'source_start_seconds' => number_format($startSeconds, 3, '.', ''),
            'source_end_seconds' => number_format($endSeconds, 3, '.', ''),
            'sort_order' => $sortOrder,
            'is_included' => $isIncluded ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $segmentId, float $startSeconds, float $endSeconds, int $sortOrder, bool $isIncluded): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE video_edit_segments
             SET source_start_seconds = :source_start_seconds,
                 source_end_seconds = :source_end_seconds,
                 sort_order = :sort_order,
                 is_included = :is_included
             WHERE id = :id'
        );
        $statement->execute([
            'source_start_seconds' => number_format($startSeconds, 3, '.', ''),
            'source_end_seconds' => number_format($endSeconds, 3, '.', ''),
            'sort_order' => $sortOrder,
            'is_included' => $isIncluded ? 1 : 0,
            'id' => $segmentId,
        ]);
    }

    /**
     * @param array<int, int> $keepIds
     */
    public function deleteExcept(int $videoId, array $keepIds): void
    {
        $keepIds = array_values(array_filter(array_map('intval', $keepIds), static fn (int $id): bool => $id > 0));

        if ($keepIds === []) {
            $statement = $this->pdo->prepare('DELETE FROM video_edit_segments WHERE video_id = :video_id');
            $statement->execute(['video_id' => $videoId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $statement = $this->pdo->prepare("DELETE FROM video_edit_segments WHERE video_id = ? AND id NOT IN ({$placeholders})");
        $statement->execute(array_merge([$videoId], $keepIds));
    }

    public function setIncludedForUser(int $userId, int $segmentId, bool $isIncluded): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE video_edit_segments ves
             INNER JOIN video_files vf ON vf.id = ves.video_id
             SET ves.is_included = :is_included
             WHERE vf.user_id = :user_id AND ves.id = :id'
        );
        $statement->execute([
            'is_included' => $isIncluded ? 1 : 0,
            'user_id' => $userId,
            'id' => $segmentId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function updateSortOrderForUser(int $userId, int $segmentId, int $sortOrder): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE video_edit_segments ves
             INNER JOIN video_files vf ON vf.id = ves.video_id
             SET ves.sort_order = :sort_order
             WHERE vf.user_id = :user_id AND ves.id = :id'
        );
        $statement->execute([
            'sort_order' => $sortOrder,
            'user_id' => $userId,
            'id' => $segmentId,
        ]);

        return $statement->rowCount() === 1;
    }
}
