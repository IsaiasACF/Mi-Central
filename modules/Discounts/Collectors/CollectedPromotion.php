<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;

final class CollectedPromotion
{
    /**
     * @param array<int, string>|null $weekdaysRaw
     * @param array<int, string>|null $benefitNamesRaw
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly string $title,
        private readonly ?string $sourceUrl = null,
        private readonly ?string $merchantName = null,
        private readonly ?string $description = null,
        private readonly ?string $discountText = null,
        private readonly ?string $discountTypeHint = null,
        private readonly null|int|float|string $discountValueHint = null,
        private readonly ?int $maxDiscountClp = null,
        private readonly ?string $promoCode = null,
        private readonly ?string $startsOnRaw = null,
        private readonly ?string $endsOnRaw = null,
        private readonly ?array $weekdaysRaw = null,
        private readonly ?array $benefitNamesRaw = null,
        private readonly ?string $channelRaw = null,
        private readonly ?string $categoryRaw = null,
        private readonly ?string $terms = null,
        private readonly ?DateTimeImmutable $collectedAt = null,
    ) {
        $this->assertText($sourceKey, 'source_key', true);
        $this->assertText($title, 'title', true);

        foreach ([
            'source_url' => $sourceUrl,
            'merchant_name' => $merchantName,
            'description' => $description,
            'discount_text' => $discountText,
            'discount_type_hint' => $discountTypeHint,
            'promo_code' => $promoCode,
            'starts_on_raw' => $startsOnRaw,
            'ends_on_raw' => $endsOnRaw,
            'channel_raw' => $channelRaw,
            'category_raw' => $categoryRaw,
            'terms' => $terms,
        ] as $field => $value) {
            if ($value !== null) {
                $this->assertText($value, $field, false);
            }
        }

        if ($sourceUrl !== null && filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new CollectorParseException('URL de fuente invalida.');
        }

        $this->assertStringList($weekdaysRaw, 'weekdays_raw');
        $this->assertStringList($benefitNamesRaw, 'benefit_names_raw');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: (string) ($data['source_key'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            sourceUrl: self::nullableString($data['source_url'] ?? null),
            merchantName: self::nullableString($data['merchant_name'] ?? null),
            description: self::nullableString($data['description'] ?? null),
            discountText: self::nullableString($data['discount_text'] ?? null),
            discountTypeHint: self::nullableString($data['discount_type_hint'] ?? null),
            discountValueHint: $data['discount_value_hint'] ?? null,
            maxDiscountClp: self::nullableInt($data['max_discount_clp'] ?? null),
            promoCode: self::nullableString($data['promo_code'] ?? null),
            startsOnRaw: self::nullableString($data['starts_on_raw'] ?? null),
            endsOnRaw: self::nullableString($data['ends_on_raw'] ?? null),
            weekdaysRaw: is_array($data['weekdays_raw'] ?? null) ? array_values($data['weekdays_raw']) : null,
            benefitNamesRaw: is_array($data['benefit_names_raw'] ?? null) ? array_values($data['benefit_names_raw']) : null,
            channelRaw: self::nullableString($data['channel_raw'] ?? null),
            categoryRaw: self::nullableString($data['category_raw'] ?? null),
            terms: self::nullableString($data['terms'] ?? null),
            collectedAt: ($data['collected_at'] ?? null) instanceof DateTimeImmutable ? $data['collected_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'source_url' => $this->sourceUrl,
            'merchant_name' => $this->merchantName,
            'title' => $this->title,
            'description' => $this->description,
            'discount_text' => $this->discountText,
            'discount_type_hint' => $this->discountTypeHint,
            'discount_value_hint' => $this->discountValueHint,
            'max_discount_clp' => $this->maxDiscountClp,
            'promo_code' => $this->promoCode,
            'starts_on_raw' => $this->startsOnRaw,
            'ends_on_raw' => $this->endsOnRaw,
            'weekdays_raw' => $this->weekdaysRaw,
            'benefit_names_raw' => $this->benefitNamesRaw,
            'channel_raw' => $this->channelRaw,
            'category_raw' => $this->categoryRaw,
            'terms' => $this->terms,
            'collected_at' => $this->collectedAt()->format(DATE_ATOM),
        ];
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function collectedAt(): DateTimeImmutable
    {
        return $this->collectedAt ?? new DateTimeImmutable('now');
    }

    private function assertText(string $value, string $field, bool $required): void
    {
        if ($required && trim($value) === '') {
            throw new CollectorParseException('Campo requerido invalido: ' . $field . '.');
        }

        if (@preg_match('//u', $value) !== 1) {
            throw new CollectorParseException('Texto externo no es UTF-8 valido: ' . $field . '.');
        }
    }

    /**
     * @param array<int, mixed>|null $values
     */
    private function assertStringList(?array $values, string $field): void
    {
        if ($values === null) {
            return;
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new CollectorParseException('Lista invalida: ' . $field . '.');
            }

            $this->assertText($value, $field, false);
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $value = (int) $value;

            return $value > 0 ? $value : null;
        }

        return null;
    }
}
