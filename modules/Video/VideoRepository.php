<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoRepository
{
    private const SELECT_COLUMNS = 'id, user_id, original_name, stored_name, storage_path, extension, mime_type, size_bytes, status, duration_seconds, width, height, fps, video_codec, audio_codec, container_format, bitrate, metadata_status, metadata_error, analyzed_at, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO video_files (
                user_id,
                original_name,
                stored_name,
                storage_path,
                extension,
                mime_type,
                size_bytes,
                status,
                metadata_status
            ) VALUES (
                :user_id,
                :original_name,
                :stored_name,
                :storage_path,
                :extension,
                :mime_type,
                :size_bytes,
                :status,
                :metadata_status
            )'
        );
        $statement->execute([
            'user_id' => $userId,
            'original_name' => $data['original_name'],
            'stored_name' => $data['stored_name'],
            'storage_path' => $data['storage_path'],
            'extension' => $data['extension'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => $data['size_bytes'],
            'status' => $data['status'],
            'metadata_status' => $data['metadata_status'] ?? 'pending',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_files
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $userId, int $videoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM video_files
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $videoId,
            'user_id' => $userId,
        ]);
        $video = $statement->fetch();

        return is_array($video) ? $video : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reservePendingMetadata(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->query(
                'SELECT id
                 FROM video_files
                 WHERE metadata_status = ' . $this->pdo->quote('pending') . '
                 ORDER BY created_at ASC, id ASC
                 LIMIT ' . $limit . '
                 FOR UPDATE SKIP LOCKED'
            );
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->pdo->prepare(
                "UPDATE video_files
                 SET metadata_status = 'processing', metadata_error = NULL
                 WHERE metadata_status = 'pending' AND id IN ({$placeholders})"
            );
            $update->execute($ids);
            $this->pdo->commit();

            return $this->findByIds($ids);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . "
             FROM video_files
             WHERE id IN ({$placeholders})
             ORDER BY created_at ASC, id ASC"
        );
        $statement->execute($ids);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markMetadataReady(int $videoId, array $metadata): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_files
             SET duration_seconds = :duration_seconds,
                 width = :width,
                 height = :height,
                 fps = :fps,
                 video_codec = :video_codec,
                 audio_codec = :audio_codec,
                 container_format = :container_format,
                 bitrate = :bitrate,
                 metadata_status = 'ready',
                 metadata_error = NULL,
                 analyzed_at = UTC_TIMESTAMP()
             WHERE id = :id AND metadata_status = 'processing'"
        );
        $statement->execute([
            'duration_seconds' => $metadata['duration_seconds'],
            'width' => $metadata['width'],
            'height' => $metadata['height'],
            'fps' => $metadata['fps'],
            'video_codec' => $metadata['video_codec'],
            'audio_codec' => $metadata['audio_codec'],
            'container_format' => $metadata['container_format'],
            'bitrate' => $metadata['bitrate'],
            'id' => $videoId,
        ]);
    }

    public function markMetadataFailed(int $videoId, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_files
             SET metadata_status = 'failed',
                 metadata_error = :metadata_error,
                 analyzed_at = UTC_TIMESTAMP()
             WHERE id = :id AND metadata_status = 'processing'"
        );
        $statement->execute([
            'metadata_error' => substr($error, 0, 500),
            'id' => $videoId,
        ]);
    }

    public function retryMetadata(int $userId, int $videoId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE video_files
             SET metadata_status = 'pending',
                 metadata_error = NULL,
                 analyzed_at = NULL
             WHERE id = :id AND user_id = :user_id AND metadata_status = 'failed'"
        );
        $statement->execute([
            'id' => $videoId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function delete(int $userId, int $videoId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM video_files WHERE id = :id AND user_id = :user_id');
        $statement->execute([
            'id' => $videoId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
