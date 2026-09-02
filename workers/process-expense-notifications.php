<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseNotificationFormatter;
use Modules\Expenses\ExpenseNotificationService;
use Modules\Notifications\NotificationRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/expense-notifications-worker.log';

try {
    $pdo = Connection::get();
    $timezone = (string) ($config['app']['timezone'] ?? DateTimeHelper::DEFAULT_TIMEZONE);
    $service = new ExpenseNotificationService(
        $pdo,
        new NotificationRepository($pdo),
        new ExpenseNotificationFormatter($timezone),
        $timezone,
    );
    $summary = $service->process();
    $output = 'Processed users: ' . (int) $summary['processed_users'] . PHP_EOL
        . 'Due tomorrow: ' . (int) $summary['due_tomorrow'] . PHP_EOL
        . 'Due today: ' . (int) $summary['due_today'] . PHP_EOL
        . 'Overdue created: ' . (int) $summary['overdue'] . PHP_EOL
        . 'Missing amount: ' . (int) $summary['missing_amount'] . PHP_EOL
        . 'Weekly summaries: ' . (int) $summary['weekly_summaries'] . PHP_EOL
        . 'Duplicates skipped: ' . (int) $summary['duplicates_skipped'] . PHP_EOL
        . 'Errors: ' . (int) $summary['errors'] . PHP_EOL;

    echo $output;
    expenseNotificationWorkerLog($logPath, trim(str_replace(PHP_EOL, ' ', $output)));
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    expenseNotificationWorkerLog($logPath, 'Critical failure: ' . $exception->getMessage());
    fwrite(STDERR, "Critical failure processing expense notifications.\n");
    exit(1);
}

function expenseNotificationWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
