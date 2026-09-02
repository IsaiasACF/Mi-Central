<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunRepository;
use Modules\Discounts\Collectors\DiscountCollectorRunner;
use Modules\Discounts\Collectors\DiscountCollectorScheduleRepository;
use Modules\Discounts\Collectors\DiscountCollectorScheduler;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

require_once __DIR__ . '/run-discount-collector.php';

if (!function_exists('discountCollectorScheduleCommand')) {
    /**
     * @param array<int, string> $argv
     * @param array<string, mixed> $config
     */
    function discountCollectorScheduleCommand(array $argv, DiscountCollectorRegistry $registry, array $config): int
    {
        $command = (string) ($argv[1] ?? 'status');
        $collectorKey = '';
        $minutes = null;
        $runId = null;

        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--collector=')) {
                $collectorKey = trim(substr($arg, 12));
                continue;
            }

            if (str_starts_with($arg, '--minutes=')) {
                $minutes = (int) substr($arg, 10);
                continue;
            }

            if (str_starts_with($arg, '--run-id=')) {
                $runId = (int) substr($arg, 9);
            }
        }

        $collectorConfig = is_array($config['collectors'] ?? null) ? $config['collectors'] : [];
        $httpConfig = is_array($collectorConfig['http'] ?? null) ? $collectorConfig['http'] : [];
        $timezone = is_string($config['app']['timezone'] ?? null) ? (string) $config['app']['timezone'] : 'America/Santiago';
        $pdo = Connection::get();
        $runRepository = new DiscountCollectorRunRepository($pdo);
        $scheduler = new DiscountCollectorScheduler(
            new DiscountCollectorScheduleRepository($pdo),
            $registry,
            new DiscountCollectorRunner(
                $registry,
                discountCollectorHttpClientFromConfig($httpConfig),
                $timezone,
                is_array($collectorConfig['collectors'] ?? null) ? $collectorConfig['collectors'] : [],
            ),
            discountCollectorImportPipelineFromConfig($config),
            is_array($collectorConfig['scheduler'] ?? null) ? $collectorConfig['scheduler'] : [],
        );

        if ($command === 'status') {
            $rows = $scheduler->statusRows();

            if ($rows === []) {
                echo "No collectors registered.\n";
                return 0;
            }

            echo "Collector\tEnabled\tStatus\tLast run\tNext run\n";

            foreach ($rows as $row) {
                $lastRun = $runRepository->latestForCollector((string) $row['collector_key']);
                echo (string) $row['collector_key'] . "\t"
                    . ((int) ($row['enabled'] ?? 0) === 1 ? 'yes' : 'no') . "\t"
                    . (string) ($row['last_status'] ?? 'never') . "\t"
                    . (is_array($lastRun) ? (string) $lastRun['started_at'] : '-') . "\t"
                    . (is_string($row['next_run_at'] ?? null) ? (string) $row['next_run_at'] : '-') . PHP_EOL;
            }

            return 0;
        }

        if ($command === 'runs') {
            $runs = $runRepository->latest(20, $collectorKey !== '' ? $collectorKey : null);

            if ($runs === []) {
                echo "No collector runs found.\n";
                return 0;
            }

            echo "ID\tCollector\tTrigger\tStatus\tStarted\tDuration\n";

            foreach ($runs as $run) {
                echo (int) $run['id'] . "\t"
                    . (string) $run['collector_key'] . "\t"
                    . (string) $run['trigger_type'] . "\t"
                    . (string) $run['status'] . "\t"
                    . (string) $run['started_at'] . "\t"
                    . discountCollectorCliDuration($run['duration_ms'] ?? null) . PHP_EOL;
            }

            return 0;
        }

        if ($command === 'run') {
            if ($runId === null || $runId <= 0) {
                echo "Usage:\nphp workers/discount-collector-schedule.php run --run-id=<id>\n";
                return 2;
            }

            $run = $runRepository->find($runId);

            if ($run === null) {
                echo "Collector run not found.\n";
                return 1;
            }

            echo 'ID: ' . (int) $run['id'] . PHP_EOL;
            echo 'Collector: ' . (string) $run['collector_key'] . PHP_EOL;
            echo 'Trigger: ' . (string) $run['trigger_type'] . PHP_EOL;
            echo 'Status: ' . (string) $run['status'] . PHP_EOL;
            echo 'Started: ' . (string) $run['started_at'] . PHP_EOL;
            echo 'Completed: ' . (is_string($run['completed_at'] ?? null) ? (string) $run['completed_at'] : '-') . PHP_EOL;
            echo 'Duration: ' . discountCollectorCliDuration($run['duration_ms'] ?? null) . PHP_EOL;
            echo 'Collected: ' . (int) $run['collected_count'] . PHP_EOL;
            echo 'Normalized: ' . (int) $run['normalized_count'] . PHP_EOL;
            echo 'Created: ' . (int) $run['created_count'] . PHP_EOL;
            echo 'Updated: ' . (int) $run['updated_count'] . PHP_EOL;
            echo 'Duplicates: ' . (int) $run['duplicate_count'] . PHP_EOL;
            echo 'Skipped: ' . (int) $run['skipped_count'] . PHP_EOL;
            echo 'Warnings: ' . (int) $run['warning_count'] . PHP_EOL;
            echo 'Errors: ' . (int) $run['error_count'] . PHP_EOL;

            if (is_string($run['error_type'] ?? null) && $run['error_type'] !== '') {
                echo 'Error type: ' . (string) $run['error_type'] . PHP_EOL;
            }

            if (is_string($run['error_message'] ?? null) && $run['error_message'] !== '') {
                echo 'Error: ' . (string) $run['error_message'] . PHP_EOL;
            }

            if (is_string($run['warning_summary'] ?? null) && $run['warning_summary'] !== '') {
                echo 'Warning summary:' . PHP_EOL . (string) $run['warning_summary'] . PHP_EOL;
            }

            if (is_string($run['error_summary'] ?? null) && $run['error_summary'] !== '') {
                echo 'Error summary:' . PHP_EOL . (string) $run['error_summary'] . PHP_EOL;
            }

            return 0;
        }

        if ($collectorKey === '') {
            echo "Usage:\nphp workers/discount-collector-schedule.php status|runs|run|enable|disable|interval --collector=<key> [--run-id=<id>] [--minutes=1440]\n";
            return 2;
        }

        $scheduler->ensureSchedulesExist();

        if ($command === 'enable') {
            echo $scheduler->setEnabled($collectorKey, true) ? "Collector enabled.\n" : "Collector schedule not found.\n";
            return 0;
        }

        if ($command === 'disable') {
            echo $scheduler->setEnabled($collectorKey, false) ? "Collector disabled.\n" : "Collector schedule not found.\n";
            return 0;
        }

        if ($command === 'interval') {
            if ($minutes === null) {
                echo "Missing --minutes. Minimum: " . $scheduler->minIntervalMinutes() . "\n";
                return 2;
            }

            echo $scheduler->setInterval($collectorKey, $minutes) ? "Collector interval updated.\n" : "Collector schedule not found.\n";
            return 0;
        }

        echo "Unknown command.\n";
        return 2;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $config = require dirname(__DIR__) . '/app/bootstrap.php';

    exit(discountCollectorScheduleCommand($argv, new DiscountCollectorRegistry(), $config));
}

function discountCollectorCliDuration(mixed $durationMs): string
{
    if ($durationMs === null || $durationMs === '') {
        return '-';
    }

    return number_format(((int) $durationMs) / 1000, 1, '.', '') . 's';
}
