<?php
declare(strict_types=1);

namespace Modules\Discounts;

final class DiscountBenefitType
{
    public const TYPES = [
        'bank_card',
        'bank_account',
        'mobile',
        'wallet',
        'membership',
        'other',
    ];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'bank_card' => 'Tarjeta bancaria',
            'bank_account' => 'Cuenta bancaria',
            'mobile' => 'Operador movil',
            'wallet' => 'Billetera digital',
            'membership' => 'Membresia',
            'other' => 'Otro',
        ];
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? 'Otro';
    }
}
