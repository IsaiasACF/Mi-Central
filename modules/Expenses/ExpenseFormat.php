<?php
declare(strict_types=1);

namespace Modules\Expenses;

final class ExpenseFormat
{
    public static function clp(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return '$' . number_format((int) $amount, 0, ',', '.');
    }
}
