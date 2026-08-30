<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDOException;

final class ExpenseServiceDefinitionService
{
    private const NAME_MAX_LENGTH = 160;
    private const NOTES_MAX_LENGTH = 5000;

    public function __construct(private readonly ExpenseServiceRepository $services)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $name = $this->name($input['name'] ?? '');
        $normalizedName = $this->normalizedName($name);
        $paymentMethodIds = $this->paymentMethodIds($input['payment_method_ids'] ?? []);
        $defaultPaymentMethodId = $this->defaultPaymentMethodId($input['default_payment_method_id'] ?? null, $paymentMethodIds);

        if ($this->services->normalizedNameExists($userId, $normalizedName)) {
            throw new ExpenseValidationException('Ya existe un servicio de gasto con ese nombre.');
        }

        $this->assertPaymentMethodsSelectable($userId, 0, $paymentMethodIds);

        try {
            $id = $this->services->create($userId, [
                'category_id' => $this->categoryId($userId, $input['category_id'] ?? null),
                'name' => $name,
                'normalized_name' => $normalizedName,
                'default_amount_clp' => $this->optionalAmount($input['default_amount_clp'] ?? null),
                'notes' => $this->optionalPlainText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
                'active' => $this->boolFlag($input['active'] ?? true),
            ]);
            $this->services->syncPaymentMethods($id, $paymentMethodIds, $defaultPaymentMethodId);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ExpenseValidationException('Ya existe un servicio de gasto con ese nombre.');
            }

            throw $exception;
        }

        return $this->get($userId, $id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $serviceId): ?array
    {
        $service = $this->services->findByIdForUser($userId, $this->positiveId($serviceId, 'id'));

        if ($service === null) {
            return null;
        }

        $service['payment_methods'] = $this->services->paymentMethodsForService($userId, $serviceId);

        return $this->withRecurringRule($service);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, bool $activeOnly = false): array
    {
        return array_map(
            fn (array $service): array => $this->withRecurringRule($service + [
                'payment_methods' => $this->services->paymentMethodsForService($userId, (int) $service['id']),
            ]),
            $this->services->listForUser($userId, $activeOnly),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $serviceId, array $input): ?array
    {
        $serviceId = $this->positiveId($serviceId, 'id');

        if ($this->services->findByIdForUser($userId, $serviceId) === null) {
            return null;
        }

        $data = [];

        if (array_key_exists('category_id', $input)) {
            $data['category_id'] = $this->categoryId($userId, $input['category_id']);
        }

        if (array_key_exists('name', $input)) {
            $data['name'] = $this->name($input['name']);
            $data['normalized_name'] = $this->normalizedName($data['name']);

            if ($this->services->normalizedNameExists($userId, $data['normalized_name'], $serviceId)) {
                throw new ExpenseValidationException('Ya existe un servicio de gasto con ese nombre.');
            }
        }

        if (array_key_exists('default_amount_clp', $input)) {
            $data['default_amount_clp'] = $this->optionalAmount($input['default_amount_clp']);
        }

        if (array_key_exists('notes', $input)) {
            $data['notes'] = $this->optionalPlainText($input['notes'], self::NOTES_MAX_LENGTH);
        }

        if (array_key_exists('active', $input)) {
            $data['active'] = $this->boolFlag($input['active']);
        }

        $this->services->update($userId, $serviceId, $data);

        if (array_key_exists('payment_method_ids', $input) || array_key_exists('default_payment_method_id', $input)) {
            $paymentMethodIds = $this->paymentMethodIds($input['payment_method_ids'] ?? []);
            $defaultPaymentMethodId = $this->defaultPaymentMethodId($input['default_payment_method_id'] ?? null, $paymentMethodIds);
            $this->assertPaymentMethodsSelectable($userId, $serviceId, $paymentMethodIds);
            $this->services->syncPaymentMethods($serviceId, $paymentMethodIds, $defaultPaymentMethodId);
        }

        return $this->get($userId, $serviceId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addPaymentMethod(int $userId, int $serviceId, int $paymentMethodId, bool $isDefault = false): array
    {
        $serviceId = $this->positiveId($serviceId, 'service_id');
        $paymentMethodId = $this->positiveId($paymentMethodId, 'payment_method_id');

        if ($this->services->findByIdForUser($userId, $serviceId) === null) {
            throw new ExpenseValidationException('Servicio de gasto invalido.');
        }

        if (!$this->services->paymentMethodBelongsToUser($userId, $paymentMethodId)) {
            throw new ExpenseValidationException('Medio de pago invalido.');
        }

        if ($this->services->servicePaymentMethodExists($serviceId, $paymentMethodId)) {
            throw new ExpenseValidationException('El medio de pago ya esta asociado al servicio.');
        }

        try {
            $this->services->addPaymentMethod($serviceId, $paymentMethodId, $isDefault ? 1 : 0);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ExpenseValidationException('El medio de pago ya esta asociado al servicio.');
            }

            throw $exception;
        }

        return $this->services->paymentMethodsForService($userId, $serviceId);
    }

    public function setDefaultPaymentMethod(int $userId, int $serviceId, int $paymentMethodId): bool
    {
        $serviceId = $this->positiveId($serviceId, 'service_id');
        $paymentMethodId = $this->positiveId($paymentMethodId, 'payment_method_id');

        if ($this->services->findByIdForUser($userId, $serviceId) === null) {
            throw new ExpenseValidationException('Servicio de gasto invalido.');
        }

        if (!$this->services->paymentMethodBelongsToUser($userId, $paymentMethodId)) {
            throw new ExpenseValidationException('Medio de pago invalido.');
        }

        if (!$this->services->servicePaymentMethodExists($serviceId, $paymentMethodId)) {
            throw new ExpenseValidationException('El medio de pago no esta asociado al servicio.');
        }

        return $this->services->setPaymentMethodDefault($serviceId, $paymentMethodId);
    }

    private function categoryId(int $userId, mixed $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $categoryId = $this->inputId($categoryId, 'category_id');

        if (!$this->services->categoryBelongsToUser($userId, $categoryId)) {
            throw new ExpenseValidationException('Categoria invalida.');
        }

        return $categoryId;
    }

    private function name(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($name === '' || $length > self::NAME_MAX_LENGTH) {
            throw new ExpenseValidationException('Nombre de servicio invalido.');
        }

        return $name;
    }

    private function normalizedName(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
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
                throw new ExpenseValidationException('Monto sugerido invalido.');
            }

            $amount = (int) $normalized;
        } else {
            throw new ExpenseValidationException('Monto sugerido invalido.');
        }

        if ($amount < 0) {
            throw new ExpenseValidationException('Monto sugerido invalido.');
        }

        return $amount;
    }

    /**
     * @param array<int, int> $paymentMethodIds
     */
    private function defaultPaymentMethodId(mixed $value, array $paymentMethodIds): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $defaultPaymentMethodId = $this->inputId($value, 'default_payment_method_id');

        if (!in_array($defaultPaymentMethodId, $paymentMethodIds, true)) {
            throw new ExpenseValidationException('El medio predeterminado debe estar seleccionado.');
        }

        return $defaultPaymentMethodId;
    }

    /**
     * @return array<int, int>
     */
    private function paymentMethodIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value)) {
            throw new ExpenseValidationException('Medios de pago invalidos.');
        }

        $ids = [];

        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }

            $ids[] = $this->inputId($item, 'payment_method_ids');
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, int> $paymentMethodIds
     */
    private function assertPaymentMethodsSelectable(int $userId, int $serviceId, array $paymentMethodIds): void
    {
        foreach ($paymentMethodIds as $paymentMethodId) {
            if (!$this->services->paymentMethodSelectableForService($userId, $serviceId, $paymentMethodId)) {
                throw new ExpenseValidationException('Medio de pago invalido.');
            }
        }
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

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function withRecurringRule(array $service): array
    {
        $ruleId = $service['recurring_rule_id'] === null ? null : (int) $service['recurring_rule_id'];
        $service['recurring_rule'] = $ruleId === null ? null : [
            'id' => $ruleId,
            'service_id' => (int) $service['id'],
            'frequency' => (string) $service['recurring_frequency'],
            'interval_value' => (int) $service['recurring_interval_value'],
            'day_of_month' => $service['recurring_day_of_month'] === null ? null : (int) $service['recurring_day_of_month'],
            'default_amount_clp' => $service['recurring_default_amount_clp'] === null ? null : (int) $service['recurring_default_amount_clp'],
            'default_category_id' => $service['recurring_default_category_id'] === null ? null : (int) $service['recurring_default_category_id'],
            'default_payment_method_id' => $service['recurring_default_payment_method_id'] === null ? null : (int) $service['recurring_default_payment_method_id'],
            'starts_on' => (string) $service['recurring_starts_on'],
            'ends_on' => $service['recurring_ends_on'] === null ? null : (string) $service['recurring_ends_on'],
            'next_generation_on' => $service['recurring_next_generation_on'] === null ? null : (string) $service['recurring_next_generation_on'],
            'active' => (int) $service['recurring_active'],
        ];

        foreach ([
            'recurring_rule_id',
            'recurring_frequency',
            'recurring_interval_value',
            'recurring_day_of_month',
            'recurring_default_amount_clp',
            'recurring_default_category_id',
            'recurring_default_payment_method_id',
            'recurring_starts_on',
            'recurring_ends_on',
            'recurring_next_generation_on',
            'recurring_active',
        ] as $field) {
            unset($service[$field]);
        }

        return $service;
    }
}
