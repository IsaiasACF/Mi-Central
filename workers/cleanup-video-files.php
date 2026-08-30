<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Video\VideoCleanupService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoStorage;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/video-cleanup-worker.log';

try {
    $pdo = Connection::get();
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $cleanup = new VideoCleanupService(
        $videoConfig,
        new VideoExportJobRepository($pdo),
        new VideoStorage($videoConfig),
    );
    $summary = $cleanup->cleanup();
    $output = 'Expired exports removed: ' . $summary['expired_exports_removed'] . PHP_EOL
        . 'Temporary files removed: ' . $summary['temporary_files_removed'] . PHP_EOL
        . 'Errors: ' . $summary['errors'] . PHP_EOL;

    echo $output;
    videoCleanupWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    videoCleanupWorkerLog($logPath, 'Critical failure: ' . videoCleanupWorkerError($exception->getMessage()));
    fwrite(STDERR, "Critical failure cleaning video files.\n");
    exit(1);
}

function videoCleanupWorkerError(string $message): string
{
    $message = trim((string) preg_replace('/\s+/', ' ', $message));

    if ($message === '') {
        return 'No se pudo limpiar archivos de video.';
    }

    return substr($message, 0, 240);
}

function videoCleanupWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
