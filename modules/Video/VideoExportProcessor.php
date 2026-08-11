<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class VideoExportProcessor
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly VideoRepository $videos,
        private readonly VideoExportJobRepository $jobs,
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
        $video = $this->videoForJob($job);
        $inputPath = $storage->pathForStoredVideo($video);

        if ($inputPath === null) {
            throw new RuntimeException('Archivo original no disponible.');
        }

        $segments = $this->validSnapshot((int) $job['id'], (float) $video['duration_seconds']);
        $storedName = (string) ($job['output_stored_name'] ?? '');
        $finalPath = $storage->safeExportPath($storedName);
        $tempPath = $storage->safeTempExportPath((int) $job['id'], bin2hex(random_bytes(8)));

        if ($finalPath === null || $tempPath === null) {
            throw new RuntimeException('Ruta de exportacion invalida.');
        }

        if (is_file($finalPath)) {
            throw new RuntimeException('La exportacion ya existe.');
        }

        try {
            $this->runFfmpeg($inputPath, $tempPath, $segments, $this->hasAudio($video), (int) $job['id'], (float) $job['estimated_duration_seconds']);
            $outputDuration = $this->verifyOutput($tempPath, $segments);

            if (!rename($tempPath, $finalPath)) {
                throw new RuntimeException('No se pudo guardar la exportacion final.');
            }

            $size = filesize($finalPath);

            if (!is_int($size) || $size <= 0) {
                throw new RuntimeException('La exportacion final esta vacia.');
            }

            $this->jobs->markCompleted(
                (int) $job['id'],
                $storage->relativeExportPath($storedName),
                $size,
                $outputDuration,
                $this->retentionDays()
            );
        } catch (\Throwable $exception) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            if (is_file($finalPath) && (string) ($job['output_path'] ?? '') === '') {
                unlink($finalPath);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function videoForJob(array $job): array
    {
        $videos = $this->videos->findByIds([(int) ($job['video_id'] ?? 0)]);
        $video = $videos[0] ?? null;

        if (!is_array($video) || (int) $video['user_id'] !== (int) ($job['user_id'] ?? 0)) {
            throw new RuntimeException('Video no disponible para exportar.');
        }

        if (($video['metadata_status'] ?? '') !== 'ready' || (float) ($video['duration_seconds'] ?? 0) <= 0.0) {
            throw new RuntimeException('Metadata de video no disponible.');
        }

        return $video;
    }

    /**
     * @return array<int, array{start: float, end: float}>
     */
    private function validSnapshot(int $jobId, float $duration): array
    {
        $rows = $this->jobs->listSnapshotSegments($jobId);
        $segments = [];

        foreach ($rows as $row) {
            $start = round((float) $row['source_start_seconds'], 3);
            $end = round((float) $row['source_end_seconds'], 3);

            if ($start < 0.0 || $end <= $start || $end > $duration + 0.001) {
                throw new RuntimeException('Snapshot de exportacion invalido.');
            }

            $segments[] = ['start' => $start, 'end' => $end];
        }

        if ($segments === []) {
            throw new RuntimeException('No existen segmentos para exportar.');
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $video
     */
    private function hasAudio(array $video): bool
    {
        return is_string($video['audio_codec'] ?? null) && (string) $video['audio_codec'] !== '';
    }

    /**
     * @param array<int, array{start: float, end: float}> $segments
     */
    private function runFfmpeg(string $inputPath, string $outputPath, array $segments, bool $hasAudio, int $jobId, float $estimatedDuration): void
    {
        $filter = $this->filterComplex($segments, $hasAudio);
        $command = [
            $this->ffmpegBinary(),
            '-hide_banner',
            '-y',
            '-nostats',
            '-progress',
            'pipe:1',
            '-i',
            $inputPath,
            '-filter_complex',
            $filter,
            '-map',
            '[vout]',
        ];

        if ($hasAudio) {
            $command[] = '-map';
            $command[] = '[aout]';
        } else {
            $command[] = '-an';
        }

        $command = array_merge($command, [
            '-c:v',
            'libx264',
            '-preset',
            'medium',
            '-crf',
            '22',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
        ]);

        if ($hasAudio) {
            $command[] = '-c:a';
            $command[] = 'aac';
            $command[] = '-b:a';
            $command[] = '160k';
        }

        $command[] = $outputPath;
        $this->runFfmpegProcess($command, $jobId, $estimatedDuration);
    }

    /**
     * @param array<int, array{start: float, end: float}> $segments
     */
    private function filterComplex(array $segments, bool $hasAudio): string
    {
        $parts = [];
        $concatInputs = '';

        foreach ($segments as $index => $segment) {
            $start = $this->filterNumber($segment['start']);
            $end = $this->filterNumber($segment['end']);
            $parts[] = '[0:v]trim=start=' . $start . ':end=' . $end . ',setpts=PTS-STARTPTS,pad=ceil(iw/2)*2:ceil(ih/2)*2[v' . $index . ']';
            $concatInputs .= '[v' . $index . ']';

            if ($hasAudio) {
                $parts[] = '[0:a]atrim=start=' . $start . ':end=' . $end . ',asetpts=PTS-STARTPTS[a' . $index . ']';
                $concatInputs .= '[a' . $index . ']';
            }
        }

        $parts[] = $concatInputs . 'concat=n=' . count($segments) . ':v=1:a=' . ($hasAudio ? '1' : '0') . ($hasAudio ? '[vout][aout]' : '[vout]');

        return implode(';', $parts);
    }

    private function filterNumber(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }

    /**
     * @param array<int, array{start: float, end: float}> $segments
     */
    private function verifyOutput(string $path, array $segments): float
    {
        if (!is_file($path)) {
            throw new RuntimeException('FFmpeg no genero archivo de salida.');
        }

        $size = filesize($path);

        if (!is_int($size) || $size <= 0) {
            throw new RuntimeException('FFmpeg genero un archivo vacio.');
        }

        $metadata = $this->probeFile($path);
        $duration = $metadata['duration'];
        $expected = array_reduce($segments, static fn (float $carry, array $segment): float => $carry + ($segment['end'] - $segment['start']), 0.0);

        if (!$metadata['has_video'] || $duration <= 0.0 || abs($duration - $expected) > max(2.0, $expected * 0.08)) {
            throw new RuntimeException('La exportacion no paso la verificacion tecnica.');
        }

        return $duration;
    }

    /**
     * @param array<int, string> $command
     */
    private function runFfmpegProcess(array $command, int $jobId, float $estimatedDuration): void
    {
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('FFmpeg no pudo exportar el video.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffer = '';
        $stderr = '';
        $lastPercent = -1.0;
        $lastWrite = microtime(true);
        $currentSpeed = null;

        while (true) {
            $status = proc_get_status($process);
            $stdoutChunk = stream_get_contents($pipes[1]);

            if (is_string($stdoutChunk) && $stdoutChunk !== '') {
                $buffer .= $stdoutChunk;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines) ?? '';

                foreach ($lines as $line) {
                    $progress = $this->parseProgressLine($line, $estimatedDuration);

                    if ($progress === null) {
                        continue;
                    }

                    if ($progress['processed_seconds'] === null && !$progress['done']) {
                        $currentSpeed = $progress['speed'] ?? $currentSpeed;
                        continue;
                    }

                    $now = microtime(true);
                    $speed = $progress['speed'] ?? $currentSpeed;

                    if ($progress['percent'] - $lastPercent >= 1.0 || $now - $lastWrite >= 1.0 || $progress['done']) {
                        $this->jobs->updateProgress($jobId, $progress['percent'], $progress['processed_seconds'], $speed);
                        $lastPercent = $progress['percent'];
                        $lastWrite = $now;
                    }
                }
            }

            $stderrChunk = stream_get_contents($pipes[2]);

            if (is_string($stderrChunk) && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
                $stderr = substr($stderr, -4000);
            }

            if (!($status['running'] ?? false)) {
                break;
            }

            usleep(100000);
        }

        $tail = stream_get_contents($pipes[1]);

        if (is_string($tail) && $tail !== '') {
            $buffer .= $tail;
        }

        foreach (explode("\n", $buffer) as $line) {
            $progress = $this->parseProgressLine($line, $estimatedDuration);

            if ($progress !== null) {
                if ($progress['processed_seconds'] === null && !$progress['done']) {
                    $currentSpeed = $progress['speed'] ?? $currentSpeed;
                    continue;
                }

                $this->jobs->updateProgress($jobId, $progress['percent'], $progress['processed_seconds'], $progress['speed'] ?? $currentSpeed);
            }
        }

        $stderrTail = stream_get_contents($pipes[2]);

        if (is_string($stderrTail) && $stderrTail !== '') {
            $stderr .= $stderrTail;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException($this->controlledError('FFmpeg no pudo exportar el video.', $stderr));
        }
    }

    /**
     * @return array{percent: float, processed_seconds: ?float, speed: ?string, done: bool}|null
     */
    public function parseProgressLine(string $line, float $estimatedDuration): ?array
    {
        $line = trim($line);

        if ($line === '' || !str_contains($line, '=')) {
            return null;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === 'out_time_us' || $key === 'out_time_ms') {
            $processed = max(0.0, ((float) $value) / 1000000.0);
        } elseif ($key === 'out_time') {
            $processed = $this->timecodeToSeconds($value);
        } elseif ($key === 'speed') {
            return [
                'percent' => 0.0,
                'processed_seconds' => null,
                'speed' => substr($value, 0, 32),
                'done' => false,
            ];
        } elseif ($key === 'progress' && $value === 'end') {
            return [
                'percent' => 100.0,
                'processed_seconds' => $estimatedDuration > 0.0 ? $estimatedDuration : null,
                'speed' => null,
                'done' => true,
            ];
        } else {
            return null;
        }

        return [
            'percent' => $this->progressPercent($processed, $estimatedDuration),
            'processed_seconds' => $processed,
            'speed' => null,
            'done' => false,
        ];
    }

    public function progressPercent(float $processedSeconds, float $estimatedDuration): float
    {
        if ($estimatedDuration <= 0.0 || !is_finite($processedSeconds) || !is_finite($estimatedDuration)) {
            return 0.0;
        }

        return max(0.0, min(100.0, round(($processedSeconds / $estimatedDuration) * 100, 2)));
    }

    private function timecodeToSeconds(string $value): float
    {
        if (preg_match('/\A(\d+):(\d{2}):(\d{2})(?:\.(\d{1,6}))?\z/', $value, $matches) !== 1) {
            return 0.0;
        }

        return ((int) $matches[1] * 3600)
            + ((int) $matches[2] * 60)
            + (int) $matches[3]
            + ((float) ('0.' . ($matches[4] ?? '0')));
    }

    /**
     * @return array{duration: float, has_video: bool}
     */
    private function probeFile(string $path): array
    {
        $json = $this->runProcess([
            $this->ffprobeBinary(),
            '-v',
            'error',
            '-show_format',
            '-show_streams',
            '-of',
            'json',
            $path,
        ], 'FFprobe no pudo verificar la exportacion.');
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('FFprobe devolvio verificacion invalida.');
        }

        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $hasVideo = false;

        foreach ($streams as $stream) {
            if (is_array($stream) && (string) ($stream['codec_type'] ?? '') === 'video') {
                $hasVideo = true;
                break;
            }
        }

        return [
            'duration' => round((float) ($format['duration'] ?? 0), 3),
            'has_video' => $hasVideo,
        ];
    }

    /**
     * @param array<int, string> $command
     */
    private function runProcess(array $command, string $fallbackError): string
    {
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException($fallbackError);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException($this->controlledError($fallbackError, is_string($stderr) ? $stderr : ''));
        }

        return is_string($stdout) ? $stdout : '';
    }

    private function ffmpegBinary(): string
    {
        $binary = $this->config['ffmpeg_bin'] ?? null;
        $binary = is_string($binary) && $binary !== '' ? $binary : '/usr/bin/ffmpeg';

        if (!is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('FFmpeg no esta disponible.');
        }

        return $binary;
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

    private function controlledError(string $fallback, string $stderr): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $stderr));

        if ($message === '') {
            return $fallback;
        }

        return $fallback . ' ' . substr($message, 0, 220);
    }

    private function retentionDays(): int
    {
        return max(1, min(3650, (int) ($this->config['export_retention_days'] ?? 30)));
    }
}
