<?php
declare(strict_types=1);

namespace Modules\Expenses;

use DateTimeImmutable;
use DateTimeZone;

final class ExpenseDateHelper
{
    public static function resolveDayOfMonth(int $year, int $month, int $requestedDay): string
    {
        $requestedDay = max(1, min(31, $requestedDay));
        $monthDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-01', $year, $month),
        );
        $daysInMonth = $monthDate instanceof DateTimeImmutable ? (int) $monthDate->format('t') : 28;
        $day = min($requestedDay, $daysInMonth);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function monthStart(string $date, DateTimeZone $timezone): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new ExpenseValidationException('Fecha invalida.');
        }

        return $parsed->modify('first day of this month')->format('Y-m-01');
    }

    public static function addMonths(string $periodMonth, int $months, DateTimeZone $timezone): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $periodMonth, $timezone);

        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $periodMonth) {
            throw new ExpenseValidationException('Periodo invalido.');
        }

        return $parsed->modify('+' . max(1, $months) . ' months')->format('Y-m-01');
    }
}
