<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;

final class ExpenseRecurringAdjustmentService
{
    public const ACTION_GENERATE = 'generate';
    public const ACTION_SKIP = 'skip';

    private const ACTIONS = [
        self::ACTION_GENERATE,
        self::ACTION_SKIP,
    ];
    private const DESCRIPTION_MAX_LENGTH = 180;
    private const NOTES_MAX_LENGTH = 5000;

    public function __construct(
        private readonly ExpenseRecurringAdjustmentRepository $adjustments,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveForService(int $userId, int $serviceId, array $input): array
    {
        $serviceId = $this->positiveId($serviceId, 'service_id');
        $adjustmentId = $this->nullableId($input['id'] ?? null, 'id');
        $ruleId = $this->positiveId((int) ($input['recurring_rule_id'] ?? 0), 'recurring_rule_id');

        if (!$this->adjustments->ruleBelongsToServiceForUser($userId, $ruleId, $serviceId)) {
            throw new ExpenseValidationException('Recurrencia invalida.');
        }

        $periodMonth = $this->periodMonth($input['period_month'] ?? null);
        $action = $this->action($input['adjustment_action'] ?? ($input['recurring_adjustment_action'] ?? ($input['action'] ?? self::ACTION_GENERATE)));
        $amountOverride = $this->boolFlag($input['amount_override'] ?? false);
        $data = [
            'action' => $action,
            'description' => $action === self::ACTION_SKIP ? null : $this->optionalPlainText($input['description'] ?? null, self::DESCRIPTION_MAX_LENGTH),
            'amount_override' => $action === self::ACTION_SKIP ? 0 : $amountOverride,
            'amount_clp' => $action === self::ACTION_SKIP || $amountOverride === 0 ? null : $this->optionalAmount($input['amount_clp'] ?? null),
            'due_on' => $action === self::ACTION_SKIP ? null : $this->optionalDate($input['due_on'] ?? null, 'Fecha limite invalida.'),
            'category_id' => $action === self::ACTION_SKIP ? null : $this->categoryId($userId, $input['category_id'] ?? null),
            'payment_method_id' => $action === self::ACTION_SKIP ? null : $this->paymentMethodId($userId, $input['payment_method_id'] ?? null),
            'notes' => $this->optionalPlainText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
            'active' => $this->boolFlag($input['active'] ?? true),
        ];

        if ($adjustmentId !== null) {
            if (!$this->adjustments->updateForUser($userId, $adjustmentId, $ruleId, $periodMonth, $data)) {
                throw new ExpenseValidationException('Ajuste invalido.');
            }

            return $this->adjustments->findByIdForUser($userId, $adjustmentId) ?? ['id' => $adjustmentId];
        }

        $id = $this->adjustments->save($userId, $ruleId, $periodMonth, $data);

        return $this->adjustments->findByRuleAndPeriodForUser($userId, $ruleId, $periodMonth) ?? ['id' => $id];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForService(int $userId, int $serviceId): array
    {
        return $this->adjustments->listForServiceForUser($userId, $this->positiveId($serviceId, 'service_id'));
    }

    /**
     * @param array<int, int> $serviceIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listForServices(int $userId, array $serviceIds): array
    {
        $validIds = [];

        foreach ($serviceIds as $serviceId) {
            $serviceId = (int) $serviceId;

            if ($serviceId > 0) {
                $validIds[] = $serviceId;
            }
        }

        return $this->adjustments->listForServicesForUser($userId, $validIds);
    }

    public function delete(int $userId, int $adjustmentId): bool
    {
        return $this->adjustments->deleteForUser($userId, $this->positiveId($adjustmentId, 'id'));
    }

    private function action(mixed $value): string
    {
        $action = trim((string) $value);

        if (!in_array($action, self::ACTIONS, true)) {
            throw new ExpenseValidationException('Accion de ajuste invalida.');
        }

        return $action;
    }

    private function periodMonth(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ExpenseValidationException('Periodo de ajuste invalido.');
        }

        $value = trim($value);

        if (preg_match('/\A(19|20|21|22)[0-9]{2}-(0[1-9]|1[0-2])\z/', $value) === 1) {
            return $value . '-01';
        }

        if (preg_match('/\A(19|20|21|22)[0-9]{2}-(0[1-9]|1[0-2])-01\z/', $value) === 1) {
            return $value;
        }

        throw new ExpenseValidationException('Periodo de ajuste invalido.');
    }

    private function optionalDate(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, DateTimeHelper::timezone($this->timezone));

        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new ExpenseValidationException($message);
        }

        return $value;
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
                throw new ExpenseValidationException('Monto de ajuste invalido.');
            }

            $amount = (int) $normalized;
        } else {
            throw new ExpenseValidationException('Monto de ajuste invalido.');
        }

        if ($amount < 0) {
            throw new ExpenseValidationException('Monto de ajuste invalido.');
        }

        return $amount;
    }

    private function categoryId(int $userId, mixed $categoryId): ?int
    {
        $categoryId = $this->nullableId($categoryId, 'category_id');

        if ($categoryId !== null && !$this->adjustments->categoryBelongsToUser($userId, $categoryId)) {
            throw new ExpenseValidationException('Categoria invalida.');
        }

        return $categoryId;
    }

    private function paymentMethodId(int $userId, mixed $paymentMethodId): ?int
    {
        $paymentMethodId = $this->nullableId($paymentMethodId, 'payment_method_id');

        if ($paymentMethodId !== null && !$this->adjustments->paymentMethodBelongsToUser($userId, $paymentMethodId)) {
            throw new ExpenseValidationException('Medio de pago invalido.');
        }

        return $paymentMethodId;
    }

    private function optionalPlainText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/<[^>]*>/', $value) === 1) {
            throw new ExpenseValidationException('Texto invalido.');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
            throw new ExpenseValidationException('Texto demasiado largo.');
        }

        return $value === '' ? null : $value;
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
