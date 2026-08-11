<?php
declare(strict_types=1);

namespace Modules\Video;

final class TranscriptionExportService
{
    private const FORMATS = [
        'txt' => [
            'extension' => 'txt',
            'suffix' => 'transcripcion',
            'content_type' => 'text/plain; charset=UTF-8',
        ],
        'txt_timestamps' => [
            'extension' => 'txt',
            'suffix' => 'transcripcion_tiempos',
            'content_type' => 'text/plain; charset=UTF-8',
        ],
        'srt' => [
            'extension' => 'srt',
            'suffix' => 'subtitulos',
            'content_type' => 'application/x-subrip; charset=UTF-8',
        ],
        'vtt' => [
            'extension' => 'vtt',
            'suffix' => 'subtitulos',
            'content_type' => 'text/vtt; charset=UTF-8',
        ],
    ];

    public function __construct(private readonly VideoTranscriptionRepository $transcriptions)
    {
    }

    /**
     * @return array{content: string, content_type: string, filename: string}
     */
    public function export(int $userId, int $transcriptionId, string $format): array
    {
        $format = $this->normalizeFormat($format);
        $transcription = $this->transcriptions->findForExportForUser($userId, $this->positiveId($transcriptionId));

        if ($transcription === null) {
            throw new VideoValidationException('Transcripcion no encontrada.');
        }

        if ((string) ($transcription['status'] ?? '') !== 'completed') {
            throw new VideoValidationException('La transcripcion no esta completada.');
        }

        $segments = $this->validSegments($this->transcriptions->listSegments((int) $transcription['id']));
        $content = match ($format) {
            'txt' => $this->cleanText($segments, $transcription),
            'txt_timestamps' => $this->timestampedText($segments),
            'srt' => $this->srt($segments),
            'vtt' => $this->vtt($segments),
        };

        return [
            'content' => $content,
            'content_type' => self::FORMATS[$format]['content_type'],
            'filename' => $this->downloadName((string) ($transcription['video_original_name'] ?? 'video'), $format),
        ];
    }

    public function secondsToSrtTimestamp(float $seconds): string
    {
        return $this->timestamp($seconds, ',');
    }

    public function secondsToVttTimestamp(float $seconds): string
    {
        return $this->timestamp($seconds, '.');
    }

    public function secondsToReadableTimestamp(float $seconds): string
    {
        $milliseconds = $this->milliseconds($seconds);
        $totalSeconds = intdiv($milliseconds, 1000);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $remainingSeconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    public function downloadName(string $originalName, string $format): string
    {
        $format = $this->normalizeFormat($format);
        $name = preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $originalName) ?: $originalName;
        $name = preg_replace('/[[:cntrl:]\/\\\\]+/', ' ', $name) ?: '';
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._-');

        if ($name === '') {
            $name = 'video';
        }

        $suffix = self::FORMATS[$format]['suffix'];
        $extension = self::FORMATS[$format]['extension'];
        $limit = max(1, 180 - strlen($suffix) - strlen($extension) - 2);
        $name = substr($name, 0, $limit);

        return $name . '_' . $suffix . '.' . $extension;
    }

    public function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        if (!array_key_exists($format, self::FORMATS)) {
            throw new VideoValidationException('Formato de transcripcion invalido.');
        }

        return $format;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     * @param array<string, mixed> $transcription
     */
    private function cleanText(array $segments, array $transcription): string
    {
        if ($segments !== []) {
            return implode(PHP_EOL, array_map(static fn (array $segment): string => $segment['text'], $segments)) . PHP_EOL;
        }

        $fullText = $this->normalizeText((string) ($transcription['full_text'] ?? ''));

        if ($fullText === '') {
            throw new VideoValidationException('No hay texto disponible para exportar.');
        }

        return $fullText . PHP_EOL;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    private function timestampedText(array $segments): string
    {
        $this->requireTimedSegments($segments);

        $lines = [];

        foreach ($segments as $segment) {
            $lines[] = '[' . $this->secondsToReadableTimestamp($segment['start_seconds']) . '] ' . $segment['text'];
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    private function srt(array $segments): string
    {
        $this->requireTimedSegments($segments);
        $blocks = [];

        foreach ($segments as $index => $segment) {
            $blocks[] = (string) ($index + 1)
                . PHP_EOL
                . $this->secondsToSrtTimestamp($segment['start_seconds']) . ' --> ' . $this->secondsToSrtTimestamp($segment['end_seconds'])
                . PHP_EOL
                . $segment['text'];
        }

        return implode(PHP_EOL . PHP_EOL, $blocks) . PHP_EOL;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    private function vtt(array $segments): string
    {
        $this->requireTimedSegments($segments);
        $blocks = ['WEBVTT'];

        foreach ($segments as $segment) {
            $blocks[] = $this->secondsToVttTimestamp($segment['start_seconds']) . ' --> ' . $this->secondsToVttTimestamp($segment['end_seconds'])
                . PHP_EOL
                . $segment['text'];
        }

        return implode(PHP_EOL . PHP_EOL, $blocks) . PHP_EOL;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array{start_seconds: float, end_seconds: float, text: string}>
     */
    private function validSegments(array $segments): array
    {
        $valid = [];

        foreach ($segments as $segment) {
            $start = round((float) ($segment['start_seconds'] ?? -1), 3);
            $end = round((float) ($segment['end_seconds'] ?? -1), 3);
            $text = $this->normalizeText((string) ($segment['text'] ?? ''));

            if ($start < 0.0 || $end <= $start || $text === '' || preg_match('//u', $text) !== 1) {
                continue;
            }

            $valid[] = [
                'start_seconds' => $start,
                'end_seconds' => $end,
                'text' => $text,
            ];
        }

        usort($valid, static function (array $left, array $right): int {
            $time = $left['start_seconds'] <=> $right['start_seconds'];

            return $time !== 0 ? $time : $left['end_seconds'] <=> $right['end_seconds'];
        });

        return $valid;
    }

    /**
     * @param array<int, array{start_seconds: float, end_seconds: float, text: string}> $segments
     */
    private function requireTimedSegments(array $segments): void
    {
        if ($segments === []) {
            throw new VideoValidationException('No hay segmentos temporales disponibles para generar subtitulos.');
        }
    }

    private function timestamp(float $seconds, string $separator): string
    {
        $milliseconds = $this->milliseconds($seconds);
        $hours = intdiv($milliseconds, 3600000);
        $milliseconds %= 3600000;
        $minutes = intdiv($milliseconds, 60000);
        $milliseconds %= 60000;
        $wholeSeconds = intdiv($milliseconds, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return sprintf('%02d:%02d:%02d%s%03d', $hours, $minutes, $wholeSeconds, $separator, $remainingMilliseconds);
    }

    private function milliseconds(float $seconds): int
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            return 0;
        }

        return (int) round($seconds * 1000);
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/[ \t\r\n]+/u', ' ', $text));
    }

    private function positiveId(int $value): int
    {
        if ($value <= 0) {
            throw new VideoValidationException('Transcripcion no encontrada.');
        }

        return $value;
    }
}
