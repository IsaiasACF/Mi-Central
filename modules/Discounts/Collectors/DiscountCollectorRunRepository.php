<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use PDO;

final class DiscountCollectorRunRepository
{
    private const COLUMNS = 'id, collector_key, trigger_type, status, started_at, completed_at, duration_ms, collected_count, normalized_count, created_count, updated_count, duplicate_count, skipped_count, warning_count, error_count, error_type, error_message, warning_summary, error_summary, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(string $collectorKey, string $triggerType): int
    {
        $this->assertTrigger($triggerType);
        $statement = $this->pdo->prepare(
            "INSERT INTO discount_collector_runs (
                collector_key,
                trigger_type,
                status,
                started_at
            ) VALUES (
                :collector_key,
                :trigger_type,
                'running',
                UTC_TIMESTAMP()
            )"
        );
        $statement->execute([
            'collector_key' => $collectorKey,
            'trigger_type' => $triggerType,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completeWithImport(int $runId, string $status, PromotionImportResult $import, int $durationMs, ?string $errorType = null, ?string $errorMessage = null): void
    {
        $this->assertStatus($status);
        $statement = $this->pdo->prepare(
            "UPDATE discount_collector_runs
             SET status = :status,
                 completed_at = UTC_TIMESTAMP(),
                 duration_ms = :duration_ms,
                 collected_count = :collected_count,
                 normalized_count = :normalized_count,
                 created_count = :created_count,
                 updated_count = :updated_count,
                 duplicate_count = :duplicate_count,
                 skipped_count = :skipped_count,
                 warning_count = :warning_count,
                 error_count = :error_count,
                 error_type = :error_type,
                 error_message = :error_message,
                 warning_summary = :warning_summary,
                 error_summary = :error_summary
             WHERE id = :id AND status = 'running'"
        );
        $statement->execute([
            'status' => $status,
            'duration_ms' => max(0, $durationMs),
            'collected_count' => $import->collectedCount(),
            'normalized_count' => $import->normalizedCount(),
            'created_count' => $import->createdCount(),
            'updated_count' => $import->updatedCount(),
            'duplicate_count' => $import->duplicateCount(),
            'skipped_count' => $import->skippedCount(),
            'warning_count' => $import->warningCount(),
            'error_count' => $import->errorCount(),
            'error_type' => $errorType,
            'error_message' => $errorMessage === null ? null : DiscountCollectorErrorSanitizer::sanitize($errorMessage),
            'warning_summary' => DiscountCollectorErrorSanitizer::summarize($import->warnings()),
            'error_summary' => DiscountCollectorErrorSanitizer::summarize($import->errors()),
            'id' => $runId,
        ]);
    }

    public function fail(int $runId, string $errorType, string $errorMessage, int $durationMs, int $collectedCount = 0): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE discount_collector_runs
             SET status = 'failed',
                 completed_at = UTC_TIMESTAMP(),
                 duration_ms = :duration_ms,
                 collected_count = :collected_count,
                 error_count = 1,
                 error_type = :error_type,
                 error_message = :error_message,
                 error_summary = :error_summary
             WHERE id = :id AND status = 'running'"
        );
        $safeMessage = DiscountCollectorErrorSanitizer::sanitize($errorMessage);
        $statement->execute([
            'duration_ms' => max(0, $durationMs),
            'collected_count' => max(0, $collectedCount),
            'error_type' => $this->validErrorType($errorType),
            'error_message' => $safeMessage,
            'error_summary' => $safeMessage,
            'id' => $runId,
        ]);
    }

    public function markStaleInterrupted(string $collectorKey, int $staleAfterMinutes): int
    {
        $staleAfterMinutes = max(1, $staleAfterMinutes);
        $message = 'La ejecucion fue interrumpida antes de finalizar.';
        $statement = $this->pdo->prepare(
            "UPDATE discount_collector_runs
             SET status = 'failed',
                 completed_at = UTC_TIMESTAMP(),
                 duration_ms = GREATEST(0, FLOOR(TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP()) / 1000)),
                 error_count = IF(error_count = 0, 1, error_count),
                 error_type = 'interrupted',
                 error_message = :error_message,
                 error_summary = :error_summary
             WHERE collector_key = :collector_key
               AND status = 'running'
               AND started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$staleAfterMinutes} MINUTE)"
        );
        $statement->execute([
            'collector_key' => $collectorKey,
            'error_message' => $message,
            'error_summary' => $message,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $runId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_collector_runs
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $runId]);
        $run = $statement->fetch();

        return is_array($run) ? $run : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForCollector(string $collectorKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_collector_runs
             WHERE collector_key = :collector_key
             ORDER BY started_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['collector_key' => $collectorKey]);
        $run = $statement->fetch();

        return is_array($run) ? $run : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20, ?string $collectorKey = null): array
    {
        $limit = max(1, min(100, $limit));
        $where = '';
        $params = [];

        if ($collectorKey !== null && $collectorKey !== '') {
            $where = 'WHERE collector_key = :collector_key';
            $params['collector_key'] = $collectorKey;
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . "
             FROM discount_collector_runs
             {$where}
             ORDER BY started_at DESC, id DESC
             LIMIT {$limit}"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function assertTrigger(string $triggerType): void
    {
        if (!in_array($triggerType, ['scheduled', 'manual', 'dry_run'], true)) {
            throw new CollectorConfigurationException('Trigger de collector invalido.');
        }
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, ['running', 'success', 'partial', 'failed'], true)) {
            throw new CollectorConfigurationException('Estado de run invalido.');
        }
    }

    private function validErrorType(string $errorType): string
    {
        return in_array($errorType, ['configuration', 'http', 'timeout', 'parse', 'normalization', 'validation', 'persistence', 'interrupted', 'unknown'], true)
            ? $errorType
            : 'unknown';
    }
}
