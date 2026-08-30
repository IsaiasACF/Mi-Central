<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;
use Modules\Discounts\DiscountPromotionCategory;
use Modules\Discounts\DiscountPromotionFormat;

final class NormalizedPromotion
{
    /**
     * @param array<int, int> $weekdays
     * @param array<int, string> $benefits
     * @param array<int, string> $warnings
     */
    public function __construct(
        private readonly string $collectorKey,
        private readonly string $sourceKey,
        private readonly ?string $sourceUrl,
        private readonly ?string $merchantName,
        private readonly ?string $merchantCategory,
        private readonly ?string $promotionCategory,
        private readonly string $title,
        private readonly ?string $description,
        private readonly string $discountType,
        private readonly null|int|float|string $discountValue,
        private readonly ?int $maxDiscountClp,
        private readonly ?string $promoCode,
        private readonly string $channel,
        private readonly ?string $startsOn,
        private readonly ?string $endsOn,
        private readonly array $weekdays,
        private readonly array $benefits,
        private readonly ?string $terms,
        private readonly DateTimeImmutable $collectedAt,
        private readonly array $warnings = [],
    ) {
        if (!DiscountCollectorRegistry::isValidKey($collectorKey)) {
            throw new CollectorParseException('Collector key normalizada invalida.');
        }

        $this->assertText($sourceKey, 'source_key', 255, true);
        $this->assertText($title, 'title', 220, true);
        $this->assertText($sourceUrl, 'source_url', 500, false);
        $this->assertText($merchantName, 'merchant_name', 180, false);
        $this->assertText($merchantCategory, 'merchant_category', 120, false);
        $this->assertText($promotionCategory, 'category', 32, false);
        $this->assertText($description, 'description', 5000, false);
        $this->assertText($promoCode, 'promo_code', 120, false);
        $this->assertText($terms, 'terms', 5000, false);

        if ($sourceUrl !== null && (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($sourceUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new CollectorParseException('URL de fuente normalizada invalida.');
        }

        if (!in_array($discountType, DiscountPromotionFormat::DISCOUNT_TYPES, true)) {
            throw new CollectorParseException('Tipo de descuento normalizado invalido.');
        }

        if (!in_array($channel, DiscountPromotionFormat::CHANNELS, true)) {
            throw new CollectorParseException('Canal normalizado invalido.');
        }

        if ($promotionCategory !== null && !DiscountPromotionCategory::isValid($promotionCategory)) {
            throw new CollectorParseException('Categoria normalizada invalida.');
        }

        foreach ([$startsOn, $endsOn] as $date) {
            if ($date !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
                throw new CollectorParseException('Fecha normalizada invalida.');
            }
        }

        if ($startsOn !== null && $endsOn !== null && $startsOn > $endsOn) {
            throw new CollectorParseException('Rango de vigencia normalizado invalido.');
        }

        foreach ($weekdays as $weekday) {
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new CollectorParseException('Dia normalizado invalido.');
            }
        }

        foreach ($benefits as $benefit) {
            $this->assertText($benefit, 'benefit', 255, true);
        }

        foreach ($warnings as $warning) {
            $this->assertText($warning, 'warning', 500, true);
        }
    }

    public function collectorKey(): string
    {
        return $this->collectorKey;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function merchantName(): ?string
    {
        return $this->merchantName;
    }

    public function merchantCategory(): ?string
    {
        return $this->merchantCategory;
    }

    public function category(): ?string
    {
        return $this->promotionCategory;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function discountType(): string
    {
        return $this->discountType;
    }

    public function discountValue(): ?string
    {
        if ($this->discountValue === null || $this->discountValue === '') {
            return null;
        }

        return number_format((float) $this->discountValue, 2, '.', '');
    }

    public function maxDiscountClp(): ?int
    {
        return $this->maxDiscountClp;
    }

    public function promoCode(): ?string
    {
        return $this->promoCode;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function startsOn(): ?string
    {
        return $this->startsOn;
    }

    public function endsOn(): ?string
    {
        return $this->endsOn;
    }

    /**
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        return $this->weekdays;
    }

    /**
     * @return array<int, string>
     */
    public function benefits(): array
    {
        return $this->benefits;
    }

    public function hasRawBenefits(): bool
    {
        return $this->benefits !== [];
    }

    public function terms(): ?string
    {
        return $this->terms;
    }

    public function collectedAt(): DateTimeImmutable
    {
        return $this->collectedAt;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPromotionData(?int $merchantId, string $fingerprint): array
    {
        return [
            'merchant_id' => $merchantId,
            'created_by_user_id' => null,
            'category' => $this->promotionCategory,
            'title' => $this->title,
            'description' => $this->description,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue(),
            'max_discount_clp' => $this->maxDiscountClp,
            'promo_code' => $this->promoCode,
            'channel' => $this->channel,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'terms' => $this->terms,
            'source_type' => 'collector',
            'collector_key' => $this->collectorKey,
            'source_key' => $this->sourceKey,
            'source_url' => $this->sourceUrl,
            'last_seen_at' => $this->collectedAt->format('Y-m-d H:i:s'),
            'dedupe_fingerprint' => $fingerprint,
            'is_active' => 1,
        ];
    }

    private function assertText(?string $value, string $field, int $maxLength, bool $required): void
    {
        if ($value === null) {
            if ($required) {
                throw new CollectorParseException('Campo normalizado requerido invalido: ' . $field . '.');
            }

            return;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if (($required && trim($value) === '') || $length > $maxLength || @preg_match('//u', $value) !== 1) {
            throw new CollectorParseException('Texto normalizado invalido: ' . $field . '.');
        }
    }
}
