<?php
declare(strict_types=1);

use Modules\Video\WhisperService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$video = is_array($config['video'] ?? null) ? $config['video'] : [];
$service = new WhisperService($video);
$errors = 0;

if ($service->binaryIsExecutable($service->whisperBinary())) {
    echo "Whisper: OK\n";
} else {
    echo "Whisper: NOT AVAILABLE\n";
    $errors++;
}

try {
    if ($service->modelsDirectoryIsAccessible()) {
        echo "Models directory: OK\n";
    } else {
        echo "Models directory: NOT ACCESSIBLE\n";
        $errors++;
    }
} catch (Throwable $exception) {
    echo "Models directory: NOT ACCESSIBLE\n";
    $errors++;
}

if ($service->modelIsInstalled()) {
    echo "Model: OK\n";
} else {
    echo "Model: NOT INSTALLED\n";
    $errors++;
}

if ($service->binaryIsExecutable($service->ffmpegBinary())) {
    echo "FFmpeg: OK\n";
} else {
    echo "FFmpeg: NOT AVAILABLE\n";
    $errors++;
}

if ($service->binaryIsExecutable($service->ffprobeBinary())) {
    echo "FFprobe: OK\n";
} else {
    echo "FFprobe: NOT AVAILABLE\n";
    $errors++;
}

if ($service->tempDirectoryIsWritable()) {
    echo "Temp directory: OK\n";
} else {
    echo "Temp directory: NOT WRITABLE\n";
    $errors++;
}

if ($errors > 0) {
    fwrite(STDERR, "Transcription environment: FAILED\n");
    exit(1);
}

echo "Transcription environment: OK\n";
exit(0);
