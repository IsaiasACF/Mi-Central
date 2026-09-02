<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Throwable;

final class DiscountCollectorScheduler
{
    private int $defaultIntervalMinutes;
    private int $minIntervalMinutes;
    private int $retryMinutes;
    private int $staleAfterMinutes;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly DiscountCollectorScheduleRepository $schedules,
        private readonly DiscountCollectorRegistry $registry,
        private readonly DiscountCollectorRunService $runner,
        private readonly DiscountPromotionImporter $pipeline,
        array $config = [],
        private readonly ?DiscountCollectorRunRepository $runs = null,
        private readonly ?DiscountCollectorLogger $logger = null,
    ) {
        $this->minIntervalMinutes = max(1, (int) ($config['min_interval_minutes'] ?? 60));
        $this->defaultIntervalMinutes = $this->validInterval((int) ($config['default_interval_minutes'] ?? 1440));
        $this->retryMinutes = $this->validInterval((int) ($config['retry_minutes'] ?? 60));
        $this->staleAfterMinutes = max(1, (int) ($config['stale_after_minutes'] ?? 180));
    }

    /**
     * @return array<string, mixed>
     */
    public function processNextDue(): array
    {
        $registeredKeys = array_keys($this->registry->all());

        if ($registeredKeys === []) {
            $this->schedules->markUnregistered([]);

            return [
                'status' => 'no_collectors',
                'message' => 'No collectors registered.',
                'collector_key' => null,
                'import' => null,
            ];
        }

        $this->ensureSchedulesExist();
        $this->schedules->markUnregistered($registeredKeys);
        $schedule = $this->schedules->claimNextDue($registeredKeys, $this->staleAfterMinutes);

        if ($schedule === null) {
            return [
                'status' => 'idle',
                'message' => 'No collectors due.',
                'collector_key' => null,
                'import' => null,
            ];
        }

        $collectorKey = (string) $schedule['collector_key'];
        $intervalMinutes = (int) $schedule['interval_minutes'];
        $this->recoverStaleRun($schedule, $collectorKey);
        $runId = $this->runs?->start($collectorKey, 'scheduled');
        $startedNs = hrtime(true);

        try {
            $result = $this->runner->run($collectorKey);
            $durationMs = $this->durationMs($startedNs);

            if (!$result->success()) {
                $errorType = DiscountCollectorErrorSanitizer::typeFromName($result->errorType(), $result->errorMessage());
                $message = $result->errorMessage() ?? 'Collector failed.';
                $this->runs?->fail($runId ?? 0, $errorType, $message, $durationMs, count($result->items()));
                $this->schedules->markFailed($collectorKey, $this->retryMinutes);
                $this->logRun($runId);

                return [
                    'status' => 'failed',
                    'message' => DiscountCollectorErrorSanitizer::sanitize($message, 240),
                    'collector_key' => $collectorKey,
                    'import' => null,
                    'run_id' => $runId,
                ];
            }

            $import = $this->pipeline->import($result, false);
            $durationMs = $this->durationMs($startedNs);

            if ($import->errorCount() > 0) {
                $this->runs?->completeWithImport($runId ?? 0, 'partial', $import, $durationMs, 'normalization', 'Import completed with item errors.');
                $this->schedules->markPartial($collectorKey, $intervalMinutes);
                $this->logRun($runId);

                return [
                    'status' => 'partial',
                    'message' => 'Collector processed with item errors.',
                    'collector_key' => $collectorKey,
                    'import' => $import,
                    'run_id' => $runId,
                ];
            }

            $this->runs?->completeWithImport($runId ?? 0, 'success', $import, $durationMs);
            $this->schedules->markSuccess($collectorKey, $intervalMinutes);
            $this->logRun($runId);

            return [
                'status' => 'success',
                'message' => 'Collector processed.',
                'collector_key' => $collectorKey,
                'import' => $import,
                'run_id' => $runId,
            ];
        } catch (Throwable $exception) {
            $durationMs = $this->durationMs($startedNs);
            $this->runs?->fail(
                $runId ?? 0,
                DiscountCollectorErrorSanitizer::typeFromThrowable($exception),
                $exception->getMessage(),
                $durationMs,
            );
            $this->schedules->markFailed($collectorKey, $this->retryMinutes);
            $this->logRun($runId);

            return [
                'status' => 'failed',
                'message' => DiscountCollectorErrorSanitizer::sanitize($exception->getMessage(), 240),
                'collector_key' => $collectorKey,
                'import' => null,
                'run_id' => $runId,
            ];
        }
    }

    public function ensureSchedulesExist(): int
    {
        $created = 0;

        foreach (array_keys($this->registry->all()) as $collectorKey) {
            if ($this->schedules->ensureForCollector($collectorKey, $this->defaultIntervalMinutes, true)) {
                $created++;
            }
        }

        return $created;
    }

    public function setEnabled(string $collectorKey, bool $enabled): bool
    {
        return $this->schedules->setEnabled($collectorKey, $enabled);
    }

    public function setInterval(string $collectorKey, int $intervalMinutes): bool
    {
        return $this->schedules->setInterval($collectorKey, $this->validInterval($intervalMinutes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusRows(): array
    {
        $this->ensureSchedulesExist();
        $this->schedules->markUnregistered(array_keys($this->registry->all()));
        $registered = $this->registry->all();

        return array_map(static function (array $schedule) use ($registered): array {
            $schedule['registered'] = isset($registered[(string) $schedule['collector_key']]);

            return $schedule;
        }, $this->schedules->all());
    }

    public function validInterval(int $minutes): int
    {
        if ($minutes < $this->minIntervalMinutes) {
            throw new CollectorConfigurationException(
                'Intervalo invalido. Debe ser al menos ' . $this->minIntervalMinutes . ' minutos.',
            );
        }

        return $minutes;
    }

    public function minIntervalMinutes(): int
    {
        return $this->minIntervalMinutes;
    }

    private function recoverStaleRun(array $schedule, string $collectorKey): void
    {
        if (!empty($schedule['was_stale'])) {
            $this->runs?->markStaleInterrupted($collectorKey, $this->staleAfterMinutes);
        }
    }

    private function durationMs(int $startedNs): int
    {
        return (int) max(0, round((hrtime(true) - $startedNs) / 1_000_000));
    }

    private function logRun(?int $runId): void
    {
        if ($runId === null || $this->runs === null || $this->logger === null) {
            return;
        }

        $run = $this->runs->find($runId);

        if ($run !== null) {
            $this->logger->logRun($run);
        }
    }
}
