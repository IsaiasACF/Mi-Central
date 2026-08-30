<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;

final class CollectorResult
{
    /**
     * @param array<int, CollectedPromotion> $items
     * @param array<int, string> $warnings
     */
    public function __construct(
        private readonly string $collectorKey,
        private readonly DateTimeImmutable $startedAt,
        private readonly DateTimeImmutable $finishedAt,
        private readonly array $items,
        private readonly array $warnings = [],
        private readonly bool $success = true,
        private readonly ?string $errorType = null,
        private readonly ?string $errorMessage = null,
    ) {
        if (!DiscountCollectorRegistry::isValidKey($collectorKey)) {
            throw new CollectorConfigurationException('Collector key invalida.');
        }

        foreach ($items as $item) {
            if (!$item instanceof CollectedPromotion) {
                throw new CollectorConfigurationException('CollectorResult solo acepta CollectedPromotion.');
            }
        }

        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                throw new CollectorConfigurationException('Advertencia de collector invalida.');
            }
        }
    }

    /**
     * @param array<int, CollectedPromotion> $items
     * @param array<int, string> $warnings
     */
    public static function ok(string $collectorKey, DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt, array $items, array $warnings = []): self
    {
        return new self($collectorKey, $startedAt, $finishedAt, array_values($items), array_values($warnings), true);
    }

    /**
     * @param array<int, string> $warnings
     */
    public static function failed(string $collectorKey, DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt, string $errorType, string $errorMessage, array $warnings = []): self
    {
        return new self($collectorKey, $startedAt, $finishedAt, [], array_values($warnings), false, $errorType, $errorMessage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collector_key' => $this->collectorKey,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt->format(DATE_ATOM),
            'items' => array_map(static fn (CollectedPromotion $item): array => $item->toArray(), $this->items),
            'warnings' => $this->warnings,
            'success' => $this->success,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
        ];
    }

    public function collectorKey(): string
    {
        return $this->collectorKey;
    }

    public function success(): bool
    {
        return $this->success;
    }

    /**
     * @return array<int, CollectedPromotion>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function errorType(): ?string
    {
        return $this->errorType;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
