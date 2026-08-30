<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDOException;

final class ExpensePaymentMethodService
{
    public const TYPES = [
        'card',
        'bank_account',
        'automatic_payment',
        'wallet',
        'webpay',
        'transfer',
        'cash',
        'other',
    ];

    private const NAME_MAX_LENGTH = 120;
    private const INSTITUTION_MAX_LENGTH = 120;
    private const NOTES_MAX_LENGTH = 5000;
    private const SENSITIVE_INPUT_KEYS = [
        'card_number',
        'number',
        'full_number',
        'cvv',
        'cvc',
        'pin',
        'password',
        'secret',
        'account_number',
        'bank_account_number',
    ];

    public function __construct(private readonly ExpensePaymentMethodRepository $paymentMethods)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $this->rejectSensitiveInput($input);

        $name = $this->name($input['name'] ?? '');
        $normalizedName = $this->normalizedName($name);

        if ($this->paymentMethods->normalizedNameExists($userId, $normalizedName)) {
            throw new ExpenseValidationException('Ya existe un medio de pago con ese nombre.');
        }

        try {
            $id = $this->paymentMethods->create($userId, [
                'name' => $name,
                'normalized_name' => $normalizedName,
                'type' => $this->type($input['type'] ?? ''),
                'institution_name' => $this->optionalText($input['institution_name'] ?? null, self::INSTITUTION_MAX_LENGTH),
                'notes' => $this->optionalText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
                'active' => $this->boolFlag($input['active'] ?? true),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ExpenseValidationException('Ya existe un medio de pago con ese nombre.');
            }

            throw $exception;
        }

        return $this->get($userId, $id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $paymentMethodId): ?array
    {
        return $this->paymentMethods->findByIdForUser($userId, $this->positiveId($paymentMethodId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, bool $activeOnly = false): array
    {
        return $this->paymentMethods->listForUser($userId, $activeOnly);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $paymentMethodId, array $input): ?array
    {
        $paymentMethodId = $this->positiveId($paymentMethodId, 'id');

        if ($this->get($userId, $paymentMethodId) === null) {
            return null;
        }

        $this->rejectSensitiveInput($input);
        $data = [];

        if (array_key_exists('name', $input)) {
            $data['name'] = $this->name($input['name']);
            $data['normalized_name'] = $this->normalizedName($data['name']);

            if ($this->paymentMethods->normalizedNameExists($userId, $data['normalized_name'], $paymentMethodId)) {
                throw new ExpenseValidationException('Ya existe un medio de pago con ese nombre.');
            }
        }

        if (array_key_exists('type', $input)) {
            $data['type'] = $this->type($input['type']);
        }

        if (array_key_exists('institution_name', $input)) {
            $data['institution_name'] = $this->optionalText($input['institution_name'], self::INSTITUTION_MAX_LENGTH);
        }

        if (array_key_exists('notes', $input)) {
            $data['notes'] = $this->optionalText($input['notes'], self::NOTES_MAX_LENGTH);
        }

        if (array_key_exists('active', $input)) {
            $data['active'] = $this->boolFlag($input['active']);
        }

        $this->paymentMethods->update($userId, $paymentMethodId, $data);

        return $this->get($userId, $paymentMethodId);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rejectSensitiveInput(array $input): void
    {
        foreach (self::SENSITIVE_INPUT_KEYS as $key) {
            if (isset($input[$key]) && trim((string) $input[$key]) !== '') {
                throw new ExpenseValidationException('El medio de pago no debe guardar datos sensibles.');
            }
        }

        foreach (['name', 'institution_name', 'notes'] as $field) {
            if (!isset($input[$field])) {
                continue;
            }

            $value = (string) $input[$field];

            if (
                preg_match('/(?:\d[\s-]?){13,19}/', $value) === 1
                || preg_match('/\b(cvv|cvc|pin|clave|password|contrasena|contraseña)\b/i', $value) === 1
            ) {
                throw new ExpenseValidationException('El medio de pago no debe guardar datos sensibles.');
            }
        }
    }

    private function name(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($name === '' || $length > self::NAME_MAX_LENGTH) {
            throw new ExpenseValidationException('Nombre de medio de pago invalido.');
        }

        return $name;
    }

    private function normalizedName(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function type(mixed $type): string
    {
        $type = trim((string) $type);

        if (!in_array($type, self::TYPES, true)) {
            throw new ExpenseValidationException('Tipo de medio de pago invalido.');
        }

        return $type;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
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

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new ExpenseValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
