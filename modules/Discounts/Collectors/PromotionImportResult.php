<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class PromotionImportResult
{
    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $errors
     */
    public function __construct(
        private readonly string $collectorKey,
        private readonly int $collectedCount,
        private readonly int $normalizedCount,
        private readonly int $createdCount,
        private readonly int $updatedCount,
        private readonly int $skippedCount,
        private readonly int $duplicateCount,
        private readonly array $warnings = [],
        private readonly array $errors = [],
        private readonly bool $dryRun = false,
    ) {
    }

    public function collectorKey(): string
    {
        return $this->collectorKey;
    }

    public function collectedCount(): int
    {
        return $this->collectedCount;
    }

    public function normalizedCount(): int
    {
        return $this->normalizedCount;
    }

    public function createdCount(): int
    {
        return $this->createdCount;
    }

    public function updatedCount(): int
    {
        return $this->updatedCount;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    public function duplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function warningCount(): int
    {
        return count($this->warnings);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collector_key' => $this->collectorKey,
            'collected_count' => $this->collectedCount,
            'normalized_count' => $this->normalizedCount,
            'created_count' => $this->createdCount,
            'updated_count' => $this->updatedCount,
            'skipped_count' => $this->skippedCount,
            'duplicate_count' => $this->duplicateCount,
            'warning_count' => $this->warningCount(),
            'error_count' => $this->errorCount(),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'dry_run' => $this->dryRun,
        ];
    }
}
