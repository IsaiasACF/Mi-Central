<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Video\VideoMetadataService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/video-metadata-worker.log';
$limit = 5;

try {
    $pdo = Connection::get();
    $repository = new VideoRepository($pdo);
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $metadata = new VideoMetadataService($videoConfig, new VideoStorage($videoConfig));
    $summary = [
        'processed' => 0,
        'ready' => 0,
        'failed' => 0,
    ];

    foreach ($repository->reservePendingMetadata($limit) as $video) {
        $summary['processed']++;

        try {
            $repository->markMetadataReady((int) $video['id'], $metadata->analyze($video));
            $summary['ready']++;
        } catch (Throwable $exception) {
            $repository->markMetadataFailed((int) $video['id'], videoMetadataWorkerError($exception->getMessage()));
            $summary['failed']++;
        }
    }

    $output = 'Processed: ' . $summary['processed'] . PHP_EOL
        . 'Ready: ' . $summary['ready'] . PHP_EOL
        . 'Failed: ' . $summary['failed'] . PHP_EOL;

    echo $output;
    videoMetadataWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit(0);
} catch (Throwable $exception) {
    videoMetadataWorkerLog($logPath, 'Critical failure: ' . videoMetadataWorkerError($exception->getMessage()));
    fwrite(STDERR, "Critical failure processing video metadata.\n");
    exit(1);
}

function videoMetadataWorkerError(string $message): string
{
    $message = trim((string) preg_replace('/\s+/', ' ', $message));

    if ($message === '') {
        return 'No se pudo analizar el video.';
    }

    return substr($message, 0, 240);
}

function videoMetadataWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
