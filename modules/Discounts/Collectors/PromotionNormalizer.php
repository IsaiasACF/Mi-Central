<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;
use Modules\Discounts\DiscountPromotionCategory;
use Modules\Discounts\DiscountPromotionFormat;
use Modules\Discounts\DiscountTextNormalizer;

final class PromotionNormalizer
{
    private const MONTHS = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    public function __construct(
        private readonly DiscountTextNormalizer $text = new DiscountTextNormalizer(),
        private readonly DiscountPromotionCategory $categories = new DiscountPromotionCategory(),
    ) {
    }

    public function normalize(string $collectorKey, CollectedPromotion $raw): NormalizedPromotion
    {
        $data = $raw->toArray();
        $warnings = [];
        $sourceKey = $this->required($data['source_key'] ?? null, 'source_key', 255);
        $title = $this->limited($this->required($data['title'] ?? null, 'title', 220), 'title', 220, $warnings);
        $sourceUrl = $this->optionalUrl($data['source_url'] ?? null);
        $merchantName = $this->limited($this->text->cleanVisible($this->stringOrNull($data['merchant_name'] ?? null)), 'merchant_name', 180, $warnings);
        $description = $this->limited($this->text->cleanVisible($this->stringOrNull($data['description'] ?? null)), 'description', 5000, $warnings);
        $terms = $this->limited($this->text->cleanVisible($this->stringOrNull($data['terms'] ?? null)), 'terms', 5000, $warnings);
        $discount = $this->normalizeDiscount($data, $warnings);
        $startsOn = $this->normalizeDate($this->stringOrNull($data['starts_on_raw'] ?? null), 'starts_on', $warnings);
        $endsOn = $this->normalizeDate($this->stringOrNull($data['ends_on_raw'] ?? null), 'ends_on', $warnings);

        if ($startsOn !== null && $endsOn !== null && $startsOn > $endsOn) {
            throw new CollectorParseException('Rango de vigencia invalido en promocion recolectada.');
        }

        $weekdays = $this->normalizeWeekdays(is_array($data['weekdays_raw'] ?? null) ? $data['weekdays_raw'] : [], $warnings);
        $benefits = $this->normalizeBenefits(is_array($data['benefit_names_raw'] ?? null) ? $data['benefit_names_raw'] : []);
        $channel = $this->normalizeChannel($this->stringOrNull($data['channel_raw'] ?? null), $warnings);
        $maxDiscountClp = $this->normalizePositiveInt($data['max_discount_clp'] ?? null, 'max_discount_clp', $warnings);
        $promoCode = $this->limited($this->text->cleanVisible($this->stringOrNull($data['promo_code'] ?? null)), 'promo_code', 120, $warnings);
        $category = $this->categories->normalize($this->stringOrNull($data['category_raw'] ?? null), implode(' ', array_filter([
            $merchantName,
            $title,
            $description,
            $terms,
        ], static fn (?string $value): bool => $value !== null && $value !== '')));

        return new NormalizedPromotion(
            collectorKey: $collectorKey,
            sourceKey: $sourceKey,
            sourceUrl: $sourceUrl,
            merchantName: $merchantName,
            merchantCategory: null,
            promotionCategory: $category,
            title: $title,
            description: $description,
            discountType: $discount['type'],
            discountValue: $discount['value'],
            maxDiscountClp: $maxDiscountClp,
            promoCode: $promoCode,
            channel: $channel,
            startsOn: $startsOn,
            endsOn: $endsOn,
            weekdays: $weekdays,
            benefits: $benefits,
            terms: $terms,
            collectedAt: $raw->collectedAt(),
            warnings: $warnings,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $warnings
     * @return array{type: string, value: ?string}
     */
    private function normalizeDiscount(array $data, array &$warnings): array
    {
        $hintType = $this->text->comparable($this->stringOrNull($data['discount_type_hint'] ?? null));
        $hintType = str_replace(' ', '_', $hintType);
        $valueHint = $data['discount_value_hint'] ?? null;
        $searchText = implode(' ', array_filter([
            $this->stringOrNull($data['discount_text'] ?? null),
            $this->stringOrNull($data['title'] ?? null),
            $this->stringOrNull($data['description'] ?? null),
        ], static fn (?string $value): bool => $value !== null));

        if (in_array($hintType, DiscountPromotionFormat::DISCOUNT_TYPES, true)) {
            if ($hintType === 'percentage') {
                $value = $this->numericValue($valueHint) ?? $this->parsePercentage($searchText);
                $this->assertPercentage($value);

                return ['type' => 'percentage', 'value' => $this->formatNumber($value)];
            }

            if ($hintType === 'fixed_amount') {
                $value = $this->numericValue($valueHint) ?? $this->parseFixedAmount($searchText);
                $this->assertPositive($value, 'Monto fijo invalido.');

                return ['type' => 'fixed_amount', 'value' => $this->formatNumber($value)];
            }

            return ['type' => $hintType, 'value' => null];
        }

        $percentage = $this->parsePercentage($searchText);

        if ($percentage !== null) {
            $this->assertPercentage($percentage);

            return ['type' => 'percentage', 'value' => $this->formatNumber($percentage)];
        }

        $fixed = $this->parseFixedAmount($searchText);

        if ($fixed !== null) {
            $this->assertPositive($fixed, 'Monto fijo invalido.');

            return ['type' => 'fixed_amount', 'value' => $this->formatNumber($fixed)];
        }

        $warnings[] = 'Descuento no reconocido; se guardo como otro.';

        return ['type' => 'other', 'value' => null];
    }

    private function parsePercentage(string $text): ?float
    {
        if (preg_match('/(?<!\d)(\d{1,3}(?:[,.]\d{1,2})?)\s*%/u', $text, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    private function parseFixedAmount(string $text): ?float
    {
        $comparable = $this->text->comparable($text);

        if (!str_contains($comparable, 'descuento')) {
            return null;
        }

        if (preg_match('/\$\s*([0-9][0-9\.\,]*)/u', $text, $matches) === 1) {
            return (float) str_replace(['.', ','], ['', '.'], $matches[1]);
        }

        if (preg_match('/(?<!\d)([0-9][0-9\.\,]*)\s*(?:pesos|clp)/u', $comparable, $matches) === 1) {
            return (float) str_replace(['.', ','], ['', '.'], $matches[1]);
        }

        return null;
    }

    private function normalizeChannel(?string $value, array &$warnings): string
    {
        $channel = $this->text->comparable($value);

        if ($channel === '') {
            $warnings[] = 'Canal no informado; se guardo como presencial y online.';

            return 'both';
        }

        $hasOnline = preg_match('/\b(online|web|internet|app|sitio)\b/u', $channel) === 1;
        $hasStore = preg_match('/\b(presencial|tienda|local|sucursal|locales)\b/u', $channel) === 1;

        if (str_contains($channel, 'ambos') || ($hasOnline && $hasStore)) {
            return 'both';
        }

        if ($hasOnline) {
            return 'online';
        }

        if ($hasStore) {
            return 'in_store';
        }

        $warnings[] = 'Canal no reconocido; se guardo como presencial y online.';

        return 'both';
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, string> $warnings
     * @return array<int, int>
     */
    private function normalizeWeekdays(array $values, array &$warnings): array
    {
        $days = [];
        $foundAllDays = false;

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $text = $this->text->comparable($value);

            if ($text === '') {
                continue;
            }

            if (preg_match('/\b(todos los dias|todos dias|diario|cada dia)\b/u', $text) === 1) {
                $foundAllDays = true;
                continue;
            }

            foreach ([
                1 => ['lunes', 'lun'],
                2 => ['martes', 'mar'],
                3 => ['miercoles', 'mie'],
                4 => ['jueves', 'jue'],
                5 => ['viernes', 'vie'],
                6 => ['sabado', 'sab'],
                7 => ['domingo', 'dom'],
            ] as $weekday => $tokens) {
                foreach ($tokens as $token) {
                    if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $text) === 1) {
                        $days[$weekday] = $weekday;
                    }
                }
            }
        }

        if ($foundAllDays) {
            return [];
        }

        if ($values !== [] && $days === []) {
            $warnings[] = 'Dias de semana no reconocidos.';
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeBenefits(array $values): array
    {
        $benefits = [];

        foreach ($values as $value) {
            $benefit = is_string($value) ? $this->text->cleanVisible($value) : null;

            if ($benefit !== null) {
                $benefits[$this->text->comparable($benefit)] = $benefit;
            }
        }

        return array_values($benefits);
    }

    /**
     * @param array<int, string> $warnings
     */
    private function normalizePositiveInt(mixed $value, string $field, array &$warnings): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value <= 0) {
            $warnings[] = 'Monto positivo no reconocido en ' . $field . '; se dejo sin valor.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<int, string> $warnings
     */
    private function normalizeDate(?string $value, string $field, array &$warnings): ?string
    {
        $value = $this->text->cleanVisible($value);

        if ($value === null) {
            return null;
        }

        foreach (['!Y-m-d' => '/\A\d{4}-\d{2}-\d{2}\z/', '!d/m/Y' => '/\A\d{1,2}\/\d{1,2}\/\d{4}\z/', '!d-m-Y' => '/\A\d{1,2}-\d{1,2}-\d{4}\z/'] as $format => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $date = DateTimeImmutable::createFromFormat($format, $value);

                if ($date instanceof DateTimeImmutable) {
                    return $date->format('Y-m-d');
                }
            }
        }

        $comparable = $this->text->comparable($value);

        if (preg_match('/\A(\d{1,2})\s+(?:de\s+)?([a-z]+)\s+(?:de\s+)?(\d{4})\z/u', $comparable, $matches) === 1) {
            $month = self::MONTHS[$matches[2]] ?? null;

            if ($month !== null) {
                $date = DateTimeImmutable::createFromFormat('!Y-n-j', $matches[3] . '-' . $month . '-' . $matches[1]);

                if ($date instanceof DateTimeImmutable) {
                    return $date->format('Y-m-d');
                }
            }
        }

        if (preg_match('/\A\d{1,2}[\/-]\d{1,2}\z/', $value) === 1) {
            $warnings[] = 'Fecha ambigua sin ano en ' . $field . '; se dejo sin fecha.';

            return null;
        }

        $warnings[] = 'Fecha no reconocida en ' . $field . '; se dejo sin fecha.';

        return null;
    }

    private function required(mixed $value, string $field, int $maxLength): string
    {
        $value = $this->text->cleanVisible(is_string($value) ? $value : (string) $value);
        $length = $value === null ? 0 : (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value));

        if ($value === null || $length > $maxLength) {
            throw new CollectorParseException('Campo requerido invalido: ' . $field . '.');
        }

        return $value;
    }

    /**
     * @param array<int, string> $warnings
     */
    private function limited(?string $value, string $field, int $maxLength, array &$warnings): ?string
    {
        if ($value === null) {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($length <= $maxLength) {
            return $value;
        }

        $warnings[] = 'Texto truncado en ' . $field . '.';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    private function optionalUrl(mixed $value): ?string
    {
        $value = $this->text->cleanVisible($this->stringOrNull($value));

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new CollectorParseException('URL de fuente invalida.');
        }

        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            return (float) str_replace(',', '.', trim($value));
        }

        return null;
    }

    private function assertPercentage(?float $value): void
    {
        if ($value === null || $value <= 0 || $value > 100) {
            throw new CollectorParseException('Porcentaje recolectado invalido.');
        }
    }

    private function assertPositive(?float $value, string $message): void
    {
        if ($value === null || $value <= 0) {
            throw new CollectorParseException($message);
        }
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
