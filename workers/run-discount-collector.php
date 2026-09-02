<?php
declare(strict_types=1);

use Modules\Discounts\Collectors\CollectorHttpClient;
use Modules\Discounts\Collectors\DiscountCollectorErrorSanitizer;
use Modules\Discounts\Collectors\DiscountCollectorLogger;
use Modules\Discounts\Collectors\DiscountBenefitProgramResolver;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunRepository;
use Modules\Discounts\Collectors\DiscountCollectorRunner;
use Modules\Discounts\Collectors\DiscountMerchantResolver;
use Modules\Discounts\Collectors\DiscountPromotionImportPipeline;
use Modules\Discounts\Collectors\PromotionDeduplicator;
use Modules\Discounts\Collectors\PromotionFingerprintService;
use Modules\Discounts\Collectors\PromotionNormalizer;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountTextNormalizer;
use App\Database\Connection;

if (!function_exists('runDiscountCollectorCommand')) {
    /**
     * @param array<int, string> $argv
     * @param array<string, mixed> $config
     */
    function runDiscountCollectorCommand(
        array $argv,
        DiscountCollectorRegistry $registry,
        array $config,
        ?CollectorHttpClient $httpClient = null,
        ?DiscountPromotionImportPipeline $pipeline = null,
        ?DiscountCollectorRunRepository $runs = null,
        ?DiscountCollectorLogger $logger = null,
    ): int
    {
        $args = array_values(array_slice($argv, 1));
        $dryRun = getenv('DRY_RUN') === '1';
        $collectorKey = '';

        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
                continue;
            }

            if ($collectorKey === '') {
                $collectorKey = trim($arg);
            }
        }

        if ($collectorKey === '') {
            echo "Usage:\nphp workers/run-discount-collector.php <collector-key> [--dry-run]\n";
            return 2;
        }

        $runCollectorKey = DiscountCollectorRegistry::isValidKey($collectorKey) ? $collectorKey : 'invalid_collector';
        $pdo = Connection::get();
        $runs = $runs ?? new DiscountCollectorRunRepository($pdo);
        $logger = $logger ?? new DiscountCollectorLogger(dirname(__DIR__) . '/storage/logs/discount-collectors-worker.log');
        $runId = $runs->start($runCollectorKey, $dryRun ? 'dry_run' : 'manual');
        $startedNs = hrtime(true);
        $collectorConfig = is_array($config['collectors'] ?? null) ? $config['collectors'] : [];
        $httpConfig = is_array($collectorConfig['http'] ?? null) ? $collectorConfig['http'] : [];
        $timezone = is_string($config['app']['timezone'] ?? null) ? (string) $config['app']['timezone'] : 'America/Santiago';
        $runner = new DiscountCollectorRunner(
            $registry,
            $httpClient ?? discountCollectorHttpClientFromConfig($httpConfig),
            $timezone,
            is_array($collectorConfig['collectors'] ?? null) ? $collectorConfig['collectors'] : [],
        );
        $result = $runner->run($collectorKey);

        echo 'Collector: ' . $result->collectorKey() . PHP_EOL;
        echo 'Status: ' . ($result->success() ? 'OK' : 'FAILED') . PHP_EOL;
        echo 'Found: ' . count($result->items()) . PHP_EOL;
        echo 'Warnings: ' . count($result->warnings()) . PHP_EOL;

        if (!$result->success()) {
            echo 'Error: ' . DiscountCollectorErrorSanitizer::sanitize($result->errorMessage() ?? 'Collector failed.', 240) . PHP_EOL;
            $runs->fail(
                $runId,
                DiscountCollectorErrorSanitizer::typeFromName($result->errorType(), $result->errorMessage()),
                $result->errorMessage() ?? 'Collector failed.',
                discountCollectorDurationMs($startedNs),
                count($result->items()),
            );
            $logger->logRun($runs->find($runId) ?? []);
            return 1;
        }

        $pipeline = $pipeline ?? discountCollectorImportPipelineFromConfig($config);

        try {
            $import = $pipeline->import($result, $dryRun);
        } catch (Throwable $exception) {
            $runs->fail(
                $runId,
                DiscountCollectorErrorSanitizer::typeFromThrowable($exception),
                $exception->getMessage(),
                discountCollectorDurationMs($startedNs),
                count($result->items()),
            );
            $logger->logRun($runs->find($runId) ?? []);
            echo 'Errors: 1' . PHP_EOL;
            echo 'Import error: ' . DiscountCollectorErrorSanitizer::sanitize($exception->getMessage(), 240) . PHP_EOL;
            return 1;
        }

        if ($import->dryRun()) {
            echo 'DRY RUN' . PHP_EOL;
            echo 'Would create: ' . $import->createdCount() . PHP_EOL;
            echo 'Would update: ' . $import->updatedCount() . PHP_EOL;
        } else {
            echo 'Created: ' . $import->createdCount() . PHP_EOL;
            echo 'Updated: ' . $import->updatedCount() . PHP_EOL;
        }

        echo 'Normalized: ' . $import->normalizedCount() . PHP_EOL;
        echo 'Duplicates: ' . $import->duplicateCount() . PHP_EOL;
        echo 'Skipped: ' . $import->skippedCount() . PHP_EOL;
        echo 'Import warnings: ' . $import->warningCount() . PHP_EOL;
        echo 'Errors: ' . $import->errorCount() . PHP_EOL;

        foreach (array_slice($import->warnings(), 0, 5) as $warning) {
            echo 'Warning: ' . DiscountCollectorErrorSanitizer::sanitize($warning, 240) . PHP_EOL;
        }

        foreach (array_slice($import->errors(), 0, 5) as $error) {
            echo 'Import error: ' . DiscountCollectorErrorSanitizer::sanitize($error, 240) . PHP_EOL;
        }

        $status = $import->errorCount() > 0 ? 'partial' : 'success';
        $runs->completeWithImport(
            $runId,
            $status,
            $import,
            discountCollectorDurationMs($startedNs),
            $status === 'partial' ? 'normalization' : null,
            $status === 'partial' ? 'Import completed with item errors.' : null,
        );
        $logger->logRun($runs->find($runId) ?? []);

        return $import->errorCount() > 0 ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $httpConfig
     */
    function discountCollectorHttpClientFromConfig(array $httpConfig): CollectorHttpClient
    {
        return new CollectorHttpClient(
            timeoutSeconds: (int) ($httpConfig['timeout_seconds'] ?? 15),
            connectTimeoutSeconds: (int) ($httpConfig['connect_timeout_seconds'] ?? 5),
            maxRedirects: (int) ($httpConfig['max_redirects'] ?? 3),
            maxResponseBytes: (int) ($httpConfig['max_response_bytes'] ?? 1048576),
            userAgent: is_string($httpConfig['user_agent'] ?? null) ? (string) $httpConfig['user_agent'] : 'MiCentral-DiscountCollector/1.0',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    function discountCollectorImportPipelineFromConfig(array $config): DiscountPromotionImportPipeline
    {
        $pdo = Connection::get();
        $promotionRepository = new DiscountPromotionRepository($pdo);
        $merchantRepository = new DiscountMerchantRepository($pdo);
        $benefitRepository = new DiscountBenefitProgramRepository($pdo);
        $text = new DiscountTextNormalizer();

        return new DiscountPromotionImportPipeline(
            new PromotionNormalizer($text),
            new PromotionFingerprintService($text),
            new PromotionDeduplicator($promotionRepository),
            new DiscountMerchantResolver($merchantRepository, new DiscountMerchantService($merchantRepository), $text),
            new DiscountBenefitProgramResolver($benefitRepository, new DiscountBenefitProgramService($benefitRepository), $text),
            $promotionRepository,
        );
    }

    function discountCollectorDurationMs(int $startedNs): int
    {
        return (int) max(0, round((hrtime(true) - $startedNs) / 1_000_000));
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $basePath = dirname(__DIR__);
    $config = require $basePath . '/app/bootstrap.php';

    exit(runDiscountCollectorCommand($argv, new DiscountCollectorRegistry(), $config));
}
