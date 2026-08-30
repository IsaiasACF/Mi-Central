<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDOException;

final class ExpenseCategoryService
{
    private const NAME_MAX_LENGTH = 120;

    public function __construct(private readonly ExpenseCategoryRepository $categories)
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

        if ($this->categories->normalizedNameExists($userId, $normalizedName)) {
            throw new ExpenseValidationException('Ya existe una categoria de gastos con ese nombre.');
        }

        try {
            $id = $this->categories->create(
                $userId,
                $name,
                $normalizedName,
                $this->color($input['color'] ?? null),
                $this->boolFlag($input['active'] ?? true),
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ExpenseValidationException('Ya existe una categoria de gastos con ese nombre.');
            }

            throw $exception;
        }

        return $this->get($userId, $id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $categoryId): ?array
    {
        return $this->categories->findByIdForUser($userId, $this->positiveId($categoryId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, bool $activeOnly = false): array
    {
        return $this->categories->listForUser($userId, $activeOnly);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $categoryId, array $input): ?array
    {
        $categoryId = $this->positiveId($categoryId, 'id');

        if ($this->get($userId, $categoryId) === null) {
            return null;
        }

        $data = [];

        if (array_key_exists('name', $input)) {
            $data['name'] = $this->name($input['name']);
            $data['normalized_name'] = $this->normalizedName($data['name']);

            if ($this->categories->normalizedNameExists($userId, $data['normalized_name'], $categoryId)) {
                throw new ExpenseValidationException('Ya existe una categoria de gastos con ese nombre.');
            }
        }

        if (array_key_exists('color', $input)) {
            $data['color'] = $this->color($input['color']);
        }

        if (array_key_exists('active', $input)) {
            $data['active'] = $this->boolFlag($input['active']);
        }

        $this->categories->update($userId, $categoryId, $data);

        return $this->get($userId, $categoryId);
    }

    private function name(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($name === '' || $length > self::NAME_MAX_LENGTH) {
            throw new ExpenseValidationException('Nombre de categoria invalido.');
        }

        return $name;
    }

    private function normalizedName(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function color(mixed $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }

        $color = strtoupper(trim((string) $color));

        if (preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1) {
            throw new ExpenseValidationException('Color de categoria invalido.');
        }

        return $color;
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
