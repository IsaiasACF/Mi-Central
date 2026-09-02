<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoCutPointRepository
{
    private const SELECT_COLUMNS = 'vcp.id, vcp.video_id, vcp.position_seconds, vcp.created_at, vcp.updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForVideoForUser(int $userId, int $videoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_cut_points vcp
             INNER JOIN video_files vf ON vf.id = vcp.video_id
             WHERE vf.user_id = :user_id AND vcp.video_id = :video_id
             ORDER BY vcp.position_seconds ASC, vcp.id ASC'
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
    public function listForVideo(int $videoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_cut_points vcp
             WHERE vcp.video_id = :video_id
             ORDER BY vcp.position_seconds ASC, vcp.id ASC'
        );
        $statement->execute(['video_id' => $videoId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, int $cutPointId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_cut_points vcp
             INNER JOIN video_files vf ON vf.id = vcp.video_id
             WHERE vf.user_id = :user_id AND vcp.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $cutPointId,
        ]);
        $cutPoint = $statement->fetch();

        return is_array($cutPoint) ? $cutPoint : null;
    }

    public function create(int $videoId, float $positionSeconds): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO video_cut_points (video_id, position_seconds)
             VALUES (:video_id, :position_seconds)'
        );
        $statement->execute([
            'video_id' => $videoId,
            'position_seconds' => number_format($positionSeconds, 3, '.', ''),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateForUser(int $userId, int $cutPointId, float $positionSeconds): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE video_cut_points vcp
             INNER JOIN video_files vf ON vf.id = vcp.video_id
             SET vcp.position_seconds = :position_seconds
             WHERE vf.user_id = :user_id AND vcp.id = :id'
        );
        $statement->execute([
            'position_seconds' => number_format($positionSeconds, 3, '.', ''),
            'user_id' => $userId,
            'id' => $cutPointId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteForUser(int $userId, int $cutPointId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE vcp
             FROM video_cut_points vcp
             INNER JOIN video_files vf ON vf.id = vcp.video_id
             WHERE vf.user_id = :user_id AND vcp.id = :id'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $cutPointId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function existsNearPosition(int $videoId, float $positionSeconds, float $toleranceSeconds, ?int $exceptCutPointId = null): bool
    {
        $sql = 'SELECT COUNT(*)
                FROM video_cut_points
                WHERE video_id = :video_id
                  AND ABS(position_seconds - :position_seconds) <= :tolerance';
        $params = [
            'video_id' => $videoId,
            'position_seconds' => number_format($positionSeconds, 3, '.', ''),
            'tolerance' => number_format($toleranceSeconds, 3, '.', ''),
        ];

        if ($exceptCutPointId !== null) {
            $sql .= ' AND id <> :except_id';
            $params['except_id'] = $exceptCutPointId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }
}
