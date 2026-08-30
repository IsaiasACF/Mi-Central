<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use PDO;
use RuntimeException;

final class DiscountCollectorScheduleRepository
{
    private const COLUMNS = 'id, collector_key, enabled, interval_minutes, next_run_at, last_started_at, last_completed_at, last_status, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $started = !$this->pdo->inTransaction();

        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($started) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function ensureForCollector(string $collectorKey, int $intervalMinutes, bool $enabled = true): bool
    {
        $this->assertCollectorKey($collectorKey);
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO discount_collector_schedules (
                collector_key,
                enabled,
                interval_minutes,
                next_run_at,
                last_status
            ) VALUES (
                :collector_key,
                :enabled,
                :interval_minutes,
                UTC_TIMESTAMP(),
                'never'
            )"
        );
        $statement->execute([
            'collector_key' => $collectorKey,
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $intervalMinutes,
        ]);

        if ($statement->rowCount() === 1) {
            return true;
        }

        $reactivate = $this->pdo->prepare(
            "UPDATE discount_collector_schedules
             SET last_status = 'never',
                 next_run_at = COALESCE(next_run_at, UTC_TIMESTAMP())
             WHERE collector_key = :collector_key
               AND last_status = 'not_registered'"
        );
        $reactivate->execute(['collector_key' => $collectorKey]);

        return false;
    }

    /**
     * @param array<int, string> $collectorKeys
     */
    public function markUnregistered(array $collectorKeys): int
    {
        $collectorKeys = $this->validUniqueKeys($collectorKeys);

        if ($collectorKeys === []) {
            $statement = $this->pdo->prepare(
                "UPDATE discount_collector_schedules
                 SET last_status = 'not_registered'
                 WHERE last_status <> 'not_registered'"
            );
            $statement->execute();

            return $statement->rowCount();
        }

        $placeholders = implode(',', array_fill(0, count($collectorKeys), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE discount_collector_schedules
             SET last_status = 'not_registered'
             WHERE collector_key NOT IN ({$placeholders})
               AND last_status <> 'not_registered'"
        );
        $statement->execute($collectorKeys);

        return $statement->rowCount();
    }

    /**
     * @param array<int, string> $collectorKeys
     * @return array<string, mixed>|null
     */
    public function claimNextDue(array $collectorKeys, int $staleAfterMinutes): ?array
    {
        $collectorKeys = $this->validUniqueKeys($collectorKeys);

        if ($collectorKeys === []) {
            return null;
        }

        $staleAfterMinutes = max(1, $staleAfterMinutes);
        $placeholders = implode(',', array_fill(0, count($collectorKeys), '?'));

        return $this->transaction(function () use ($collectorKeys, $placeholders, $staleAfterMinutes): ?array {
            $statement = $this->pdo->prepare(
                "SELECT " . self::COLUMNS . "
                 FROM discount_collector_schedules
                 WHERE collector_key IN ({$placeholders})
                   AND enabled = 1
                   AND (next_run_at IS NULL OR next_run_at <= UTC_TIMESTAMP())
                   AND (
                       last_status <> 'running'
                       OR last_started_at IS NULL
                       OR last_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$staleAfterMinutes} MINUTE)
                   )
                 ORDER BY COALESCE(next_run_at, '1970-01-01 00:00:00') ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $statement->execute($collectorKeys);
            $schedule = $statement->fetch();

            if (!is_array($schedule)) {
                return null;
            }

            $wasStale = (string) ($schedule['last_status'] ?? '') === 'running';
            $update = $this->pdo->prepare(
                "UPDATE discount_collector_schedules
                 SET last_status = 'running',
                     last_started_at = UTC_TIMESTAMP(),
                     next_run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$staleAfterMinutes} MINUTE)
                 WHERE id = :id
                   AND enabled = 1
                   AND (
                       last_status <> 'running'
                       OR last_started_at IS NULL
                       OR last_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$staleAfterMinutes} MINUTE)
                   )"
            );
            $update->execute(['id' => (int) $schedule['id']]);

            if ($update->rowCount() !== 1) {
                return null;
            }

            $claimed = $this->find((string) $schedule['collector_key']);

            if ($claimed !== null) {
                $claimed['was_stale'] = $wasStale;
            }

            return $claimed;
        });
    }

    public function markSuccess(string $collectorKey, int $intervalMinutes): void
    {
        $this->complete($collectorKey, 'success', $intervalMinutes);
    }

    public function markFailed(string $collectorKey, int $retryMinutes): void
    {
        $this->complete($collectorKey, 'failed', $retryMinutes);
    }

    public function markPartial(string $collectorKey, int $intervalMinutes): void
    {
        $this->complete($collectorKey, 'partial', $intervalMinutes);
    }

    public function setEnabled(string $collectorKey, bool $enabled): bool
    {
        $this->assertCollectorKey($collectorKey);
        $statement = $this->pdo->prepare(
            'UPDATE discount_collector_schedules SET enabled = :enabled WHERE collector_key = :collector_key'
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'collector_key' => $collectorKey,
        ]);

        return $statement->rowCount() === 1;
    }

    public function setInterval(string $collectorKey, int $intervalMinutes): bool
    {
        $this->assertCollectorKey($collectorKey);
        $statement = $this->pdo->prepare(
            'UPDATE discount_collector_schedules SET interval_minutes = :interval_minutes WHERE collector_key = :collector_key'
        );
        $statement->execute([
            'interval_minutes' => $intervalMinutes,
            'collector_key' => $collectorKey,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . '
             FROM discount_collector_schedules
             ORDER BY collector_key ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $collectorKey): ?array
    {
        $this->assertCollectorKey($collectorKey);
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_collector_schedules
             WHERE collector_key = :collector_key
             LIMIT 1'
        );
        $statement->execute(['collector_key' => $collectorKey]);
        $schedule = $statement->fetch();

        return is_array($schedule) ? $schedule : null;
    }

    private function complete(string $collectorKey, string $status, int $delayMinutes): void
    {
        $this->assertCollectorKey($collectorKey);
        $delayMinutes = max(1, $delayMinutes);
        $statement = $this->pdo->prepare(
            "UPDATE discount_collector_schedules
             SET last_status = :last_status,
                 last_completed_at = UTC_TIMESTAMP(),
                 next_run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$delayMinutes} MINUTE)
             WHERE collector_key = :collector_key"
        );
        $statement->execute([
            'last_status' => $status,
            'collector_key' => $collectorKey,
        ]);
    }

    private function assertCollectorKey(string $collectorKey): void
    {
        if (!DiscountCollectorRegistry::isValidKey($collectorKey)) {
            throw new RuntimeException('Collector key invalida.');
        }
    }

    /**
     * @param array<int, string> $collectorKeys
     * @return array<int, string>
     */
    private function validUniqueKeys(array $collectorKeys): array
    {
        $keys = [];

        foreach ($collectorKeys as $collectorKey) {
            if (DiscountCollectorRegistry::isValidKey($collectorKey)) {
                $keys[$collectorKey] = $collectorKey;
            }
        }

        return array_values($keys);
    }
}
