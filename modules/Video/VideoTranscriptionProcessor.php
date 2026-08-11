<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class VideoTranscriptionProcessor
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TranscriptionSourceResolver $sources,
        private readonly VideoTranscriptionRepository $transcriptions,
        private readonly WhisperService $whisper,
        private readonly WhisperTranscriptParser $parser,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $job
     */
    public function process(array $job): void
    {
        $storage = $this->storage ?? new VideoStorage($this->config);
        $storage->ensureExportDirectories();
        $source = $this->sources->forTranscriptionJob($job);
        $inputPath = $source['absolute_path'];

        $jobId = (int) ($job['id'] ?? 0);
        $token = bin2hex(random_bytes(8));
        $audioPath = $this->whisper->safeTempAudioPath($jobId, $token);
        $outputBase = $this->whisper->safeTempOutputBasePath($jobId, $token);
        $jsonPath = $outputBase . '.json';

        try {
            $this->whisper->extractAudioToWav($inputPath, $audioPath);
            $this->transcriptions->updateProgress($jobId, 10.0);
            $json = $this->whisper->transcribeAudioToJson($audioPath, $outputBase, (string) ($job['requested_language'] ?? 'auto'));
            $segments = $this->validSegments($this->parser->parseJson($json));

            if ($segments === []) {
                throw new RuntimeException('Whisper no genero segmentos de texto.');
            }

            $fullText = $this->fullText($segments);
            $detectedLanguage = (string) ($job['requested_language'] ?? '') === 'auto' ? $this->parser->detectedLanguage($json) : null;
            $model = $this->parser->modelName($json) ?? (string) ($job['model'] ?? $this->whisper->configuredModelName());
            $this->transcriptions->markCompleted($jobId, $detectedLanguage, $model, $fullText, $segments);
        } finally {
            foreach ([$audioPath, $jsonPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     * @return array<int, array{start_seconds: float, end_seconds: float, text: string}>
     */
    private function validSegments(array $segments): array
    {
        usort($segments, static fn (array $left, array $right): int => $left['start_seconds'] <=> $right['start_seconds']);
        $valid = [];

        foreach ($segments as $segment) {
            $start = round((float) $segment['start_seconds'], 3);
            $end = round((float) $segment['end_seconds'], 3);
            $text = trim((string) preg_replace('/\s+/u', ' ', $segment['text']));

            if ($start < 0.0 || $end <= $start || $text === '' || preg_match('//u', $text) !== 1) {
                continue;
            }

            $valid[] = [
                'start_seconds' => $start,
                'end_seconds' => $end,
                'text' => $text,
            ];
        }

        return $valid;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    private function fullText(array $segments): string
    {
        return trim(implode(' ', array_map(static fn (array $segment): string => $segment['text'], $segments)));
    }
}
