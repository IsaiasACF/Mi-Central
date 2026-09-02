<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;

final class ExpenseNotificationFormatter
{
    private const MONTHS = [
        1 => 'ene',
        2 => 'feb',
        3 => 'mar',
        4 => 'abr',
        5 => 'may',
        6 => 'jun',
        7 => 'jul',
        8 => 'ago',
        9 => 'sept',
        10 => 'oct',
        11 => 'nov',
        12 => 'dic',
    ];

    public function __construct(private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE)
    {
    }

    /**
     * @param array<string, mixed> $expense
     * @return array{title: string, message: ?string}
     */
    public function dueTomorrow(array $expense): array
    {
        return [
            'title' => $this->description($expense) . ' vence manana',
            'message' => $this->amountLine($expense),
        ];
    }

    /**
     * @param array<string, mixed> $expense
     * @return array{title: string, message: ?string}
     */
    public function dueToday(array $expense): array
    {
        return [
            'title' => $this->description($expense) . ' vence hoy',
            'message' => $this->amountLine($expense),
        ];
    }

    /**
     * @param array<string, mixed> $expense
     * @return array{title: string, message: ?string}
     */
    public function overdue(array $expense): array
    {
        $dueOn = is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : '';
        $parts = [];

        if ($dueOn !== '') {
            $parts[] = 'Vencio el ' . $this->shortDate($dueOn);
        }

        $parts[] = $this->amountText($expense);

        return [
            'title' => $this->description($expense) . ' esta vencido',
            'message' => implode(' - ', array_filter($parts)),
        ];
    }

    /**
     * @param array<string, mixed> $expense
     * @return array{title: string, message: ?string}
     */
    public function missingAmount(array $expense): array
    {
        $dueOn = is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : '';
        $message = $dueOn === ''
            ? 'Monto pendiente'
            : 'Vence el ' . $this->shortDate($dueOn) . ' y todavia no tiene monto.';

        return [
            'title' => $this->description($expense) . ' todavia no tiene monto',
            'message' => $message,
        ];
    }

    /**
     * @return array{title: string, message: string}
     */
    public function weeklySummary(int $count, int $totalKnownClp, int $unknownAmountCount): array
    {
        $title = $count . ' ' . ($count === 1 ? 'gasto pendiente' : 'gastos pendientes') . ' esta semana';
        $known = $totalKnownClp > 0 ? 'por ' . ExpenseFormat::clp($totalKnownClp) : 'sin monto conocido';
        $unknown = $unknownAmountCount > 0
            ? ' - ' . $unknownAmountCount . ' ' . ($unknownAmountCount === 1 ? 'monto pendiente' : 'montos pendientes')
            : '';

        return [
            'title' => $title,
            'message' => 'Tienes ' . $count . ' ' . ($count === 1 ? 'pago pendiente' : 'pagos pendientes') . ' ' . $known . $unknown . '.',
        ];
    }

    /**
     * @param array<string, mixed> $expense
     */
    private function amountLine(array $expense): string
    {
        return 'Monto: ' . $this->amountText($expense);
    }

    /**
     * @param array<string, mixed> $expense
     */
    private function amountText(array $expense): string
    {
        return $expense['amount_clp'] === null ? 'Monto pendiente' : ExpenseFormat::clp($expense['amount_clp']);
    }

    /**
     * @param array<string, mixed> $expense
     */
    private function description(array $expense): string
    {
        $description = trim((string) ($expense['description'] ?? 'Gasto'));

        return $description === '' ? 'Gasto' : $description;
    }

    private function shortDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, DateTimeHelper::timezone($this->timezone));

        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            return $date;
        }

        return (int) $parsed->format('j') . ' ' . self::MONTHS[(int) $parsed->format('n')];
    }
}
