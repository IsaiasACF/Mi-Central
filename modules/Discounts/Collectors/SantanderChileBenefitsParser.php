<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use JsonException;

final class SantanderChileBenefitsParser
{
    private const EXPECTED_TAG = 'home-disfrutadores';
    private const DETAIL_HOST = 'banco.santander.cl';

    /**
     * @return array{items: array<int, CollectedPromotion>, total_pages: int, total_entries: int, current_page: int}
     */
    public function parseCatalog(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CollectorParseException('JSON publico de Santander Chile no pudo parsearse.');
        }

        if (!is_array($data) || !is_array($data['promociones'] ?? null)) {
            throw new CollectorParseException('Estructura fundamental de beneficios Santander Chile no encontrada.');
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $entries = $data['promociones'];
        $totalEntries = max(0, (int) ($meta['total_entries'] ?? count($entries)));
        $totalPages = max(1, (int) ($meta['total_pages'] ?? 1));
        $currentPage = max(1, (int) ($meta['current_page'] ?? 1));

        if ($entries === [] && $totalEntries > 0) {
            throw new CollectorParseException('Catalogo Santander Chile sin promociones pese a indicar total mayor que cero.');
        }

        $items = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new CollectorParseException('Entrada de beneficio Santander Chile invalida.');
            }

            $item = $this->parseEntry($entry);

            if ($item !== null) {
                $items[] = $item;
            }
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
    public function parseEntry(array $entry): ?CollectedPromotion
    {
        $tags = $this->tags($entry['tags'] ?? []);

        if (!$this->shouldImport($tags, $entry)) {
            return null;
        }

        $sourceKey = $this->slug($entry['slug'] ?? null) ?? $this->slug($entry['uuid'] ?? null) ?? $this->slug($entry['id'] ?? null);
        $title = $this->text($entry['title'] ?? null);

        if ($sourceKey === null) {
            throw new CollectorParseException('Beneficio Santander Chile sin slug estable.');
        }

        if ($title === null) {
            throw new CollectorParseException('Beneficio Santander Chile sin titulo.');
        }

        $customFields = is_array($entry['custom_fields'] ?? null) ? $entry['custom_fields'] : [];
        $externalCopy = $this->customField($customFields, 'Bajada externa');
        $internalCopy = $this->customField($customFields, 'Bajada interna');
        $landingTitle = $this->customField($customFields, 'Titulo en landing Cuarenta');
        $landingCopy = $this->customField($customFields, 'Bajada en landing Cuarenta');
        $vigencia = $this->customField($customFields, 'Vigencia');
        $description = $this->htmlText($entry['description'] ?? null);
        $conditions = $this->text($entry['conditions'] ?? null);
        $discountText = $this->discountText($externalCopy, $internalCopy, $landingTitle, $landingCopy, $description);
        $discount = $this->discountHint($discountText);
        $dates = $this->dates($vigencia);
        $terms = $this->join([$description, $conditions]);

        return new CollectedPromotion(
            sourceKey: $sourceKey,
            title: $title,
            sourceUrl: $this->sourceUrl($entry['url'] ?? null),
            merchantName: $title,
            description: $description,
            discountText: $discountText,
            discountTypeHint: $discount['type'],
            discountValueHint: $discount['value'],
            maxDiscountClp: $this->maxDiscountClp($this->join([$externalCopy, $internalCopy, $description])),
            promoCode: $this->promoCode($description),
            startsOnRaw: $dates['starts_on'],
            endsOnRaw: $dates['ends_on'],
            weekdaysRaw: $this->weekdaysRaw($tags, $externalCopy, $internalCopy, $landingCopy, $description),
            benefitNamesRaw: $this->benefits($tags, $description),
            channelRaw: $this->join([$externalCopy, $internalCopy, $description]),
            categoryRaw: $this->join($tags),
            terms: $terms === '' ? null : $terms,
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $tags
     */
    private function shouldImport(array $tags, array $entry): bool
    {
        if (!in_array(self::EXPECTED_TAG, $tags, true) || in_array('no-home', $tags, true) || in_array('empresas', $tags, true)) {
            return false;
        }

        $fields = is_array($entry['custom_fields'] ?? null) ? $entry['custom_fields'] : [];
        $text = $this->comparable($this->join([
            $this->customField($fields, 'Bajada externa'),
            $this->customField($fields, 'Bajada interna'),
            $this->customField($fields, 'Vigencia'),
            $this->htmlText($entry['description'] ?? null),
        ]));

        if ($text === '') {
            return false;
        }

        return preg_match('/\b(dcto|descuento|cuotas?\s+sin\s+interes|milla|millas|beneficio|promocion)\b/u', $text) === 1
            || str_contains($text, '%');
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, string>
     */
    private function tags(array $tags): array
    {
        $result = [];

        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $result[] = $this->slug($tag) ?? $tag;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function customField(array $fields, string $name): ?string
    {
        $field = is_array($fields[$name] ?? null) ? $fields[$name] : null;

        return $field === null ? null : $this->text($field['value'] ?? null);
    }

    private function sourceUrl(mixed $url): ?string
    {
        $url = $this->text($url);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === self::DETAIL_HOST ? $url : null;
    }

    private function discountText(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{type: ?string, value: int|float|null}
     */
    private function discountHint(?string $discountText): array
    {
        if ($discountText === null) {
            return ['type' => null, 'value' => null];
        }

        $comparable = $this->comparable($discountText);

        if (preg_match('/\b(hasta|maximo)\b/u', $comparable) === 1) {
            return ['type' => 'other', 'value' => null];
        }

        if (preg_match('/(?<!\d)(\d{1,3}(?:[,.]\d{1,2})?)\s*%/u', $discountText, $matches) === 1) {
            return [
                'type' => 'percentage',
                'value' => (float) str_replace(',', '.', $matches[1]),
            ];
        }

        if (preg_match('/cuotas?\s+sin\s+inter[eé]s/iu', $discountText) === 1 || preg_match('/\bmillas?\b/iu', $discountText) === 1) {
            return ['type' => 'other', 'value' => null];
        }

        return ['type' => null, 'value' => null];
    }

    /**
     * @return array{starts_on: ?string, ends_on: ?string}
     */
    private function dates(?string $vigencia): array
    {
        if ($vigencia === null) {
            return ['starts_on' => null, 'ends_on' => null];
        }

        $comparable = $this->comparable($vigencia);

        if (preg_match('/(?:hasta\s+el\s+)?(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4})/u', $comparable, $matches) === 1) {
            return [
                'starts_on' => null,
                'ends_on' => str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ' de ' . $matches[2] . ' de ' . $matches[3],
            ];
        }

        return ['starts_on' => null, 'ends_on' => $vigencia];
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>|null
     */
    private function weekdaysRaw(array $tags, ?string ...$texts): ?array
    {
        $values = [];

        foreach ($tags as $tag) {
            if ($this->looksLikeWeekday($tag)) {
                $values[] = $tag;
            }
        }

        foreach ($texts as $text) {
            if ($text !== null && $this->looksLikeWeekday($text)) {
                $values[] = $text;
            }
        }

        return $values === [] ? null : array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>|null
     */
    private function benefits(array $tags, ?string $description): ?array
    {
        $benefits = [];
        $text = $this->comparable($description ?? '');
        $isAmex = in_array('amex', $tags, true) || in_array('exclusivo-amex', $tags, true) || str_contains($text, 'american express');
        $isLimited = in_array('wm-limited', $tags, true) || in_array('exclusivo-limited', $tags, true) || str_contains($text, 'worldmember limited');

        if ($isAmex) {
            $benefits['bank_card|Santander Chile|American Express Santander|Credito'] = 'bank_card|Santander Chile|American Express Santander|Credito';

            return array_values($benefits);
        }

        if ($isLimited) {
            $benefits['bank_card|Santander Chile|WorldMember Limited Santander|Credito'] = 'bank_card|Santander Chile|WorldMember Limited Santander|Credito';

            return array_values($benefits);
        }

        $hasAllCards = in_array('todas-las-tarjetas', $tags, true) || (str_contains($text, 'credito') && str_contains($text, 'debito'));
        $hasDebit = $hasAllCards || in_array('tarjetas-debito', $tags, true) || in_array('life-y-debito', $tags, true) || str_contains($text, 'debito');
        $hasCredit = $hasAllCards
            || in_array('tarjetas-credito', $tags, true)
            || in_array('tarjeta-credito', $tags, true)
            || str_contains($text, 'credito');

        if ($hasDebit) {
            $benefits['bank_card|Santander Chile|Tarjetas Debito Santander|Debito'] = 'bank_card|Santander Chile|Tarjetas Debito Santander|Debito';
        }

        if ($hasCredit) {
            $benefits['bank_card|Santander Chile|Tarjetas Credito Santander|Credito'] = 'bank_card|Santander Chile|Tarjetas Credito Santander|Credito';
        }

        return $benefits === [] ? null : array_values($benefits);
    }

    private function maxDiscountClp(string $text): ?int
    {
        if (preg_match_all('/(?:descuento\s+m[aá]ximo|m[aá]ximo\s+de\s+descuento|tope(?:\s+m[aá]ximo)?(?:\s+de)?(?:\s+descuento)?)\s*(?:por\s+\w+\s+)?(?:de)?\s*\$?\s*([0-9][0-9\.\,]*)/iu', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($matches[1] as $amountMatch) {
            $amount = (int) str_replace(['.', ','], '', $amountMatch[0]);

            if ($amount > 0) {
                return $amount;
            }
        }

        return null;
    }

    private function promoCode(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('/(?:c[oó]digo\s+(?:promocional|de\s+descuento)|cup[oó]n)\s*[:：]\s*([A-Z0-9_-]{3,30})/iu', $text, $matches) === 1) {
            return strtoupper($matches[1]);
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
        return preg_match('/\b(lunes|martes|miercoles|miércoles|jueves|viernes|sabado|sábado|domingo|todos-los-dias|todos los dias|todos los días)\b/iu', $value) === 1;
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
