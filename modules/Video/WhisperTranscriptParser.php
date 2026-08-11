<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class WhisperTranscriptParser
{
    public function detectedLanguage(string $json): ?string
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        $language = $result['language'] ?? null;

        return is_string($language) && preg_match('/\A[a-z]{2,8}\z/i', $language) === 1 ? strtolower($language) : null;
    }

    public function modelName(string $json): ?string
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        $model = is_array($decoded['model'] ?? null) ? $decoded['model'] : [];
        $type = $model['type'] ?? null;

        return is_string($type) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $type) === 1 ? $type : null;
    }

    /**
     * @return array<int, array{start_seconds: float, end_seconds: float, text: string}>
     */
    public function parseJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Salida JSON de Whisper invalida.');
        }

        $rows = $decoded['transcription'] ?? null;

        if (!is_array($rows)) {
            throw new RuntimeException('Salida JSON de Whisper sin segmentos.');
        }

        $segments = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = $this->segmentText($row);

            if ($text === '') {
                continue;
            }

            $range = $this->segmentRange($row);

            if ($range === null) {
                continue;
            }

            $segments[] = [
                'start_seconds' => $range['start_seconds'],
                'end_seconds' => $range['end_seconds'],
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function segmentText(array $row): string
    {
        $text = $row['text'] ?? '';

        if (!is_string($text)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{start_seconds: float, end_seconds: float}|null
     */
    private function segmentRange(array $row): ?array
    {
        $offsets = is_array($row['offsets'] ?? null) ? $row['offsets'] : null;

        if ($offsets !== null && is_numeric($offsets['from'] ?? null) && is_numeric($offsets['to'] ?? null)) {
            return $this->validRange(((float) $offsets['from']) / 1000.0, ((float) $offsets['to']) / 1000.0);
        }

        $timestamps = is_array($row['timestamps'] ?? null) ? $row['timestamps'] : null;

        if ($timestamps !== null && is_string($timestamps['from'] ?? null) && is_string($timestamps['to'] ?? null)) {
            return $this->validRange(
                $this->timestampToSeconds($timestamps['from']),
                $this->timestampToSeconds($timestamps['to'])
            );
        }

        return null;
    }

    /**
     * @return array{start_seconds: float, end_seconds: float}|null
     */
    private function validRange(float $start, float $end): ?array
    {
        if (!is_finite($start) || !is_finite($end) || $start < 0.0 || $end <= $start) {
            return null;
        }

        return [
            'start_seconds' => round($start, 3),
            'end_seconds' => round($end, 3),
        ];
    }

    private function timestampToSeconds(string $value): float
    {
        $value = trim(str_replace(',', '.', $value));

        if (preg_match('/\A(?:(\d+):)?(\d{1,2}):(\d{2})(?:\.(\d{1,6}))?\z/', $value, $matches) !== 1) {
            return 0.0;
        }

        $hours = (int) (($matches[1] ?? '') === '' ? 0 : $matches[1]);
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $fraction = (string) ($matches[4] ?? '');
        $fractionSeconds = $fraction === '' ? 0.0 : (float) ('0.' . str_pad(substr($fraction, 0, 6), 6, '0'));

        return ($hours * 3600) + ($minutes * 60) + $seconds + $fractionSeconds;
    }
}
