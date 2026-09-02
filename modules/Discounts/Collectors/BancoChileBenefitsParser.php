<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use JsonException;

final class BancoChileBenefitsParser
{
    public const DETAIL_BASE_URL = 'https://sitiospublicos.bancochile.cl/personas/beneficios/detalle/';

    private const CARD_BENEFITS = [
        'visa-credito-infinite' => ['Visa Infinite', 'Credito'],
        'visa-credito-signature' => ['Visa Signature', 'Credito'],
        'visa-credito-platinum' => ['Visa Platinum', 'Credito'],
        'visa-credito-gold' => ['Visa Gold', 'Credito'],
        'visa-fan-credito' => ['Visa FAN', 'Credito'],
        'mastercard-credito-black' => ['Mastercard Black', 'Credito'],
        'mastercard-credito-platinum' => ['Mastercard Platinum', 'Credito'],
        'mastercard-credito-dorada' => ['Mastercard Dorada', 'Credito'],
        'visa-debito-infinite' => ['Visa Infinite', 'Debito'],
        'visa-debito-signature' => ['Visa Signature', 'Debito'],
        'visa-debito-bch' => ['Visa Debito Banco de Chile', 'Debito'],
        'visa-cuenta-fan' => ['Cuenta FAN Visa', 'Debito'],
    ];

    private const MONTHS = [
        'enero' => 'enero',
        'febrero' => 'febrero',
        'marzo' => 'marzo',
        'abril' => 'abril',
        'mayo' => 'mayo',
        'junio' => 'junio',
        'julio' => 'julio',
        'agosto' => 'agosto',
        'septiembre' => 'septiembre',
        'setiembre' => 'septiembre',
        'octubre' => 'octubre',
        'noviembre' => 'noviembre',
        'diciembre' => 'diciembre',
    ];

    /**
     * @return array{items: array<int, CollectedPromotion>, total_pages: int, total_entries: int, current_page: int}
     */
    public function parsePage(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CollectorParseException('JSON publico de Banco de Chile no pudo parsearse.');
        }

        if (!is_array($data) || !is_array($data['entries'] ?? null)) {
            throw new CollectorParseException('Estructura fundamental de beneficios Banco de Chile no encontrada.');
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $entries = $data['entries'];
        $totalEntries = max(0, (int) ($meta['total_entries'] ?? count($entries)));
        $totalPages = max(1, (int) ($meta['total_pages'] ?? 1));
        $currentPage = max(1, (int) ($meta['current_page'] ?? 1));

        if ($entries === [] && $totalEntries > 0) {
            throw new CollectorParseException('Catalogo Banco de Chile sin entradas pese a indicar total mayor que cero.');
        }

        $items = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new CollectorParseException('Entrada de beneficio Banco de Chile invalida.');
            }

            $items[] = $this->parseEntry($entry);
        }

        return [
            'items' => $items,
            'total_pages' => $totalPages,
            'total_entries' => $totalEntries,
            'current_page' => $currentPage,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function parseEntry(array $entry): CollectedPromotion
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $tags = array_values(array_filter($meta['tags'] ?? [], static fn (mixed $tag): bool => is_string($tag) && trim($tag) !== ''));
        $slug = $this->slug($meta['slug'] ?? null);
        $sourceKey = $slug !== null ? $slug : $this->slug($meta['uuid'] ?? null);

        if ($sourceKey === null) {
            throw new CollectorParseException('Beneficio Banco de Chile sin slug estable.');
        }

        $title = $this->text($fields['Titulo'] ?? null) ?? $this->text($meta['name'] ?? null);

        if ($title === null) {
            throw new CollectorParseException('Beneficio Banco de Chile sin titulo.');
        }

        $description = $this->htmlText($fields['Descripcion'] ?? null);
        $commercialTerms = $this->text($fields['Condiciones Comerciales'] ?? null);
        $vigencia = $this->text($fields['Vigencia'] ?? null);
        $extract = $this->text($fields['Extracto'] ?? null);
        $discountText = $this->discountText($fields);
        $discount = $this->discountHint($discountText);
        $dates = $this->dates($vigencia ?? $commercialTerms ?? '');
        $benefits = $this->benefits($fields, $commercialTerms);
        $channelRaw = $this->join([$extract, $description, $commercialTerms]);
        $weekdaysRaw = $this->weekdaysRaw($meta, $extract, $description);
        $sourceUrl = self::DETAIL_BASE_URL . rawurlencode($sourceKey);
        $terms = $commercialTerms ?? $description;

        return new CollectedPromotion(
            sourceKey: $sourceKey,
            title: $title,
            sourceUrl: $sourceUrl,
            merchantName: $title,
            description: $description,
            discountText: $discountText,
            discountTypeHint: $discount['type'],
            discountValueHint: $discount['value'],
            maxDiscountClp: $this->maxDiscountClp($this->join([$commercialTerms, $description])),
            startsOnRaw: $dates['starts_on'],
            endsOnRaw: $dates['ends_on'],
            weekdaysRaw: $weekdaysRaw,
            benefitNamesRaw: $benefits,
            channelRaw: $channelRaw,
            categoryRaw: $this->join(array_map(static fn (mixed $tag): string => (string) $tag, $tags)),
            terms: $terms,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function discountText(array $fields): ?string
    {
        return $this->text($fields['Tipo Beneficio'] ?? null)
            ?? $this->text($fields['Tipo Beneficio Oceano'] ?? null)
            ?? $this->text($fields['Tipo Beneficio Cordillera'] ?? null)
            ?? $this->text($fields['Extracto'] ?? null);
    }

    /**
     * @return array{type: ?string, value: int|float|null}
     */
    private function discountHint(?string $discountText): array
    {
        if ($discountText !== null && preg_match('/(?<!\d)(\d{1,3}(?:[,.]\d{1,2})?)\s*%/u', $discountText, $matches) === 1) {
            return [
                'type' => 'percentage',
                'value' => (float) str_replace(',', '.', $matches[1]),
            ];
        }

        return ['type' => null, 'value' => null];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<int, string>|null
     */
    private function weekdaysRaw(array $meta, ?string $extract, ?string $description): ?array
    {
        $values = [];

        if (is_array($meta['tags'] ?? null)) {
            foreach ($meta['tags'] as $tag) {
                if (is_string($tag) && $this->looksLikeWeekday($tag)) {
                    $values[] = $tag;
                }
            }
        }

        foreach ([$extract, $description] as $value) {
            if ($value !== null && $this->looksLikeWeekday($value)) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, string>|null
     */
    private function benefits(array $fields, ?string $terms): ?array
    {
        $benefits = [];
        $cards = is_array($fields['Tarjetas Permitidas'] ?? null) ? $fields['Tarjetas Permitidas'] : [];

        foreach ($cards as $card) {
            if (!is_string($card)) {
                continue;
            }

            $benefit = $this->cardBenefit($card);

            if ($benefit !== null) {
                $benefits[$benefit] = $benefit;
            }
        }

        if ($benefits === [] && $terms !== null && preg_match('/tarjetas(?:\s+emitidas)?\s+(?:por\s+)?(?:el\s+)?banco\s+de\s+chile|tarjetas\s+del\s+chile/iu', $terms) === 1) {
            $benefits['bank_card|Banco de Chile|Tarjetas Banco de Chile|'] = 'bank_card|Banco de Chile|Tarjetas Banco de Chile|';
        }

        return $benefits === [] ? null : array_values($benefits);
    }

    private function cardBenefit(string $card): ?string
    {
        $key = $this->slug($card);

        if ($key === null) {
            return null;
        }

        $mapped = self::CARD_BENEFITS[$key] ?? null;

        if ($mapped === null) {
            $name = ucwords(str_replace('-', ' ', $key));

            return 'bank_card|Banco de Chile|' . $name . '|';
        }

        return 'bank_card|Banco de Chile|' . $mapped[0] . '|' . $mapped[1];
    }

    /**
     * @return array{starts_on: ?string, ends_on: ?string}
     */
    private function dates(string $text): array
    {
        $text = $this->comparable($text);

        if (preg_match('/(?:desde\s+el|del)\s+(\d{1,2})\s+al\s+(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4})/u', $text, $matches) === 1) {
            $month = self::MONTHS[$matches[3]] ?? null;

            if ($month !== null) {
                return [
                    'starts_on' => $this->dateRaw($matches[1], $month, $matches[4]),
                    'ends_on' => $this->dateRaw($matches[2], $month, $matches[4]),
                ];
            }
        }

        if (preg_match('/(?:desde\s+el|del)\s+(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4}).*?(?:hasta\s+el|al)\s+(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4})/u', $text, $matches) === 1) {
            $startMonth = self::MONTHS[$matches[2]] ?? null;
            $endMonth = self::MONTHS[$matches[5]] ?? null;

            if ($startMonth !== null && $endMonth !== null) {
                return [
                    'starts_on' => $this->dateRaw($matches[1], $startMonth, $matches[3]),
                    'ends_on' => $this->dateRaw($matches[4], $endMonth, $matches[6]),
                ];
            }
        }

        if (preg_match('/hasta\s+el\s+(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4})/u', $text, $matches) === 1) {
            $month = self::MONTHS[$matches[2]] ?? null;

            if ($month !== null) {
                return ['starts_on' => null, 'ends_on' => $this->dateRaw($matches[1], $month, $matches[3])];
            }
        }

        return ['starts_on' => null, 'ends_on' => null];
    }

    private function maxDiscountClp(string $text): ?int
    {
        if (preg_match_all('/tope(?:\s+m[aá]ximo)?(?:\s+de)?(?:\s+descuento)?\s*(?:de)?\s*\$?\s*([0-9][0-9\.\,]*)/iu', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($matches[1] as $index => $amountMatch) {
            $offset = (int) ($matches[0][$index][1] ?? 0);
            $context = $this->comparable(substr($text, max(0, $offset - 80), 180));

            if (str_contains($context, 'compra') && !str_contains($context, 'descuento')) {
                continue;
            }

            $amount = (int) str_replace(['.', ','], '', $amountMatch[0]);

            if ($amount > 0) {
                return $amount;
            }
        }

        return null;
    }

    private function htmlText(mixed $html): ?string
    {
        if (!is_string($html) || trim($html) === '') {
            return null;
        }

        if (!str_contains($html, '<')) {
            return $this->text($html);
        }

        $xpath = CollectorHtmlHelper::xpath($html);
        $nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]');
        $parts = [];

        foreach ($nodes as $node) {
            $text = $this->text($node->textContent);

            if ($text !== null) {
                $parts[] = $text;
            }
        }

        return $this->text(implode(' ', $parts));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    private function slug(mixed $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $value = $this->comparable($value);
        $value = (string) preg_replace('/[^a-z0-9_-]+/u', '-', $value);
        $value = trim((string) preg_replace('/-+/u', '-', $value), '-_');

        return $value === '' ? null : $value;
    }

    private function looksLikeWeekday(string $value): bool
    {
        return preg_match('/\b(lunes|martes|miercoles|miércoles|jueves|viernes|sabado|sábado|domingo|todos los dias|todos los días)\b/iu', $value) === 1;
    }

    private function dateRaw(string $day, string $month, string $year): string
    {
        return str_pad($day, 2, '0', STR_PAD_LEFT) . ' de ' . $month . ' de ' . $year;
    }

    /**
     * @param array<int, ?string> $values
     */
    private function join(array $values): string
    {
        return implode(' ', array_values(array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '')));
    }

    private function comparable(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if (is_string($converted)) {
            $value = $converted;
        }

        $value = (string) preg_replace('/[^a-z0-9\s_-]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
