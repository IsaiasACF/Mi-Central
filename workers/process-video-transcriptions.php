<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Video\TranscriptionSourceResolver;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;
use Modules\Video\VideoTranscriptionProcessor;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\WhisperService;
use Modules\Video\WhisperTranscriptParser;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/video-transcriptions-worker.log';
$startedAt = microtime(true);

try {
    $pdo = Connection::get();
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $storage = new VideoStorage($videoConfig);
    $transcriptions = new VideoTranscriptionRepository($pdo);
    $whisper = new WhisperService($videoConfig, $storage);
    $processor = new VideoTranscriptionProcessor(
        $videoConfig,
        new TranscriptionSourceResolver(new VideoRepository($pdo), new VideoExportJobRepository($pdo), $storage),
        $transcriptions,
        $whisper,
        new WhisperTranscriptParser(),
        $storage,
    );
    $summary = [
        'processed' => 0,
        'completed' => 0,
        'failed' => 0,
    ];
    $job = $transcriptions->reserveNextPending();

    if ($job !== null) {
        $summary['processed'] = 1;

        try {
            $processor->process($job);
            $summary['completed'] = 1;
        } catch (Throwable $exception) {
            $transcriptions->markFailed((int) $job['id'], videoTranscriptionWorkerError($exception->getMessage()));
            $summary['failed'] = 1;
        }
    }

    $duration = round(microtime(true) - $startedAt, 2);
    $output = 'Processed: ' . $summary['processed'] . PHP_EOL
        . 'Completed: ' . $summary['completed'] . PHP_EOL
        . 'Failed: ' . $summary['failed'] . PHP_EOL
        . 'Duration seconds: ' . number_format($duration, 2, '.', '') . PHP_EOL;

    echo $output;
    videoTranscriptionWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit(0);
} catch (Throwable $exception) {
    videoTranscriptionWorkerLog($logPath, 'Critical failure: ' . videoTranscriptionWorkerError($exception->getMessage()));
    fwrite(STDERR, "Critical failure processing video transcriptions.\n");
    exit(1);
}

function videoTranscriptionWorkerError(string $message): string
{
    $message = trim((string) preg_replace('/\s+/', ' ', $message));

    if ($message === '') {
        return 'No se pudo transcribir el video.';
    }

    return substr($message, 0, 240);
}

function videoTranscriptionWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
