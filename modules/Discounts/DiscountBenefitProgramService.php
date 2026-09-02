<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDOException;

final class DiscountBenefitProgramService
{
    public const BENEFIT_TYPES = [
        'bank_card',
        'bank_account',
        'mobile',
        'wallet',
        'membership',
        'other',
    ];

    private const TEXT_MAX_LENGTH = 180;

    public function __construct(private readonly DiscountBenefitProgramRepository $programs)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $prepared = $this->prepareProgramInput($input);
        $provider = $prepared['provider_name'];
        $name = $prepared['name'];
        $type = $prepared['benefit_type'];
        $product = $prepared['product_name'];
        $normalizedProvider = $this->normalize($provider);
        $normalizedName = $this->normalize($name);
        $normalizedProduct = $this->normalize($product ?? '');

        if ($this->programs->normalizedExists($normalizedProvider, $normalizedName, $type, $normalizedProduct)) {
            throw new DiscountValidationException('Ya existe un programa de beneficio equivalente.');
        }

        $id = $this->programs->create([
            'provider_name' => $provider,
            'normalized_provider_name' => $normalizedProvider,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'benefit_type' => $type,
            'product_name' => $product,
            'normalized_product_name' => $normalizedProduct,
            'active' => $this->boolFlag($input['active'] ?? true),
        ]);

        return $this->get($id) ?? [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createOrFind(array $input): array
    {
        $prepared = $this->prepareProgramInput($input);
        $normalizedProvider = $this->normalize($prepared['provider_name']);
        $normalizedName = $this->normalize($prepared['name']);
        $normalizedProduct = $this->normalize($prepared['product_name'] ?? '');
        $existing = $this->programs->findByNormalized(
            $normalizedProvider,
            $normalizedName,
            $prepared['benefit_type'],
            $normalizedProduct,
        );

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($input);
        } catch (DiscountValidationException|PDOException $exception) {
            if ($exception instanceof PDOException && $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->programs->findByNormalized(
                $normalizedProvider,
                $normalizedName,
                $prepared['benefit_type'],
                $normalizedProduct,
            );

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $programId): ?array
    {
        return $this->programs->findById($this->positiveId($programId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->programs->list(['active' => true]);
    }

    public function assertExists(int $programId): void
    {
        if ($this->get($programId) === null) {
            throw new DiscountValidationException('Programa de beneficio no encontrado.');
        }
    }

    public function assertActive(int $programId): void
    {
        $program = $this->get($programId);

        if ($program === null || (int) ($program['active'] ?? 0) !== 1) {
            throw new DiscountValidationException('Programa de beneficio no encontrado.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{provider_name: string, name: string, benefit_type: string, product_name: ?string}
     */
    private function prepareProgramInput(array $input): array
    {
        return [
            'provider_name' => $this->requiredText($input['provider_name'] ?? null, 'provider_name'),
            'name' => $this->requiredText($input['name'] ?? null, 'name'),
            'benefit_type' => $this->benefitType($input['benefit_type'] ?? null),
            'product_name' => $this->optionalText($input['product_name'] ?? null, self::TEXT_MAX_LENGTH),
        ];
    }

    private function benefitType(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, DiscountBenefitType::TYPES, true)) {
            throw new DiscountValidationException('Tipo de beneficio invalido.');
        }

        return $value;
    }

    private function requiredText(mixed $value, string $field): string
    {
        $value = $this->normalizeSpaces((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($value === '' || $length > self::TEXT_MAX_LENGTH) {
            throw new DiscountValidationException('Texto invalido: ' . $field . '.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->normalizeSpaces((string) $value);

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
            throw new DiscountValidationException('Texto demasiado largo.');
        }

        return $value;
    }

    private function normalizeSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function normalize(string $value): string
    {
        $value = $this->normalizeSpaces($value);

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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

        throw new DiscountValidationException('Estado invalido.');
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new DiscountValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }
}
