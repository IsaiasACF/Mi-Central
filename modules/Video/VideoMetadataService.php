<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class VideoMetadataService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    public function analyze(array $video): array
    {
        $path = ($this->storage ?? new VideoStorage($this->config))->pathForStoredVideo($video);

        if ($path === null) {
            throw new RuntimeException('Archivo original no disponible.');
        }

        $json = $this->runFfprobe($path);

        return $this->metadataFromJson($json);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataFromJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('FFprobe devolvio metadata invalida.');
        }

        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $videoStream = null;
        $audioStream = null;

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            $type = (string) ($stream['codec_type'] ?? '');

            if ($type === 'video' && $videoStream === null) {
                $videoStream = $stream;
            }

            if ($type === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        if ($videoStream === null) {
            throw new RuntimeException('No se encontro stream de video.');
        }

        $duration = $this->decimalOrNull($format['duration'] ?? null);

        if ($duration === null) {
            $duration = $this->decimalOrNull($videoStream['duration'] ?? null);
        }

        $bitrate = $this->integerOrNull($format['bit_rate'] ?? null);

        if ($bitrate === null) {
            $bitrate = $this->integerOrNull($videoStream['bit_rate'] ?? null);
        }

        return [
            'duration_seconds' => $duration,
            'width' => $this->integerOrNull($videoStream['width'] ?? null),
            'height' => $this->integerOrNull($videoStream['height'] ?? null),
            'fps' => $this->parseFps($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? null),
            'video_codec' => $this->shortTextOrNull($videoStream['codec_name'] ?? null, 64),
            'audio_codec' => $audioStream === null ? null : $this->shortTextOrNull($audioStream['codec_name'] ?? null, 64),
            'container_format' => $this->shortTextOrNull($format['format_name'] ?? null, 120),
            'bitrate' => $bitrate,
        ];
    }

    public function parseFps(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || $raw === '0/0') {
            return null;
        }

        if (str_contains($raw, '/')) {
            $parts = explode('/', $raw, 2);

            if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                return null;
            }

            $numerator = (float) $parts[0];
            $denominator = (float) $parts[1];

            if ($denominator <= 0.0) {
                return null;
            }

            $fps = $numerator / $denominator;
        } elseif (is_numeric($raw)) {
            $fps = (float) $raw;
        } else {
            return null;
        }

        if ($fps <= 0.0 || $fps > 1000.0) {
            return null;
        }

        return round($fps, 3);
    }

    private function runFfprobe(string $path): string
    {
        $ffprobe = $this->ffprobeBinary();
        $command = [
            $ffprobe,
            '-v',
            'error',
            '-show_format',
            '-show_streams',
            '-of',
            'json',
            $path,
        ];
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar FFprobe.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($stdout) || trim($stdout) === '') {
            $message = $this->controlledError(is_string($stderr) ? $stderr : '');
            throw new RuntimeException($message);
        }

        return $stdout;
    }

    private function ffprobeBinary(): string
    {
        $binary = $this->config['ffprobe_bin'] ?? null;
        $binary = is_string($binary) && $binary !== '' ? $binary : '/usr/bin/ffprobe';

        if (!is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('FFprobe no esta disponible.');
        }

        return $binary;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return $number >= 0.0 && is_finite($number) ? round($number, 3) : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function shortTextOrNull(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : substr($value, 0, $maxLength);
    }

    private function controlledError(string $stderr): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $stderr));

        if ($message === '') {
            return 'FFprobe no pudo analizar el archivo.';
        }

        return 'FFprobe no pudo analizar el archivo: ' . substr($message, 0, 220);
    }
}
