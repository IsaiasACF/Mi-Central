<?php
declare(strict_types=1);

namespace Modules\Discounts;

use DateTimeImmutable;
use DateTimeZone;

final class DiscountPromotionAvailabilityService
{
    public function __construct(private readonly string $timezoneName = 'America/Santiago')
    {
    }

    public function temporalState(array $promotion): string
    {
        if ((int) ($promotion['is_active'] ?? 0) !== 1) {
            return 'Inactiva';
        }

        $startsOn = is_string($promotion['starts_on'] ?? null) ? (string) $promotion['starts_on'] : '';
        $endsOn = is_string($promotion['ends_on'] ?? null) ? (string) $promotion['ends_on'] : '';

        if ($startsOn === '' && $endsOn === '') {
            return 'Sin vigencia definida';
        }

        $today = $this->today();

        if ($startsOn !== '' && $startsOn > $today) {
            return 'Proximamente';
        }

        if ($endsOn !== '' && $endsOn < $today) {
            return 'Vencida';
        }

        return 'Vigente';
    }

    /**
     * @param array<int, int>|null $weekdays
     */
    public function isApplicableToday(array $promotion, ?array $weekdays = null): bool
    {
        return $this->isApplicableOn($promotion, $this->today(), $this->weekdayToday(), $weekdays);
    }

    /**
     * @param array<int, int>|null $weekdays
     */
    public function isApplicableOn(array $promotion, string $date, int $weekday, ?array $weekdays = null): bool
    {
        if ((int) ($promotion['is_active'] ?? 0) !== 1) {
            return false;
        }

        $startsOn = is_string($promotion['starts_on'] ?? null) ? (string) $promotion['starts_on'] : '';
        $endsOn = is_string($promotion['ends_on'] ?? null) ? (string) $promotion['ends_on'] : '';

        if ($startsOn !== '' && $startsOn > $date) {
            return false;
        }

        if ($endsOn !== '' && $endsOn < $date) {
            return false;
        }

        $weekdays = $weekdays ?? $this->weekdaysFromPromotion($promotion);

        if ($weekdays === []) {
            return true;
        }

        return in_array($weekday, array_map('intval', $weekdays), true);
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    public function weekdayToday(): int
    {
        return (int) $this->now()->format('N');
    }

    private function now(): DateTimeImmutable
    {
        $timezoneName = $this->timezoneName !== '' ? $this->timezoneName : 'America/Santiago';

        return new DateTimeImmutable('now', new DateTimeZone($timezoneName));
    }

    /**
     * @return array<int, int>
     */
    private function weekdaysFromPromotion(array $promotion): array
    {
        $weekdays = $promotion['weekdays'] ?? [];

        if (!is_array($weekdays)) {
            return [];
        }

        return array_values(array_map('intval', $weekdays));
    }
}
