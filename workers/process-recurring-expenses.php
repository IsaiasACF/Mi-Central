<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseRecurringAdjustmentRepository;
use Modules\Expenses\ExpenseRecurringRuleRepository;
use Modules\Expenses\RecurringExpenseWorker;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/recurring-expenses-worker.log';

try {
    $pdo = Connection::get();
    $repository = new ExpenseRecurringRuleRepository($pdo);
    $worker = new RecurringExpenseWorker(
        $repository,
        (string) ($config['app']['timezone'] ?? 'America/Santiago'),
        new ExpenseRecurringAdjustmentRepository($pdo),
    );
    $summary = $worker->process();

    echo 'Processed rules: ' . $summary['processed_rules'] . PHP_EOL;
    echo 'Created expenses: ' . $summary['created_expenses'] . PHP_EOL;
    echo 'Skipped existing: ' . $summary['skipped_existing'] . PHP_EOL;
    echo 'Errors: ' . $summary['errors'] . PHP_EOL;

    recurringExpensesWorkerLog(
        $logPath,
        'Processed rules: ' . $summary['processed_rules']
        . ' | Created expenses: ' . $summary['created_expenses']
        . ' | Skipped existing: ' . $summary['skipped_existing']
        . ' | Errors: ' . $summary['errors'],
    );

    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    recurringExpensesWorkerLog($logPath, 'Critical failure: ' . $exception->getMessage());
    fwrite(STDERR, "Critical failure processing recurring expenses.\n");
    exit(1);
}

function recurringExpensesWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
