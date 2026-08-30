<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\CollectedPromotion;
use Modules\Discounts\Collectors\CollectorContext;
use Modules\Discounts\Collectors\CollectorHttpException;
use Modules\Discounts\Collectors\CollectorParseException;
use Modules\Discounts\Collectors\CollectorResult;
use Modules\Discounts\Collectors\DiscountCollectorErrorSanitizer;
use Modules\Discounts\Collectors\DiscountCollectorInterface;
use Modules\Discounts\Collectors\DiscountCollectorLogger;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunRepository;
use Modules\Discounts\Collectors\DiscountCollectorRunService;
use Modules\Discounts\Collectors\DiscountCollectorScheduleRepository;
use Modules\Discounts\Collectors\DiscountCollectorScheduler;
use Modules\Discounts\Collectors\DiscountPromotionImporter;
use Modules\Discounts\Collectors\PromotionImportResult;

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
require_once dirname(__DIR__, 2) . '/workers/process-discount-collectors.php';
require_once dirname(__DIR__, 2) . '/workers/discount-collector-schedule.php';

function collector_runs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class RunsTestCollector implements DiscountCollectorInterface
{
    /**
     * @param array<int, CollectedPromotion> $items
     */
    public function __construct(private readonly string $key, private readonly array $items = [])
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return 'Runs test ' . $this->key;
    }

    public function collect(CollectorContext $context): array
    {
        return $this->items;
    }
}

final class RunsTestRunner implements DiscountCollectorRunService
{
    /**
     * @param array<string, CollectorResult|\Throwable> $results
     */
    public function __construct(private readonly array $results)
    {
    }

    public function run(string $collectorKey): CollectorResult
    {
        $result = $this->results[$collectorKey] ?? null;

        if ($result instanceof Throwable) {
            throw $result;
        }

        if ($result instanceof CollectorResult) {
            return $result;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return CollectorResult::ok($collectorKey, $now, $now, []);
    }
}

final class RunsTestImporter implements DiscountPromotionImporter
{
    public function __construct(private readonly PromotionImportResult|Throwable $result)
    {
    }

    public function import(CollectorResult $collectorResult, bool $dryRun = false): PromotionImportResult
    {
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

$pdo = Connection::get();
$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$config['collectors']['scheduler'] = [
    'default_interval_minutes' => 60,
    'min_interval_minutes' => 60,
    'retry_minutes' => 60,
    'stale_after_minutes' => 180,
];
$prefix = 'runs_' . bin2hex(random_bytes(4));
$logPath = dirname(__DIR__, 2) . '/storage/logs/discount-collectors-test-' . $prefix . '.log';
$exitCode = 1;

$key = static fn (string $suffix): string => $prefix . '_' . $suffix;
$now = static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
$setSchedule = static function (PDO $pdo, string $collectorKey, array $data): void {
    $sets = [];
    $params = ['collector_key' => $collectorKey];

    foreach ($data as $column => $value) {
        $sets[] = $column . ' = :' . $column;
        $params[$column] = $value;
    }

    $statement = $pdo->prepare(
        'UPDATE discount_collector_schedules SET ' . implode(', ', $sets) . ' WHERE collector_key = :collector_key'
    );
    $statement->execute($params);
};
$promotion = static fn (string $sourceKey): CollectedPromotion => new CollectedPromotion(
    sourceKey: $sourceKey,
    title: '25% descuento runs ' . $sourceKey,
    merchantName: 'Runs Merchant ' . $sourceKey,
    discountText: '25% descuento',
    channelRaw: 'online',
);

try {
    $pdo->prepare('DELETE FROM discount_collector_runs WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_collector_schedules WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);

    collector_runs_assert(DiscountCollectorErrorSanitizer::typeFromThrowable(new CollectorHttpException('Timeout al consultar la fuente.')) === 'timeout', 'Timeout was not categorized.');
    collector_runs_assert(DiscountCollectorErrorSanitizer::typeFromThrowable(new CollectorHttpException('HTTP 500.')) === 'http', 'HTTP error was not categorized.');
    collector_runs_assert(DiscountCollectorErrorSanitizer::typeFromThrowable(new CollectorParseException('HTML inesperado.')) === 'parse', 'Parse error was not categorized.');
    collector_runs_assert(DiscountCollectorErrorSanitizer::typeFromThrowable(new \PDOException('SQL password=hunter2')) === 'persistence', 'PDO error was not categorized.');
    collector_runs_assert(DiscountCollectorErrorSanitizer::typeFromThrowable(new RuntimeException('boom')) === 'unknown', 'Unknown error was not categorized.');
    $sanitized = DiscountCollectorErrorSanitizer::sanitize("Authorization: Bearer SECRET token=abc password=hunter2 \x1b[31m");
    collector_runs_assert(!str_contains($sanitized, 'SECRET') && !str_contains($sanitized, 'hunter2') && !str_contains($sanitized, "\x1b"), 'Secrets or control chars survived sanitization.');

    $runs = new DiscountCollectorRunRepository($pdo);
    $logger = new DiscountCollectorLogger($logPath);

    $successKey = $key('success');
    $successImport = new PromotionImportResult($successKey, 0, 0, 0, 0, 0, 0, [], [], false);
    $successScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        new DiscountCollectorRegistry([new RunsTestCollector($successKey)]),
        new RunsTestRunner([$successKey => CollectorResult::ok($successKey, $now(), $now(), [])]),
        new RunsTestImporter($successImport),
        $config['collectors']['scheduler'],
        $runs,
        $logger,
    );
    $successScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $successKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    $success = $successScheduler->processNextDue();
    $successRun = $runs->find((int) $success['run_id']);
    collector_runs_assert($successRun['trigger_type'] === 'scheduled' && $successRun['status'] === 'success', 'Scheduled success run was not persisted.');
    collector_runs_assert((int) $successRun['collected_count'] === 0 && (int) $successRun['duration_ms'] >= 0 && $successRun['completed_at'] !== null, 'Success run metrics/timestamps were not persisted.');

    $partialKey = $key('partial');
    $partialImport = new PromotionImportResult(
        $partialKey,
        20,
        20,
        5,
        13,
        2,
        1,
        ['fecha ambigua', 'benefit no resuelto', 'duplicate item'],
        ['item uno fallo', 'item dos fallo'],
        false,
    );
    $partialScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        new DiscountCollectorRegistry([new RunsTestCollector($partialKey)]),
        new RunsTestRunner([$partialKey => CollectorResult::ok($partialKey, $now(), $now(), [])]),
        new RunsTestImporter($partialImport),
        $config['collectors']['scheduler'],
        $runs,
        $logger,
    );
    $partialScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $partialKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    $partial = $partialScheduler->processNextDue();
    $partialRun = $runs->find((int) $partial['run_id']);
    $partialSchedule = (new DiscountCollectorScheduleRepository($pdo))->find($partialKey);
    collector_runs_assert($partial['status'] === 'partial' && $partialRun['status'] === 'partial' && $partialSchedule['last_status'] === 'partial', 'Partial run/schedule status was not persisted.');
    collector_runs_assert((int) $partialRun['collected_count'] === 20 && (int) $partialRun['created_count'] === 5 && (int) $partialRun['updated_count'] === 13 && (int) $partialRun['duplicate_count'] === 1, 'Partial metrics were not persisted.');
    collector_runs_assert((int) $partialRun['warning_count'] === 3 && (int) $partialRun['error_count'] === 2 && str_contains((string) $partialRun['warning_summary'], 'fecha ambigua'), 'Partial warning/error summary was not persisted.');

    $failedKey = $key('failed');
    $secretMessage = 'Authorization: Bearer SECRET token=abc password=hunter2 Timeout al consultar la fuente.';
    $failedScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        new DiscountCollectorRegistry([new RunsTestCollector($failedKey)]),
        new RunsTestRunner([$failedKey => CollectorResult::failed($failedKey, $now(), $now(), 'CollectorHttpException', $secretMessage)]),
        new RunsTestImporter(new PromotionImportResult($failedKey, 0, 0, 0, 0, 0, 0)),
        $config['collectors']['scheduler'],
        $runs,
        $logger,
    );
    $failedScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $failedKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    $failed = $failedScheduler->processNextDue();
    $failedRun = $runs->find((int) $failed['run_id']);
    collector_runs_assert($failedRun['status'] === 'failed' && $failedRun['error_type'] === 'timeout', 'Failed run was not categorized.');
    collector_runs_assert(!str_contains((string) $failedRun['error_message'], 'SECRET') && !str_contains((string) $failedRun['error_message'], 'hunter2'), 'Failed run stored secrets.');

    $persistenceKey = $key('persistfail');
    $persistenceScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        new DiscountCollectorRegistry([new RunsTestCollector($persistenceKey)]),
        new RunsTestRunner([$persistenceKey => CollectorResult::ok($persistenceKey, $now(), $now(), [])]),
        new RunsTestImporter(new \PDOException('SQLSTATE failed password=hunter2')),
        $config['collectors']['scheduler'],
        $runs,
        $logger,
    );
    $persistenceScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $persistenceKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    $persistence = $persistenceScheduler->processNextDue();
    $persistenceRun = $runs->find((int) $persistence['run_id']);
    collector_runs_assert($persistenceRun['status'] === 'failed' && $persistenceRun['error_type'] === 'persistence' && !str_contains((string) $persistenceRun['error_message'], 'hunter2'), 'Persistence failure was not sanitized/categorized.');

    $recentKey = $key('recent');
    $recentRegistry = new DiscountCollectorRegistry([new RunsTestCollector($recentKey)]);
    $recentScheduler = new DiscountCollectorScheduler(new DiscountCollectorScheduleRepository($pdo), $recentRegistry, new RunsTestRunner([]), new RunsTestImporter(new PromotionImportResult($recentKey, 0, 0, 0, 0, 0, 0)), $config['collectors']['scheduler'], $runs, $logger);
    $recentScheduler->ensureSchedulesExist();
    $recentRunId = $runs->start($recentKey, 'scheduled');
    $setSchedule($pdo, $recentKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'running', 'last_started_at' => gmdate('Y-m-d H:i:s')]);
    collector_runs_assert($recentScheduler->processNextDue()['status'] === 'idle' && $runs->find($recentRunId)['status'] === 'running', 'Recent running run was incorrectly interrupted.');

    $staleKey = $key('stale');
    $staleScheduler = new DiscountCollectorScheduler(new DiscountCollectorScheduleRepository($pdo), new DiscountCollectorRegistry([new RunsTestCollector($staleKey)]), new RunsTestRunner([]), new RunsTestImporter(new PromotionImportResult($staleKey, 0, 0, 0, 0, 0, 0)), $config['collectors']['scheduler'], $runs, $logger);
    $staleScheduler->ensureSchedulesExist();
    $staleRunId = $runs->start($staleKey, 'scheduled');
    $pdo->prepare("UPDATE discount_collector_runs SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 HOUR) WHERE id = :id")->execute(['id' => $staleRunId]);
    $setSchedule($pdo, $staleKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'running', 'last_started_at' => gmdate('Y-m-d H:i:s', time() - 4 * 3600)]);
    collector_runs_assert($staleScheduler->processNextDue()['status'] === 'success' && $runs->find($staleRunId)['error_type'] === 'interrupted', 'Stale running run was not marked interrupted.');

    $manualKey = $key('manual');
    ob_start();
    $manualCode = runDiscountCollectorCommand(['workers/run-discount-collector.php', $manualKey], new DiscountCollectorRegistry([new RunsTestCollector($manualKey)]), $config, null, null, $runs, $logger);
    ob_end_clean();
    collector_runs_assert($manualCode === 0 && $runs->latestForCollector($manualKey)['trigger_type'] === 'manual', 'Manual run was not recorded.');

    $dryKey = $key('dry');
    $drySourceKey = $prefix . '-dry-promo';
    ob_start();
    $dryCode = runDiscountCollectorCommand(['workers/run-discount-collector.php', $dryKey, '--dry-run'], new DiscountCollectorRegistry([new RunsTestCollector($dryKey, [$promotion($drySourceKey)])]), $config, null, null, $runs, $logger);
    $dryOutput = (string) ob_get_clean();
    $dryRun = $runs->latestForCollector($dryKey);
    collector_runs_assert($dryCode === 0 && str_contains($dryOutput, 'DRY RUN') && $dryRun['trigger_type'] === 'dry_run', 'Dry-run was not recorded.');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM discount_promotions WHERE collector_key = :collector_key AND source_key = :source_key');
    $statement->execute(['collector_key' => $dryKey, 'source_key' => $drySourceKey]);
    collector_runs_assert((int) $statement->fetchColumn() === 0, 'Dry-run persisted commercial data.');

    ob_start();
    discountCollectorScheduleCommand(['workers/discount-collector-schedule.php', 'status'], new DiscountCollectorRegistry([new RunsTestCollector($successKey)]), $config);
    $statusOutput = (string) ob_get_clean();
    collector_runs_assert(str_contains($statusOutput, 'Last run') && str_contains($statusOutput, $successKey), 'Scheduler status CLI did not show last run.');

    ob_start();
    discountCollectorScheduleCommand(['workers/discount-collector-schedule.php', 'runs', '--collector=' . $partialKey], new DiscountCollectorRegistry([]), $config);
    $runsOutput = (string) ob_get_clean();
    collector_runs_assert(str_contains($runsOutput, 'ID') && str_contains($runsOutput, $partialKey), 'Runs CLI did not list filtered collector.');

    ob_start();
    discountCollectorScheduleCommand(['workers/discount-collector-schedule.php', 'run', '--run-id=' . (int) $partialRun['id']], new DiscountCollectorRegistry([]), $config);
    $runOutput = (string) ob_get_clean();
    collector_runs_assert(str_contains($runOutput, 'Status: partial') && str_contains($runOutput, 'Warning summary'), 'Run detail CLI did not show details.');

    ob_start();
    $missingRunCode = discountCollectorScheduleCommand(['workers/discount-collector-schedule.php', 'run', '--run-id=999999999'], new DiscountCollectorRegistry([]), $config);
    $missingRunOutput = (string) ob_get_clean();
    collector_runs_assert($missingRunCode === 1 && str_contains($missingRunOutput, 'not found'), 'Missing run CLI was not handled.');

    $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    collector_runs_assert(str_contains($log, 'status=success') && str_contains($log, 'status=failed') && str_contains($log, 'duration_ms='), 'Collector log did not contain compact success/failure lines.');
    collector_runs_assert(!str_contains($log, 'SECRET') && !str_contains($log, 'hunter2') && !str_contains($log, '<html>'), 'Collector log leaked secrets or raw content.');

    echo "Discount collector runs: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discount collector runs: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_file($logPath)) {
        unlink($logPath);
    }

    $pdo->prepare('DELETE FROM discount_promotions WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_merchants WHERE normalized_name LIKE :prefix')
        ->execute(['prefix' => 'runs_merchant_' . $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_collector_runs WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_collector_schedules WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
}

exit($exitCode);
