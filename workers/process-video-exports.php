<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoExportProcessor;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/video-exports-worker.log';

try {
    $pdo = Connection::get();
    $jobs = new VideoExportJobRepository($pdo);
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $processor = new VideoExportProcessor(
        $videoConfig,
        new VideoRepository($pdo),
        $jobs,
        new VideoStorage($videoConfig),
    );
    $summary = [
        'processed' => 0,
        'completed' => 0,
        'failed' => 0,
    ];
    $job = $jobs->reserveNextPending();

    if ($job !== null) {
        $summary['processed'] = 1;

        try {
            $processor->process($job);
            $summary['completed'] = 1;
        } catch (Throwable $exception) {
            $jobs->markFailed((int) $job['id'], videoExportWorkerError($exception->getMessage()));
            $summary['failed'] = 1;
        }
    }

    $output = 'Processed: ' . $summary['processed'] . PHP_EOL
        . 'Completed: ' . $summary['completed'] . PHP_EOL
        . 'Failed: ' . $summary['failed'] . PHP_EOL;

    echo $output;
    videoExportWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit(0);
} catch (Throwable $exception) {
    videoExportWorkerLog($logPath, 'Critical failure: ' . videoExportWorkerError($exception->getMessage()));
    fwrite(STDERR, "Critical failure processing video exports.\n");
    exit(1);
}

function videoExportWorkerError(string $message): string
{
    $message = trim((string) preg_replace('/\s+/', ' ', $message));

    if ($message === '') {
        return 'No se pudo exportar el video.';
    }

    return substr($message, 0, 240);
}

function videoExportWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
