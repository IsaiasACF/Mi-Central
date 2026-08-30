<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DOMElement;

final class BancoEstadoBenefitsParser
{
    public const CATALOG_URL = 'https://investor.bancoestado.cl/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios.html';

    private const ALLOWED_DETAIL_HOSTS = ['www.bancoestado.cl', 'investor.bancoestado.cl'];
    private const DETAIL_PATH_MARKER = '/todos-beneficios/';

    /**
     * @return array<int, string>
     */
    public function parseCatalogLinks(string $html, string $baseUrl = self::CATALOG_URL): array
    {
        $this->assertNotBlocked($html, 'catalogo BancoEstado');
        $xpath = CollectorHtmlHelper::xpath($html);
        $pageText = $this->comparable($this->textFromXpath($xpath));

        if (!str_contains($pageText, 'beneficios') || !str_contains($pageText, 'bancoestado')) {
            throw new CollectorParseException('Estructura fundamental del catalogo BancoEstado no encontrada.');
        }

        $links = [];

        foreach ($xpath->query('//a[@href]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $url = $this->resolveUrl($baseUrl, (string) $node->getAttribute('href'));

            if ($url === null || !$this->isDetailUrl($url)) {
                continue;
            }

            $links[$url] = $url;
        }

        if ($links === [] && !str_contains($pageText, 'encuentra tus beneficios')) {
            throw new CollectorParseException('Catalogo BancoEstado sin enlaces de detalle reconocibles.');
        }

        return array_values($links);
    }

    /**
     * @return array<int, CollectedPromotion>
     */
    public function parseDetail(string $html, string $sourceUrl): array
    {
        $this->assertNotBlocked($html, 'detalle BancoEstado');
        $xpath = CollectorHtmlHelper::xpath($html);
        $lines = $this->meaningfulLines($xpath);
        $bodyText = implode(' ', $lines);
        $comparableBody = $this->comparable($bodyText);

        if (!str_contains($comparableBody, 'bancoestado') || !str_contains($comparableBody, 'beneficio')) {
            throw new CollectorParseException('Estructura fundamental del detalle BancoEstado no encontrada.');
        }

        $merchantName = $this->merchantName($xpath, $sourceUrl, $lines);
        $baseSourceKey = $this->sourceKeyFromUrl($sourceUrl);
        $sections = $this->sections($lines);
        $detailBlocks = $this->detailBlocks($sections, $bodyText);

        if ($detailBlocks === []) {
            throw new CollectorParseException('Detalle BancoEstado sin bloque de promocion reconocible.');
        }

        $items = [];
        $vigencias = $sections['vigencia'] ?? [];
        $contextText = $this->join([
            $sections['donde'][0] ?? null,
            $sections['medios_de_pago'][0] ?? null,
            $bodyText,
        ]);

        foreach ($detailBlocks as $index => $detailText) {
            $discount = $this->discountHint($detailText);
            $discountText = $this->discountText($detailText, $discount);
            $vigencia = $vigencias[$index] ?? $vigencias[0] ?? null;
            $dates = $this->dates($this->join([$detailText, $vigencia]));
            $sourceKey = $this->sourceKey($baseSourceKey, $detailText, $vigencia, $index, count($detailBlocks));

            $items[$sourceKey] = new CollectedPromotion(
                sourceKey: $sourceKey,
                title: $merchantName . ' - ' . ($discountText ?? 'Beneficio BancoEstado'),
                sourceUrl: $this->safeSourceUrl($sourceUrl),
                merchantName: $merchantName,
                description: $this->limited($this->join([$detailText, $sections['donde'][0] ?? null, $vigencia]), 1200),
                discountText: $discountText ?? $detailText,
                discountTypeHint: $discount['type'],
                discountValueHint: $discount['value'],
                maxDiscountClp: $this->maxDiscountClp($this->join([$detailText, $bodyText])),
                promoCode: $this->promoCode($detailText),
                startsOnRaw: $dates['starts_on'],
                endsOnRaw: $dates['ends_on'],
                weekdaysRaw: $this->weekdaysRaw($detailText, $bodyText),
                benefitNamesRaw: $this->benefits($contextText),
                channelRaw: $this->join([$sections['donde'][0] ?? null, $detailText]),
                categoryRaw: $this->join([$merchantName, $detailText, $sections['donde'][0] ?? null]),
                terms: $this->limited($this->terms($lines), 5000),
            );
        }

        return array_values($items);
    }

    private function assertNotBlocked(string $html, string $context): void
    {
        $text = $this->comparable(strip_tags($html));

        if (str_contains($text, 'access denied') || str_contains($text, 'permission to access') || str_contains($text, 'pagina no encontrada')) {
            throw new CollectorParseException('BancoEstado bloqueo o no entrego el ' . $context . ' publico esperado.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulLines(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]');
        $lines = [];

        foreach ($nodes as $node) {
            $text = $this->text($node->textContent);

            if ($text !== null) {
                $lines[] = $text;
            }
        }

        $start = 0;

        foreach ($lines as $index => $line) {
            if (preg_match('/\b(usa tus|detalle|beneficios bancoestado)\b/iu', $line) === 1) {
                $start = max(0, $index - 6);
                break;
            }
        }

        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($index > $start && preg_match('/\b(existimos para|casa matriz bancoestado|siguenos|todos los derechos reservados)\b/iu', $line) === 1) {
                $end = $index;
                break;
            }
        }

        return array_values(array_slice($lines, $start, max(0, $end - $start)));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sections(array $lines): array
    {
        $sections = [];
        $current = null;

        foreach ($lines as $line) {
            $key = match ($this->comparable($line)) {
                'detalle' => 'detalle',
                'donde' => 'donde',
                'medios de pago' => 'medios_de_pago',
                'vigencia' => 'vigencia',
                default => null,
            };

            if ($key !== null) {
                $current = $key;
                $sections[$current] ??= [];
                continue;
            }

            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /**
     * @param array<string, array<int, string>> $sections
     * @return array<int, string>
     */
    private function detailBlocks(array $sections, string $bodyText): array
    {
        $details = [];

        foreach ($sections['detalle'] ?? [] as $line) {
            if ($this->looksLikeBenefit($line)) {
                $details[] = $line;
            }
        }

        if ($details !== []) {
            return array_values(array_unique($details));
        }

        if ($this->looksLikeBenefit($bodyText)) {
            return [$bodyText];
        }

        return [];
    }

    private function merchantName(\DOMXPath $xpath, string $sourceUrl, array $lines): string
    {
        $title = $this->text($xpath->evaluate('string(//title[1])'));

        if ($title !== null) {
            $title = preg_replace('/\s*\|\s*Beneficios.*$/iu', '', $title);
            $title = preg_replace('/\s*-\s*BancoEstado.*$/iu', '', (string) $title);
            $title = $this->text($title);

            if ($title !== null && !$this->looksLikeBankBoilerplate($title)) {
                return $title;
            }
        }

        foreach ($lines as $line) {
            if (!$this->looksLikeBankBoilerplate($line) && !$this->looksLikeBenefit($line) && mb_strlen($line, 'UTF-8') <= 80) {
                return $line;
            }
        }

        return $this->titleFromSlug($this->sourceKeyFromUrl($sourceUrl));
    }

    /**
     * @return array{type: ?string, value: int|float|null}
     */
    private function discountHint(string $text): array
    {
        $comparable = $this->comparable($text);

        if (preg_match('/\b(hasta|desde)\b/u', $comparable) === 1) {
            return ['type' => 'other', 'value' => null];
        }

        if (preg_match('/(?<!\d)(\d{1,3}(?:[,.]\d{1,2})?)\s*%/u', $text, $matches) === 1) {
            return ['type' => 'percentage', 'value' => (float) str_replace(',', '.', $matches[1])];
        }

        if (preg_match('/(?:\$\s*([0-9][0-9\.\,]*)|([0-9][0-9\.\,]*)\s*(?:pesos|clp))\s*(?:de\s+)?(?:dto|dcto|descuento)/iu', $text, $matches) === 1) {
            $amount = $matches[1] !== '' ? $matches[1] : $matches[2];

            return ['type' => 'fixed_amount', 'value' => (int) str_replace(['.', ','], '', $amount)];
        }

        if (preg_match('/(?:cuotas?\s+sin\s+inter[eé]s|precio\s+preferencial|pack\s+\w+\s+\$)/iu', $text) === 1) {
            return ['type' => 'other', 'value' => null];
        }

        return ['type' => null, 'value' => null];
    }

    /**
     * @param array{type: ?string, value: int|float|null} $discount
     */
    private function discountText(string $detailText, array $discount): ?string
    {
        if ($discount['type'] === 'percentage' && $discount['value'] !== null) {
            return rtrim(rtrim((string) $discount['value'], '0'), '.') . '% de descuento';
        }

        if ($discount['type'] === 'fixed_amount' && $discount['value'] !== null) {
            return '$' . number_format((int) $discount['value'], 0, ',', '.') . ' de descuento';
        }

        if (preg_match('/(?:\d+\s*a\s*\d+\s*)?cuotas?\s+sin\s+inter[eé]s/iu', $detailText, $matches) === 1) {
            return $matches[0];
        }

        return $this->text($detailText);
    }

    /**
     * @return array{starts_on: ?string, ends_on: ?string}
     */
    private function dates(string $text): array
    {
        $comparable = $this->comparable($text);

        if (preg_match('/(?:desde|valida\s+desde|vigencia\s+desde|oferta\s+valida\s+desde)\s+(?:el\s+)?(\d{1,2})\s+(?:de\s+)?([a-z]+)?\s*(?:de\s+)?(\d{4})?\s+(?:al|hasta)\s+(?:el\s+)?(\d{1,2})\s+(?:de\s+)?([a-z]+)\s+(?:de\s+)?(\d{4})/u', $comparable, $matches) === 1) {
            $startMonth = $matches[2] !== '' ? $matches[2] : $matches[5];
            $startYear = $matches[3] !== '' ? $matches[3] : $matches[6];

            return [
                'starts_on' => str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ' de ' . $startMonth . ' de ' . $startYear,
                'ends_on' => str_pad($matches[4], 2, '0', STR_PAD_LEFT) . ' de ' . $matches[5] . ' de ' . $matches[6],
            ];
        }

        if (preg_match('/(?:hasta|vigente\s+hasta)\s+(?:el\s+)?(\d{1,2})\s+(?:de\s+)?([a-z]+)\s+(?:de\s+)?(\d{4})/u', $comparable, $matches) === 1) {
            return [
                'starts_on' => null,
                'ends_on' => str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ' de ' . $matches[2] . ' de ' . $matches[3],
            ];
        }

        return ['starts_on' => null, 'ends_on' => null];
    }

    private function maxDiscountClp(string $text): ?int
    {
        if (preg_match('/(?:tope(?:\s+maximo)?(?:\s+de)?(?:\s+descuento)?|descuento\s+maximo)\s*(?:de)?\s*\$?\s*([0-9][0-9\.\,]*)/iu', $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $context = substr($text, max(0, $matches[1][1] - 40), 40);

        if (preg_match('/compra\s+minima/iu', $context) === 1 && preg_match('/tope|descuento\s+maximo/iu', $context) !== 1) {
            return null;
        }

        $amount = (int) str_replace(['.', ','], '', $matches[1][0]);

        return $amount > 0 ? $amount : null;
    }

    private function promoCode(string $text): ?string
    {
        if (preg_match('/\b(?:primeros|ultimos|d[ií]gitos|bin|rut)\b/iu', $text) === 1) {
            return null;
        }

        if (preg_match('/(?:c[oó]digo|cup[oó]n)\s+([A-Z0-9_-]{3,40})/iu', $text, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * @return array<int, string>|null
     */
    private function weekdaysRaw(string ...$texts): ?array
    {
        $values = [];

        foreach ($texts as $text) {
            $comparable = $this->comparable($text);

            if (preg_match('/\btodos\s+los\s+dias\b/u', $comparable) === 1) {
                $values[] = 'todos los dias';
                continue;
            }

            foreach ([
                'lunes' => 'lunes',
                'martes' => 'martes',
                'miercoles' => 'miercoles',
                'jueves' => 'jueves',
                'viernes' => 'viernes',
                'sabados?' => 'sabado',
                'domingos?' => 'domingo',
            ] as $pattern => $day) {
                if (preg_match('/\b' . $pattern . '\b/u', $comparable) === 1) {
                    $values[] = $day;
                }
            }
        }

        return $values === [] ? null : array_values(array_unique($values));
    }

    /**
     * @return array<int, string>|null
     */
    private function benefits(string $text): ?array
    {
        $benefits = [];
        $comparable = $this->comparable($text);
        $hasCredit = str_contains($comparable, 'credito');
        $hasDebit = str_contains($comparable, 'debito');
        $excludesCuentaRut = preg_match('/exclu(?:ye|ido|ida).{0,20}(?:cuentarut|cuenta\s+rut)/u', $comparable) === 1;
        $hasCuentaRut = !$excludesCuentaRut && (str_contains($comparable, 'cuentarut') || str_contains($comparable, 'cuenta rut'));
        $hasVisa = str_contains($comparable, 'visa');
        $hasMastercard = str_contains($comparable, 'mastercard') || str_contains($comparable, 'master card');

        if ($hasMastercard) {
            $benefits['bank_card|BancoEstado|Tarjetas Credito Mastercard BancoEstado|Credito'] = true;
        } elseif ($hasVisa) {
            $benefits['bank_card|BancoEstado|Tarjetas Credito Visa BancoEstado|Credito'] = true;
        } else {
            if ($hasDebit || ($hasCuentaRut && !$hasCredit)) {
                $benefits['bank_card|BancoEstado|Tarjetas Debito BancoEstado|Debito'] = true;
            }

            if ($hasCredit) {
                $benefits['bank_card|BancoEstado|Tarjetas Credito BancoEstado|Credito'] = true;
            }
        }

        if ($hasCuentaRut) {
            $benefits['bank_account|BancoEstado|CuentaRUT BancoEstado|CuentaRUT'] = true;
        }

        if ($benefits === [] && str_contains($comparable, 'tarjetas bancoestado')) {
            $benefits['bank_card|BancoEstado|Tarjetas Debito BancoEstado|Debito'] = true;
            $benefits['bank_card|BancoEstado|Tarjetas Credito BancoEstado|Credito'] = true;
        }

        return $benefits === [] ? null : array_keys($benefits);
    }

    private function terms(array $lines): string
    {
        return $this->join($lines);
    }

    private function sourceKey(string $baseSourceKey, string $detailText, ?string $vigencia, int $index, int $count): string
    {
        if ($count === 1) {
            return $baseSourceKey;
        }

        $suffix = $this->slug($this->join([$this->discountText($detailText, $this->discountHint($detailText)), $vigencia, $this->promoCode($detailText)]));

        return $baseSourceKey . '-' . ($suffix ?? 'beneficio-' . ($index + 1));
    }

    private function sourceKeyFromUrl(string $sourceUrl): string
    {
        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $basename = basename($path, '.html');
        $basename = preg_replace('/---beneficios-banco(?:estado)?(?:-personas)?$/iu', '', $basename);
        $basename = preg_replace('/---beneficios-bancoestado$/iu', '', (string) $basename);

        return $this->slug($basename) ?? sha1($sourceUrl);
    }

    private function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function safeSourceUrl(string $sourceUrl): ?string
    {
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        return in_array($host, self::ALLOWED_DETAIL_HOSTS, true) ? $sourceUrl : null;
    }

    private function isDetailUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array($host, self::ALLOWED_DETAIL_HOSTS, true)
            && str_contains($path, self::DETAIL_PATH_MARKER)
            && str_ends_with($path, '.html')
            && !str_ends_with($path, '/todos-beneficios.html');
    }

    private function resolveUrl(string $baseUrl, string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        if (parse_url($href, PHP_URL_SCHEME) !== null) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        $root = $base['scheme'] . '://' . $base['host'];

        if (str_starts_with($href, '/')) {
            return $root . $href;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

        return $root . $directory . $href;
    }

    private function looksLikeBenefit(string $text): bool
    {
        return preg_match('/(%|dcto|descuento|cuotas?\s+sin\s+inter[eé]s|precio\s+preferencial|cup[oó]n|off|\$\s*\d+)/iu', $text) === 1;
    }

    private function looksLikeBankBoilerplate(string $text): bool
    {
        return preg_match('/\b(bancoestado|solicita|estado de solicitud|inicio|beneficios bancoestado|banca en linea|personas)\b/iu', $text) === 1;
    }

    private function textFromXpath(\DOMXPath $xpath): string
    {
        return $this->join($this->meaningfulLines($xpath));
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

    /**
     * @param array<int, ?string> $values
     */
    private function join(array $values): string
    {
        return implode(' ', array_values(array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '')));
    }

    private function limited(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 3, 'UTF-8')) . '...';
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
