<?php
declare(strict_types=1);

namespace Modules\Discounts;

use DateTimeImmutable;

final class DiscountPromotionFormat
{
    public const DISCOUNT_TYPES = [
        'percentage',
        'fixed_amount',
        'special_price',
        'other',
    ];

    public const CHANNELS = [
        'in_store',
        'online',
        'both',
    ];

    public const WEEKDAYS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miercoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sabado',
        7 => 'Domingo',
    ];

    /**
     * @return array<string, string>
     */
    public static function discountTypeLabels(): array
    {
        return [
            'percentage' => 'Porcentaje',
            'fixed_amount' => 'Monto fijo',
            'special_price' => 'Precio especial',
            'other' => 'Otro',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return [
            'in_store' => 'Presencial',
            'online' => 'Online',
            'both' => 'Ambos',
        ];
    }

    public static function discountLabel(array $promotion): string
    {
        $type = (string) ($promotion['discount_type'] ?? '');
        $value = $promotion['discount_value'] ?? null;

        if ($type === 'percentage' && $value !== null && $value !== '') {
            return self::cleanNumber((float) $value) . '%';
        }

        if ($type === 'fixed_amount' && $value !== null && $value !== '') {
            return '$' . number_format((int) round((float) $value), 0, ',', '.');
        }

        if ($type === 'special_price') {
            return 'Precio especial';
        }

        return '';
    }

    public static function temporalState(array $promotion, string $timezoneName = 'America/Santiago'): string
    {
        return (new DiscountPromotionAvailabilityService($timezoneName))->temporalState($promotion);
    }

    public static function validityLabel(array $promotion): string
    {
        $startsOn = is_string($promotion['starts_on'] ?? null) ? (string) $promotion['starts_on'] : '';
        $endsOn = is_string($promotion['ends_on'] ?? null) ? (string) $promotion['ends_on'] : '';

        if ($startsOn === '' && $endsOn === '') {
            return 'Sin vigencia definida';
        }

        if ($startsOn !== '' && $endsOn !== '') {
            return 'Desde ' . self::dateLabel($startsOn) . ' hasta ' . self::dateLabel($endsOn);
        }

        if ($startsOn !== '') {
            return 'Desde ' . self::dateLabel($startsOn);
        }

        return 'Vigente hasta ' . self::dateLabel($endsOn);
    }

    public static function dateLabel(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            return $date;
        }

        $months = [
            1 => 'ene',
            2 => 'feb',
            3 => 'mar',
            4 => 'abr',
            5 => 'may',
            6 => 'jun',
            7 => 'jul',
            8 => 'ago',
            9 => 'sep',
            10 => 'oct',
            11 => 'nov',
            12 => 'dic',
        ];

        return (int) $parsed->format('j') . ' ' . $months[(int) $parsed->format('n')] . ' ' . $parsed->format('Y');
    }

    private static function cleanNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
