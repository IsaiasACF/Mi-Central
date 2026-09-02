<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\CollectedPromotion;
use Modules\Discounts\Collectors\CollectorContext;
use Modules\Discounts\Collectors\CollectorResult;
use Modules\Discounts\Collectors\DiscountCollectorInterface;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunService;
use Modules\Discounts\Collectors\DiscountCollectorScheduleRepository;
use Modules\Discounts\Collectors\DiscountCollectorScheduler;
use Modules\Discounts\Collectors\DiscountPromotionImporter;
use Modules\Discounts\Collectors\PromotionImportResult;

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
require_once dirname(__DIR__, 2) . '/workers/process-discount-collectors.php';

function discount_scheduler_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SchedulerTestCollector implements DiscountCollectorInterface
{
    /**
     * @param array<int, CollectedPromotion> $items
     */
    public function __construct(
        private readonly string $key,
        private readonly array $items = [],
        private readonly bool $fail = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return 'Scheduler test ' . $this->key;
    }

    public function collect(CollectorContext $context): array
    {
        if ($this->fail) {
            throw new \Modules\Discounts\Collectors\CollectorException('Collector failed from fixture.');
        }

        return $this->items;
    }
}

final class SchedulerTestRunner implements DiscountCollectorRunService
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

final class SchedulerTestImporter implements DiscountPromotionImporter
{
    public int $calls = 0;
    public bool $lastDryRun = true;

    public function __construct(
        private readonly bool $throw = false,
        private readonly bool $withErrors = false,
    ) {
    }

    public function import(CollectorResult $collectorResult, bool $dryRun = false): PromotionImportResult
    {
        $this->calls++;
        $this->lastDryRun = $dryRun;

        if ($this->throw) {
            throw new RuntimeException('Pipeline failed from fixture.');
        }

        return new PromotionImportResult(
            $collectorResult->collectorKey(),
            count($collectorResult->items()),
            count($collectorResult->items()),
            0,
            0,
            0,
            0,
            [],
            $this->withErrors ? ['Pipeline item error.'] : [],
            $dryRun,
        );
    }
}

$pdo = Connection::get();
$prefix = 'sched_' . bin2hex(random_bytes(4));
$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$config['collectors']['scheduler'] = [
    'default_interval_minutes' => 60,
    'min_interval_minutes' => 60,
    'retry_minutes' => 60,
    'stale_after_minutes' => 180,
];
$exitCode = 1;

$key = static fn (string $suffix): string => $prefix . '_' . $suffix;
$promotion = static fn (string $sourceKey): CollectedPromotion => new CollectedPromotion(
    sourceKey: $sourceKey,
    title: '20% descuento scheduler ' . $sourceKey,
    merchantName: 'Scheduler Merchant ' . $sourceKey,
    discountText: '20% descuento',
    channelRaw: 'online',
);
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

try {
    $pdo->prepare('DELETE FROM discount_collector_schedules WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);

    ob_start();
    $emptyCode = processDiscountCollectorsCommand(new DiscountCollectorRegistry([]), $config);
    $emptyOutput = (string) ob_get_clean();
    discount_scheduler_assert($emptyCode === 0 && str_contains($emptyOutput, 'No collectors registered.'), 'Empty registry did not exit cleanly.');

    $dueKey = $key('due');
    $futureKey = $key('future');
    $disabledKey = $key('disabled');
    $registry = new DiscountCollectorRegistry([
        new SchedulerTestCollector($dueKey),
        new SchedulerTestCollector($futureKey),
        new SchedulerTestCollector($disabledKey),
    ]);
    $repo = new DiscountCollectorScheduleRepository($pdo);
    $runner = new SchedulerTestRunner([]);
    $importer = new SchedulerTestImporter();
    $scheduler = new DiscountCollectorScheduler($repo, $registry, $runner, $importer, $config['collectors']['scheduler']);

    discount_scheduler_assert($scheduler->ensureSchedulesExist() === 3, 'New collectors did not get schedules.');
    discount_scheduler_assert($scheduler->ensureSchedulesExist() === 0, 'Schedule sync duplicated rows.');
    discount_scheduler_assert($repo->find($dueKey) !== null, 'Schedule row was not persisted.');

    try {
        $scheduler->setInterval($dueKey, 30);
        throw new RuntimeException('Interval below minimum was accepted.');
    } catch (\Modules\Discounts\Collectors\CollectorConfigurationException) {
    }

    discount_scheduler_assert($scheduler->setInterval($dueKey, 120), 'Valid interval was rejected.');
    discount_scheduler_assert((int) $repo->find($dueKey)['interval_minutes'] === 120, 'Valid interval was not persisted.');

    $setSchedule($pdo, $futureKey, [
        'next_run_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'last_status' => 'never',
        'enabled' => 1,
    ]);
    $setSchedule($pdo, $disabledKey, [
        'next_run_at' => gmdate('Y-m-d H:i:s', time() - 60),
        'last_status' => 'never',
        'enabled' => 0,
    ]);

    $futureClaim = $repo->claimNextDue([$futureKey], 180);
    discount_scheduler_assert($futureClaim === null, 'Future collector was claimed.');
    $disabledClaim = $repo->claimNextDue([$disabledKey], 180);
    discount_scheduler_assert($disabledClaim === null, 'Disabled collector was claimed.');

    $setSchedule($pdo, $dueKey, [
        'next_run_at' => gmdate('Y-m-d H:i:s', time() - 60),
        'last_status' => 'never',
        'enabled' => 1,
    ]);
    $dueClaim = $repo->claimNextDue([$dueKey], 180);
    discount_scheduler_assert(is_array($dueClaim) && $dueClaim['collector_key'] === $dueKey && $dueClaim['last_status'] === 'running', 'Due collector was not claimed.');
    discount_scheduler_assert($repo->claimNextDue([$dueKey], 180) === null, 'Recent running collector was claimed twice.');

    $setSchedule($pdo, $dueKey, [
        'next_run_at' => gmdate('Y-m-d H:i:s', time() - 60),
        'last_status' => 'running',
        'last_started_at' => gmdate('Y-m-d H:i:s', time() - 4 * 3600),
    ]);
    $staleClaim = $repo->claimNextDue([$dueKey], 180);
    discount_scheduler_assert(is_array($staleClaim) && $staleClaim['collector_key'] === $dueKey, 'Stale running collector was not recovered.');

    $successKey = $key('success');
    $successRegistry = new DiscountCollectorRegistry([new SchedulerTestCollector($successKey)]);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $successImporter = new SchedulerTestImporter();
    $successScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        $successRegistry,
        new SchedulerTestRunner([$successKey => CollectorResult::ok($successKey, $now, $now, [])]),
        $successImporter,
        $config['collectors']['scheduler'],
    );
    $successScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $successKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    $success = $successScheduler->processNextDue();
    $successRow = (new DiscountCollectorScheduleRepository($pdo))->find($successKey);
    discount_scheduler_assert($success['status'] === 'success' && $successImporter->calls === 1 && !$successImporter->lastDryRun, 'Due collector was not processed successfully through pipeline.');
    discount_scheduler_assert((string) $successRow['last_status'] === 'success' && (string) $successRow['next_run_at'] > gmdate('Y-m-d H:i:s'), 'Success did not schedule the next run.');
    discount_scheduler_assert($successScheduler->processNextDue()['status'] === 'idle', 'Future collector was processed too early.');

    $collectorFailKey = $key('collectorfail');
    $collectorFailRegistry = new DiscountCollectorRegistry([new SchedulerTestCollector($collectorFailKey)]);
    $collectorFailScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        $collectorFailRegistry,
        new SchedulerTestRunner([$collectorFailKey => CollectorResult::failed($collectorFailKey, $now, $now, 'Fixture', 'Collector fixture failed.')]),
        new SchedulerTestImporter(),
        $config['collectors']['scheduler'],
    );
    $collectorFailScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $collectorFailKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    discount_scheduler_assert($collectorFailScheduler->processNextDue()['status'] === 'failed', 'Collector failure was not marked failed.');
    discount_scheduler_assert((string) (new DiscountCollectorScheduleRepository($pdo))->find($collectorFailKey)['last_status'] === 'failed', 'Collector failure state was not persisted.');

    $pipelineFailKey = $key('pipefail');
    $pipelineFailRegistry = new DiscountCollectorRegistry([new SchedulerTestCollector($pipelineFailKey)]);
    $pipelineFailScheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        $pipelineFailRegistry,
        new SchedulerTestRunner([$pipelineFailKey => CollectorResult::ok($pipelineFailKey, $now, $now, [])]),
        new SchedulerTestImporter(throw: true),
        $config['collectors']['scheduler'],
    );
    $pipelineFailScheduler->ensureSchedulesExist();
    $setSchedule($pdo, $pipelineFailKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    discount_scheduler_assert($pipelineFailScheduler->processNextDue()['status'] === 'failed', 'Pipeline exception was not handled.');
    discount_scheduler_assert((string) (new DiscountCollectorScheduleRepository($pdo))->find($pipelineFailKey)['last_status'] === 'failed', 'Pipeline failure state was not persisted.');

    $orphanKey = $key('orphan');
    $repo->ensureForCollector($orphanKey, 60, true);
    $statusRows = $successScheduler->statusRows();
    $orphanRows = array_values(array_filter($statusRows, static fn (array $row): bool => $row['collector_key'] === $orphanKey));
    discount_scheduler_assert($orphanRows !== [] && $orphanRows[0]['registered'] === false && $orphanRows[0]['last_status'] === 'not_registered', 'Unregistered schedule was not reported.');

    $persistedNextRun = (string) $successRow['next_run_at'];
    $newRepo = new DiscountCollectorScheduleRepository($pdo);
    discount_scheduler_assert((string) $newRepo->find($successKey)['next_run_at'] === $persistedNextRun, 'Persisted next_run_at was not preserved across scheduler instances.');

    $actualKey = $key('actual');
    $actualSourceKey = $prefix . '-actual-promo';
    $actualRegistry = new DiscountCollectorRegistry([
        new SchedulerTestCollector($actualKey, [$promotion($actualSourceKey)]),
    ]);
    $actualRepo = new DiscountCollectorScheduleRepository($pdo);
    $actualRepo->ensureForCollector($actualKey, 60, true);
    $setSchedule($pdo, $actualKey, ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_status' => 'never']);
    ob_start();
    $workerCode = processDiscountCollectorsCommand($actualRegistry, $config);
    $workerOutput = (string) ob_get_clean();
    discount_scheduler_assert($workerCode === 0 && str_contains($workerOutput, 'Status: success') && str_contains($workerOutput, 'Created: 1'), 'Worker scheduler did not import a due collector promotion.');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM discount_promotions WHERE collector_key = :collector_key AND source_key = :source_key');
    $statement->execute(['collector_key' => $actualKey, 'source_key' => $actualSourceKey]);
    discount_scheduler_assert((int) $statement->fetchColumn() === 1, 'Scheduler did not persist collector promotion through import pipeline.');

    $manualKey = $key('manual');
    $manualSourceKey = $prefix . '-manual-dry-run';
    $manualRegistry = new DiscountCollectorRegistry([
        new SchedulerTestCollector($manualKey, [$promotion($manualSourceKey)]),
    ]);
    $repo->ensureForCollector($manualKey, 60, true);
    $manualBefore = (string) $repo->find($manualKey)['next_run_at'];
    ob_start();
    $manualCode = runDiscountCollectorCommand(['workers/run-discount-collector.php', $manualKey, '--dry-run'], $manualRegistry, $config);
    $manualOutput = (string) ob_get_clean();
    discount_scheduler_assert($manualCode === 0 && str_contains($manualOutput, 'DRY RUN'), 'Manual collector dry-run regressed.');
    discount_scheduler_assert((string) $repo->find($manualKey)['next_run_at'] === $manualBefore, 'Manual run changed scheduler next_run_at.');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM discount_promotions WHERE collector_key = :collector_key AND source_key = :source_key');
    $statement->execute(['collector_key' => $manualKey, 'source_key' => $manualSourceKey]);
    discount_scheduler_assert((int) $statement->fetchColumn() === 0, 'Manual dry-run persisted a promotion.');

    echo "Discount collector scheduler: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discount collector scheduler: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    $pdo->prepare('DELETE FROM discount_promotions WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_merchants WHERE normalized_name LIKE :prefix')
        ->execute(['prefix' => 'scheduler_merchant_' . $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_collector_schedules WHERE collector_key LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
}

exit($exitCode);
