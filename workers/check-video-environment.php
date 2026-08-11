<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$video = is_array($config['video'] ?? null) ? $config['video'] : [];
$errors = 0;

$ffmpegBin = stringConfig($video, 'ffmpeg_bin', '/usr/bin/ffmpeg');
$ffprobeBin = stringConfig($video, 'ffprobe_bin', '/usr/bin/ffprobe');
$directories = [
    'Uploads directory' => stringConfig($video, 'uploads_path', $basePath . '/storage/video/uploads'),
    'Temp directory' => stringConfig($video, 'temp_path', $basePath . '/storage/video/temp'),
    'Exports directory' => stringConfig($video, 'exports_path', $basePath . '/storage/video/exports'),
    'Jobs directory' => stringConfig($video, 'jobs_path', $basePath . '/storage/video/jobs'),
];

if (checkBinary('FFmpeg', $ffmpegBin)) {
    echo "FFmpeg: OK\n";
} else {
    $errors++;
}

if (checkBinary('FFprobe', $ffprobeBin)) {
    echo "FFprobe: OK\n";
} else {
    $errors++;
}

foreach ($directories as $label => $path) {
    if (checkDirectory($label, $path)) {
        echo $label . ": OK\n";
        continue;
    }

    $errors++;
}

if ($errors > 0) {
    fwrite(STDERR, "Video worker environment: FAILED\n");
    exit(1);
}

echo "Video worker environment: OK\n";
exit(0);

/**
 * @param array<string, mixed> $config
 */
function stringConfig(array $config, string $key, string $fallback): string
{
    $value = $config[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $fallback;
}

function checkBinary(string $label, string $path): bool
{
    if (!is_file($path) || !is_executable($path)) {
        fwrite(STDERR, $label . ': not executable at ' . $path . PHP_EOL);
        return false;
    }

    $command = escapeshellarg($path) . ' -version 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || $output === []) {
        fwrite(STDERR, $label . ': version command failed.' . PHP_EOL);
        return false;
    }

    return true;
}

function checkDirectory(string $label, string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        fwrite(STDERR, $label . ': could not create ' . $path . PHP_EOL);
        return false;
    }

    if (!is_writable($path)) {
        fwrite(STDERR, $label . ': not writable at ' . $path . PHP_EOL);
        return false;
    }

    $probe = rtrim($path, '/') . '/.write-test-' . bin2hex(random_bytes(6));

    if (file_put_contents($probe, 'ok') === false) {
        fwrite(STDERR, $label . ': write test failed at ' . $path . PHP_EOL);
        return false;
    }

    unlink($probe);

    return true;
}
