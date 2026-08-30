<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class SantanderChileDiscountCollector implements DiscountCollectorInterface
{
    public const KEY = 'santander_chile';
    public const NAME = 'Santander Chile - Beneficios';

    private const BASE_URL = 'https://banco.santander.cl';
    private const CATALOG_PATH = '/beneficios/promociones.json';
    private const ALLOWED_HOSTS = ['banco.santander.cl'];

    public function __construct(private readonly SantanderChileBenefitsParser $parser = new SantanderChileBenefitsParser())
    {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return array<int, CollectedPromotion>
     */
    public function collect(CollectorContext $context): array
    {
        $config = $context->config();
        $maxItems = max(0, (int) ($config['max_items'] ?? 0));
        $response = $context->httpClient()->get($this->catalogUrl(), self::ALLOWED_HOSTS, [
            'User-Agent' => $this->userAgent(),
            'Accept' => 'application/json,text/plain;q=0.9,*/*;q=0.5',
        ]);
        $parsed = $this->parser->parseCatalog($response->body());
        $items = [];

        foreach ($parsed['items'] as $item) {
            $items[$item->sourceKey()] = $item;

            if ($maxItems > 0 && count($items) >= $maxItems) {
                break;
            }
        }

        return array_values($items);
    }

    private function userAgent(): string
    {
        $version = function_exists('curl_version') ? curl_version() : null;
        $curlVersion = is_array($version) && is_string($version['version'] ?? null) ? $version['version'] : '8';

        return 'curl/' . $curlVersion . ' MiCentral-DiscountCollector/1.0';
    }

    private function catalogUrl(): string
    {
        return self::BASE_URL . self::CATALOG_PATH . '?' . http_build_query([
            'per_page' => 500,
            'tags' => 'home-disfrutadores',
            'custom_fields' => 'true',
            'order_by' => 'updated_at',
            'desc' => 'true',
            'hash' => 1,
        ]);
    }
}
