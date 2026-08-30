<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Notifications\NotificationActivityService;
use Modules\Notifications\NotificationRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/notification-activity-worker.log';

try {
    $pdo = Connection::get();
    $service = new NotificationActivityService(
        $pdo,
        new NotificationRepository($pdo),
        (string) ($config['app']['timezone'] ?? 'America/Santiago'),
    );
    $summary = $service->process();
    $output = 'Daily agenda: ' . (int) ($summary['daily_agenda'] ?? 0) . PHP_EOL
        . 'Task notifications: ' . (int) ($summary['tasks'] ?? 0) . PHP_EOL
        . 'Project notifications: ' . (int) ($summary['projects'] ?? 0) . PHP_EOL
        . 'Video notifications: ' . (int) ($summary['video'] ?? 0) . PHP_EOL;

    echo $output;
    notificationActivityWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit(0);
} catch (Throwable $exception) {
    notificationActivityWorkerLog($logPath, 'Critical failure: ' . $exception->getMessage());
    fwrite(STDERR, "Critical failure processing notification activity.\n");
    exit(1);
}

function notificationActivityWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
