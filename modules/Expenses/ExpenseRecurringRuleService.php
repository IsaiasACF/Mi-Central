<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use PDOException;

final class ExpenseRecurringRuleService
{
    private const FREQUENCY_MONTHLY = 'monthly';

    public function __construct(
        private readonly ExpenseRecurringRuleRepository $rules,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveForService(int $userId, int $serviceId, array $input, ?DateTimeImmutable $today = null): array
    {
        $serviceId = $this->positiveId($serviceId, 'service_id');

        if (!$this->rules->serviceBelongsToUser($userId, $serviceId)) {
            throw new ExpenseValidationException('Servicio de gasto invalido.');
        }

        $current = $this->rules->findByServiceForUser($userId, $serviceId);
        $data = $this->data($userId, $input, $today, $current);

        if ($current === null) {
            try {
                $id = $this->rules->create($userId, ['service_id' => $serviceId] + $data);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new ExpenseValidationException('Ese servicio ya tiene una recurrencia configurada.');
                }

                throw $exception;
            }

            return $this->get($userId, $id) ?? [];
        }

        $this->rules->update($userId, (int) $current['id'], $data);

        return $this->get($userId, (int) $current['id']) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $ruleId): ?array
    {
        return $this->rules->findByIdForUser($userId, $this->positiveId($ruleId, 'id'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getForService(int $userId, int $serviceId): ?array
    {
        return $this->rules->findByServiceForUser($userId, $this->positiveId($serviceId, 'service_id'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setActive(int $userId, int $ruleId, bool $active, ?DateTimeImmutable $today = null): ?array
    {
        $rule = $this->rules->findByIdForUser($userId, $this->positiveId($ruleId, 'id'));

        if ($rule === null) {
            return null;
        }

        $data = ['active' => $active ? 1 : 0];

        if ($active) {
            $data['next_generation_on'] = $this->nextGenerationOn(
                (string) $rule['starts_on'],
                is_string($rule['ends_on'] ?? null) ? (string) $rule['ends_on'] : null,
                is_string($rule['next_generation_on'] ?? null) ? (string) $rule['next_generation_on'] : null,
                $today,
            );
        }

        $this->rules->update($userId, (int) $rule['id'], $data);

        return $this->get($userId, (int) $rule['id']);
    }

    /**
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function data(int $userId, array $input, ?DateTimeImmutable $today, ?array $current): array
    {
        $startsOn = $this->date($input['starts_on'] ?? ($current['starts_on'] ?? null), 'Fecha de inicio invalida.');
        $endsOn = $this->optionalDate($input['ends_on'] ?? ($current['ends_on'] ?? null), 'Fecha de termino invalida.');

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new ExpenseValidationException('La fecha de termino no puede ser anterior al inicio.');
        }

        $currentNext = is_string($current['next_generation_on'] ?? null) ? (string) $current['next_generation_on'] : null;

        return [
            'frequency' => $this->frequency($input['frequency'] ?? ($current['frequency'] ?? self::FREQUENCY_MONTHLY)),
            'interval_value' => $this->intervalValue($input['interval_value'] ?? ($current['interval_value'] ?? 1)),
            'day_of_month' => $this->dayOfMonth($input['day_of_month'] ?? ($current['day_of_month'] ?? null)),
            'default_amount_clp' => $this->optionalAmount($input['default_amount_clp'] ?? ($current['default_amount_clp'] ?? null)),
            'default_category_id' => $this->categoryId($userId, $input['default_category_id'] ?? ($current['default_category_id'] ?? null)),
            'default_payment_method_id' => $this->paymentMethodId($userId, $input['default_payment_method_id'] ?? ($current['default_payment_method_id'] ?? null)),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'next_generation_on' => $this->nextGenerationOn($startsOn, $endsOn, $currentNext, $today),
            'active' => $this->boolFlag($input['active'] ?? ($current['active'] ?? true)),
        ];
    }

    private function nextGenerationOn(string $startsOn, ?string $endsOn, ?string $currentNext, ?DateTimeImmutable $today): ?string
    {
        $timezone = DateTimeHelper::timezone($this->timezone);
        $today ??= DateTimeHelper::nowLocal($this->timezone);
        $currentMonth = $today->setTimezone($timezone)->modify('first day of this month')->format('Y-m-01');
        $startsMonth = ExpenseDateHelper::monthStart($startsOn, $timezone);
        $candidate = $currentNext !== null && $currentNext >= $currentMonth ? $currentNext : max($startsMonth, $currentMonth);

        if ($candidate < $startsMonth) {
            $candidate = $startsMonth;
        }

        if ($endsOn !== null && $candidate > ExpenseDateHelper::monthStart($endsOn, $timezone)) {
            return null;
        }

        return $candidate;
    }

    private function frequency(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value !== self::FREQUENCY_MONTHLY) {
            throw new ExpenseValidationException('Frecuencia invalida.');
        }

        return $value;
    }

    private function intervalValue(mixed $value): int
    {
        $value = $this->positiveInputNumber($value, 'interval_value');

        if ($value < 1 || $value > 120) {
            throw new ExpenseValidationException('Intervalo invalido.');
        }

        return $value;
    }

    private function dayOfMonth(mixed $value): int
    {
        $value = $this->positiveInputNumber($value, 'day_of_month');

        if ($value < 1 || $value > 31) {
            throw new ExpenseValidationException('Dia de vencimiento invalido.');
        }

        return $value;
    }

    private function categoryId(int $userId, mixed $categoryId): ?int
    {
        $categoryId = $this->nullableId($categoryId, 'default_category_id');

        if ($categoryId !== null && !$this->rules->categoryBelongsToUser($userId, $categoryId)) {
            throw new ExpenseValidationException('Categoria invalida.');
        }

        return $categoryId;
    }

    private function paymentMethodId(int $userId, mixed $paymentMethodId): ?int
    {
        $paymentMethodId = $this->nullableId($paymentMethodId, 'default_payment_method_id');

        if ($paymentMethodId !== null && !$this->rules->paymentMethodBelongsToUser($userId, $paymentMethodId)) {
            throw new ExpenseValidationException('Medio de pago invalido.');
        }

        return $paymentMethodId;
    }

    private function optionalAmount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value)) {
            $normalized = str_replace(['$', '.', ' '], '', trim($value));

            if (preg_match('/\A[0-9]+\z/', $normalized) !== 1) {
                throw new ExpenseValidationException('Monto predeterminado invalido.');
            }

            $amount = (int) $normalized;
        } else {
            throw new ExpenseValidationException('Monto predeterminado invalido.');
        }

        if ($amount <= 0) {
            throw new ExpenseValidationException('Monto predeterminado invalido.');
        }

        return $amount;
    }

    private function date(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, DateTimeHelper::timezone($this->timezone));

        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new ExpenseValidationException($message);
        }

        return $value;
    }

    private function optionalDate(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $message);
    }

    private function nullableId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->inputId($value, $field);
    }

    private function inputId(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $this->positiveId($value, $field);
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $this->positiveId((int) $value, $field);
        }

        throw new ExpenseValidationException("Identificador invalido: {$field}.");
    }

    private function positiveInputNumber(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', trim($value)) === 1) {
            $number = (int) trim($value);
        } else {
            throw new ExpenseValidationException("Valor invalido: {$field}.");
        }

        return $number;
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new ExpenseValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }

    private function boolFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value) && in_array($value, ['0', '1'], true)) {
            return (int) $value;
        }

        throw new ExpenseValidationException('Estado invalido.');
    }
}
