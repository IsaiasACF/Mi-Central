<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDOException;

final class DiscountMerchantService
{
    private const NAME_MAX_LENGTH = 180;
    private const CATEGORY_MAX_LENGTH = 120;
    private const URL_MAX_LENGTH = 500;

    public function __construct(private readonly DiscountMerchantRepository $merchants)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $name = $this->requiredText($input['name'] ?? null, 'name', self::NAME_MAX_LENGTH);
        $normalizedName = $this->normalize($name);

        if ($this->merchants->normalizedNameExists($normalizedName)) {
            throw new DiscountValidationException('Ya existe un comercio con ese nombre.');
        }

        $id = $this->merchants->create([
            'name' => $name,
            'normalized_name' => $normalizedName,
            'category' => $this->optionalText($input['category'] ?? null, self::CATEGORY_MAX_LENGTH),
            'website_url' => $this->optionalUrl($input['website_url'] ?? null),
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
        $name = $this->requiredText($input['name'] ?? null, 'name', self::NAME_MAX_LENGTH);
        $normalizedName = $this->normalize($name);
        $existing = $this->merchants->findByNormalizedName($normalizedName);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($input);
        } catch (DiscountValidationException|PDOException $exception) {
            if ($exception instanceof PDOException && $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->merchants->findByNormalizedName($normalizedName);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $merchantId): ?array
    {
        return $this->merchants->findById($this->positiveId($merchantId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->merchants->listActive();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->merchants->list();
    }

    public function assertExists(int $merchantId): void
    {
        if ($this->get($merchantId) === null) {
            throw new DiscountValidationException('Comercio no encontrado.');
        }
    }

    private function requiredText(mixed $value, string $field, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($value === '' || $length > $maxLength) {
            throw new DiscountValidationException('Texto invalido: ' . $field . '.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
            throw new DiscountValidationException('Texto demasiado largo.');
        }

        return $value;
    }

    private function optionalUrl(mixed $value): ?string
    {
        $value = $this->optionalText($value, self::URL_MAX_LENGTH);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DiscountValidationException('URL invalida.');
        }

        return $value;
    }

    private function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

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
