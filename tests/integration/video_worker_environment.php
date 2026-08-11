<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$video = is_array($config['video'] ?? null) ? $config['video'] : [];
$exitCode = 1;

function video_worker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_worker_config(array $video, string $key, string $fallback): string
{
    $value = $video[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $fallback;
}

function video_worker_exec(string $command, ?array &$output = null): int
{
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);

    return $exitCode;
}

try {
    video_worker_assert(PHP_SAPI === 'cli', 'Video worker test must run from CLI.');

    $checkOutput = [];
    $checkCode = video_worker_exec(escapeshellarg(PHP_BINARY) . ' workers/check-video-environment.php', $checkOutput);
    video_worker_assert($checkCode === 0 && in_array('Video worker environment: OK', $checkOutput, true), 'Video environment check failed.');

    $ffmpeg = video_worker_config($video, 'ffmpeg_bin', '/usr/bin/ffmpeg');
    $ffprobe = video_worker_config($video, 'ffprobe_bin', '/usr/bin/ffprobe');
    $tempPath = video_worker_config($video, 'temp_path', dirname(__DIR__, 2) . '/storage/video/temp');

    video_worker_assert(is_file($ffmpeg) && is_executable($ffmpeg), 'FFmpeg binary is not executable.');
    video_worker_assert(is_file($ffprobe) && is_executable($ffprobe), 'FFprobe binary is not executable.');
    video_worker_assert(is_dir($tempPath) && is_writable($tempPath), 'Video temp directory is not writable.');

    $outputFile = rtrim($tempPath, '/') . '/ffmpeg-test-' . bin2hex(random_bytes(6)) . '.mp4';
    $generateOutput = [];
    $generate = video_worker_exec(
        escapeshellarg($ffmpeg)
        . ' -y -hide_banner -loglevel error'
        . ' -f lavfi -i ' . escapeshellarg('testsrc=size=64x64:rate=1')
        . ' -t 1 -c:v mpeg4 -pix_fmt yuv420p '
        . escapeshellarg($outputFile),
        $generateOutput
    );

    try {
        video_worker_assert($generate === 0 && is_file($outputFile) && filesize($outputFile) > 0, 'FFmpeg could not generate a test video.');

        $probeOutput = [];
        $probe = video_worker_exec(
            escapeshellarg($ffprobe)
            . ' -v error -select_streams v:0'
            . ' -show_entries stream=width,height'
            . ' -of csv=p=0:s=x '
            . escapeshellarg($outputFile),
            $probeOutput
        );

        video_worker_assert($probe === 0 && trim(implode("\n", $probeOutput)) === '64x64', 'FFprobe could not inspect the generated test video.');
    } finally {
        if (is_file($outputFile)) {
            unlink($outputFile);
        }
    }

    echo "Video worker environment: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video worker environment: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
}

exit($exitCode);
