<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\CollectorHttpClient;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorLogger;
use Modules\Discounts\Collectors\DiscountCollectorRunRepository;
use Modules\Discounts\Collectors\DiscountCollectorRunner;
use Modules\Discounts\Collectors\DiscountCollectorScheduleRepository;
use Modules\Discounts\Collectors\DiscountCollectorScheduler;
use Modules\Discounts\Collectors\DiscountPromotionImporter;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

require_once __DIR__ . '/run-discount-collector.php';

if (!function_exists('processDiscountCollectorsCommand')) {
    /**
     * @param array<string, mixed> $config
     */
    function processDiscountCollectorsCommand(
        DiscountCollectorRegistry $registry,
        array $config,
        ?CollectorHttpClient $httpClient = null,
        ?DiscountPromotionImporter $pipeline = null,
        ?DiscountCollectorRunRepository $runs = null,
        ?DiscountCollectorLogger $logger = null,
    ): int {
        $collectorConfig = is_array($config['collectors'] ?? null) ? $config['collectors'] : [];
        $httpConfig = is_array($collectorConfig['http'] ?? null) ? $collectorConfig['http'] : [];
        $timezone = is_string($config['app']['timezone'] ?? null) ? (string) $config['app']['timezone'] : 'America/Santiago';
        $pdo = Connection::get();
        $scheduler = new DiscountCollectorScheduler(
            new DiscountCollectorScheduleRepository($pdo),
            $registry,
            new DiscountCollectorRunner(
                $registry,
                $httpClient ?? discountCollectorHttpClientFromConfig($httpConfig),
                $timezone,
                is_array($collectorConfig['collectors'] ?? null) ? $collectorConfig['collectors'] : [],
            ),
            $pipeline ?? discountCollectorImportPipelineFromConfig($config),
            is_array($collectorConfig['scheduler'] ?? null) ? $collectorConfig['scheduler'] : [],
            $runs ?? new DiscountCollectorRunRepository($pdo),
            $logger ?? new DiscountCollectorLogger(dirname(__DIR__) . '/storage/logs/discount-collectors-worker.log'),
        );
        $result = $scheduler->processNextDue();
        $import = $result['import'] ?? null;

        echo (string) $result['message'] . PHP_EOL;
        echo 'Status: ' . (string) $result['status'] . PHP_EOL;

        if (is_string($result['collector_key'] ?? null) && $result['collector_key'] !== '') {
            echo 'Collector: ' . $result['collector_key'] . PHP_EOL;
        }

        if ($import instanceof \Modules\Discounts\Collectors\PromotionImportResult) {
            echo 'Found: ' . $import->collectedCount() . PHP_EOL;
            echo 'Created: ' . $import->createdCount() . PHP_EOL;
            echo 'Updated: ' . $import->updatedCount() . PHP_EOL;
            echo 'Skipped: ' . $import->skippedCount() . PHP_EOL;
            echo 'Errors: ' . $import->errorCount() . PHP_EOL;
        }

        return 0;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $basePath = dirname(__DIR__);
    $config = require $basePath . '/app/bootstrap.php';
    $logPath = $basePath . '/storage/logs/discount-collectors-worker.log';

    try {
        $exitCode = processDiscountCollectorsCommand(new DiscountCollectorRegistry(), $config);
        (new DiscountCollectorLogger($logPath))->logMessage('Scheduler cycle completed.');
        exit($exitCode);
    } catch (Throwable $exception) {
        (new DiscountCollectorLogger($logPath))->logMessage('Critical failure: ' . discountCollectorWorkerError($exception->getMessage()));
        fwrite(STDERR, "Critical failure processing discount collectors.\n");
        exit(1);
    }
}

function discountCollectorWorkerError(string $message): string
{
    $message = trim((string) preg_replace('/\s+/', ' ', strip_tags($message)));

    if ($message === '') {
        return 'No se pudo procesar collectors.';
    }

    return substr($message, 0, 240);
}
