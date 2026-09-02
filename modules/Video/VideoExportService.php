<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoExportService
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoEditorService $editor,
        private readonly VideoExportJobRepository $jobs,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, int $videoId): array
    {
        $video = $this->editor->editableVideo($userId, $videoId);

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        return $this->jobResources($this->jobs->listForVideoForUser($userId, $videoId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProcessed(int $userId, int $limit = 100): array
    {
        return $this->jobResources($this->jobs->listForUser($userId, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJob(int $userId, int $jobId): ?array
    {
        $job = $this->jobs->findForUser($userId, $this->positiveId($jobId, 'export_job_id'));

        return $job === null ? null : $this->jobResource($job);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, int $videoId, mixed $name = null): array
    {
        $video = $this->editor->editableVideo($userId, $videoId);

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        $sequence = $this->editor->getExportSequence($videoId, $userId);
        $duration = $this->estimatedDuration($sequence, (float) $video['duration_seconds']);

        if ($sequence === [] || $duration <= 0.0) {
            throw new VideoValidationException('No existen segmentos incluidos para exportar.');
        }

        $displayName = $this->displayName($name, (string) ($video['original_name'] ?? 'Video editado'));
        $storedName = 'export_' . bin2hex(random_bytes(16)) . '.mp4';
        $jobId = $this->jobs->createWithSegments($userId, (int) $video['id'], $displayName, $storedName, $duration, $sequence);
        $job = $this->jobs->findForUser($userId, $jobId);

        return $this->jobResource($job ?? []);
    }

    public function delete(int $userId, int $jobId): bool
    {
        $job = $this->jobs->findForUser($userId, $this->positiveId($jobId, 'export_job_id'));

        if ($job === null) {
            return false;
        }

        if ((string) ($job['status'] ?? '') === 'processing') {
            throw new VideoValidationException('No se puede eliminar una exportacion en proceso.');
        }

        $storage = $this->storage ?? new VideoStorage([]);
        $path = $storage->pathForExportJob($job);

        if ($path !== null && is_file($path) && !unlink($path)) {
            throw new VideoValidationException('No se pudo eliminar el archivo exportado.');
        }

        return $this->jobs->deleteForUser($userId, (int) $job['id']);
    }

    public function safeDownloadName(array $job): string
    {
        $name = (string) ($job['output_name'] ?? 'video_editado');
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._-');

        if ($name === '') {
            $name = 'video_editado';
        }

        if (!str_ends_with(strtolower($name), '.mp4')) {
            $name .= '.mp4';
        }

        return substr($name, 0, 180);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function isExpired(array $job): bool
    {
        if ((string) ($job['status'] ?? '') !== 'completed') {
            return false;
        }

        $expiresAt = $job['expires_at'] ?? null;

        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        return strtotime($expiresAt . ' UTC') !== false && (int) strtotime($expiresAt . ' UTC') <= time();
    }

    /**
     * @param array<int, array{source_start_seconds: float, source_end_seconds: float}> $sequence
     */
    private function estimatedDuration(array $sequence, float $videoDuration): float
    {
        $duration = 0.0;

        foreach ($sequence as $segment) {
            $start = round((float) $segment['source_start_seconds'], 3);
            $end = round((float) $segment['source_end_seconds'], 3);

            if ($start < 0.0 || $end <= $start || $end > $videoDuration + 0.001) {
                throw new VideoValidationException('La secuencia de exportacion es invalida.');
            }

            $duration += $end - $start;
        }

        return round($duration, 3);
    }

    private function displayName(mixed $name, string $fallback): string
    {
        $raw = is_string($name) ? trim((string) preg_replace('/\s+/', ' ', $name)) : '';

        if ($raw === '') {
            $raw = preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $fallback) ?: 'Video editado';
        }

        return substr($raw, 0, 180);
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function jobResources(array $jobs): array
    {
        return array_map(fn (array $job): array => $this->jobResource($job), $jobs);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function jobResource(array $job): array
    {
        $status = (string) ($job['status'] ?? '');
        $isExpired = $this->isExpired($job);
        $isDownloadable = $status === 'completed'
            && !$isExpired
            && (($this->storage ?? new VideoStorage([]))->pathForExportJob($job) !== null);

        return [
            'id' => (int) ($job['id'] ?? 0),
            'video_id' => (int) ($job['video_id'] ?? 0),
            'status' => $status,
            'display_status' => $isExpired ? 'expired' : $status,
            'is_expired' => $isExpired,
            'is_downloadable' => $isDownloadable,
            'output_name' => (string) ($job['output_name'] ?? ''),
            'video_original_name' => (string) ($job['video_original_name'] ?? ''),
            'estimated_duration_seconds' => round((float) ($job['estimated_duration_seconds'] ?? 0), 3),
            'output_size_bytes' => ($job['output_size_bytes'] ?? null) === null ? null : (int) $job['output_size_bytes'],
            'error_message' => ($job['error_message'] ?? null) === null ? null : (string) $job['error_message'],
            'progress_percent' => round((float) ($job['progress_percent'] ?? 0), 2),
            'processed_seconds' => ($job['processed_seconds'] ?? null) === null ? null : round((float) $job['processed_seconds'], 3),
            'speed' => ($job['speed'] ?? null) === null ? null : (string) $job['speed'],
            'output_duration_seconds' => ($job['output_duration_seconds'] ?? null) === null ? null : round((float) $job['output_duration_seconds'], 3),
            'expires_at' => ($job['expires_at'] ?? null) === null ? null : (string) $job['expires_at'],
            'created_at' => (string) ($job['created_at'] ?? ''),
            'started_at' => ($job['started_at'] ?? null) === null ? null : (string) $job['started_at'],
            'completed_at' => ($job['completed_at'] ?? null) === null ? null : (string) $job['completed_at'],
        ];
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new VideoValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }
}
