<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoTranscriptionService
{
    private const LANGUAGE_LABELS = [
        'auto' => 'Detectar automaticamente',
        'es' => 'Espanol',
        'en' => 'Ingles',
    ];

    public function __construct(
        private readonly VideoTranscriptionRepository $transcriptions,
        private readonly TranscriptionSourceResolver $sources,
        private readonly WhisperService $whisper,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, int $videoId): array
    {
        $this->sources->parentVideoForUser($userId, $this->positiveId($videoId, 'video_id'));

        return array_map(fn (array $row): array => $this->resource($row), $this->transcriptions->listForVideoForUser($userId, $videoId));
    }

    /**
     * @return array<string, mixed>
     */
    public function createForVideo(int $userId, int $videoId, mixed $language): array
    {
        $source = $this->sources->videoForUser($userId, $this->positiveId($videoId, 'video_id'));
        $requestedLanguage = $this->normalizeLanguage($language);
        $id = $this->transcriptions->createForVideo($userId, (int) $source['source_id'], $source['display_name'], $requestedLanguage, $this->whisper->configuredModelName());
        $row = $this->transcriptions->findForUser($userId, $id);

        return $this->resource($row ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function createForExport(int $userId, int $exportJobId, mixed $language): array
    {
        $source = $this->sources->exportForUser($userId, $this->positiveId($exportJobId, 'export_job_id'));
        $requestedLanguage = $this->normalizeLanguage($language);
        $id = $this->transcriptions->createForExport($userId, (int) $source['source_id'], (int) $source['source_video_id'], $source['display_name'], $requestedLanguage, $this->whisper->configuredModelName());
        $row = $this->transcriptions->findForUser($userId, $id);

        return $this->resource($row ?? []);
    }

    public function delete(int $userId, int $transcriptionId): bool
    {
        $row = $this->transcriptions->findForUser($userId, $this->positiveId($transcriptionId, 'transcription_id'));

        if ($row === null) {
            return false;
        }

        if ((string) ($row['status'] ?? '') === 'processing') {
            throw new VideoValidationException('No se puede eliminar una transcripcion en proceso.');
        }

        return $this->transcriptions->deleteForUser($userId, $transcriptionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function modelResource(): array
    {
        return [
            'model' => $this->whisper->configuredModelName(),
        ];
    }

    private function normalizeLanguage(mixed $language): string
    {
        $value = is_string($language) ? strtolower(trim($language)) : 'auto';

        if (!array_key_exists($value, self::LANGUAGE_LABELS)) {
            throw new VideoValidationException('Idioma de transcripcion invalido.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        $language = (string) ($row['requested_language'] ?? 'auto');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'video_id' => (int) ($row['video_id'] ?? 0),
            'export_job_id' => ($row['export_job_id'] ?? null) === null ? null : (int) $row['export_job_id'],
            'source_type' => (string) ($row['source_type'] ?? 'video'),
            'source_id' => (string) ($row['source_type'] ?? 'video') === 'export' ? (($row['export_job_id'] ?? null) === null ? null : (int) $row['export_job_id']) : (int) ($row['video_id'] ?? 0),
            'source_video_id' => (int) ($row['source_video_id'] ?? ($row['video_id'] ?? 0)),
            'source_display_name' => (string) ($row['source_display_name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'requested_language' => $language,
            'requested_language_label' => self::LANGUAGE_LABELS[$language] ?? 'Detectar automaticamente',
            'detected_language' => ($row['detected_language'] ?? null) === null ? null : (string) $row['detected_language'],
            'model' => (string) ($row['model'] ?? ''),
            'progress_percent' => round((float) ($row['progress_percent'] ?? 0), 2),
            'segments_count' => (int) ($row['segments_count'] ?? 0),
            'error_message' => ($row['error_message'] ?? null) === null ? null : 'No se pudo completar la transcripcion.',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => ($row['started_at'] ?? null) === null ? null : (string) $row['started_at'],
            'completed_at' => ($row['completed_at'] ?? null) === null ? null : (string) $row['completed_at'],
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
