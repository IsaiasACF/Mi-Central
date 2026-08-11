<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class TranscriptionSourceResolver
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoExportJobRepository $exports,
        private readonly VideoStorage $storage,
    ) {
    }

    /**
     * @return array{source_type: string, source_id: int, source_video_id: int, owner_id: int, display_name: string, absolute_path: string}
     */
    public function videoForUser(int $userId, int $videoId): array
    {
        $video = $this->videos->findByIdForUser($userId, $this->positiveId($videoId));

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        if (($video['metadata_status'] ?? '') !== 'ready') {
            throw new VideoValidationException('El video debe estar analizado antes de transcribir.');
        }

        if (!is_string($video['audio_codec'] ?? null) || (string) $video['audio_codec'] === '') {
            throw new VideoValidationException('Este video no tiene una pista de audio disponible.');
        }

        $path = $this->storage->pathForStoredVideo($video);

        if ($path === null) {
            throw new VideoValidationException('Archivo original no disponible.');
        }

        return [
            'source_type' => 'video',
            'source_id' => (int) $video['id'],
            'source_video_id' => (int) $video['id'],
            'owner_id' => (int) $video['user_id'],
            'display_name' => (string) $video['original_name'],
            'absolute_path' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parentVideoForUser(int $userId, int $videoId): array
    {
        $video = $this->videos->findByIdForUser($userId, $this->positiveId($videoId));

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        return $video;
    }

    /**
     * @return array{source_type: string, source_id: int, source_video_id: int, owner_id: int, display_name: string, absolute_path: string}
     */
    public function exportForUser(int $userId, int $exportJobId): array
    {
        $job = $this->exports->findForUser($userId, $this->positiveId($exportJobId));

        if ($job === null) {
            throw new VideoValidationException('Exportacion no encontrada.');
        }

        if ((string) ($job['status'] ?? '') !== 'completed') {
            throw new VideoValidationException('Solo una exportacion completada puede transcribirse.');
        }

        $path = $this->storage->pathForExportJob($job);

        if ($path === null) {
            throw new VideoValidationException('Archivo exportado no disponible.');
        }

        return [
            'source_type' => 'export',
            'source_id' => (int) $job['id'],
            'source_video_id' => (int) $job['video_id'],
            'owner_id' => (int) $job['user_id'],
            'display_name' => (string) ($job['output_name'] ?? 'Exportacion'),
            'absolute_path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array{source_type: string, source_id: int, source_video_id: int, owner_id: int, display_name: string, absolute_path: string}
     */
    public function forTranscriptionJob(array $job): array
    {
        $sourceType = (string) ($job['source_type'] ?? 'video');
        $userId = (int) ($job['user_id'] ?? 0);

        if ($sourceType === 'video') {
            return $this->videoForUser($userId, (int) ($job['video_id'] ?? 0));
        }

        if ($sourceType === 'export') {
            $exportJobId = (int) ($job['export_job_id'] ?? 0);

            if ($exportJobId <= 0) {
                throw new RuntimeException('La exportacion fuente ya no esta disponible.');
            }

            return $this->exportForUser($userId, $exportJobId);
        }

        throw new RuntimeException('Fuente de transcripcion invalida.');
    }

    private function positiveId(int $id): int
    {
        if ($id <= 0) {
            throw new VideoValidationException('Identificador de fuente invalido.');
        }

        return $id;
    }
}
