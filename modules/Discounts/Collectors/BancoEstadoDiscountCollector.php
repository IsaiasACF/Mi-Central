<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class BancoEstadoDiscountCollector implements DiscountCollectorInterface
{
    public const KEY = 'bancoestado';
    public const NAME = 'BancoEstado - Beneficios';

    private const CATALOG_URL = BancoEstadoBenefitsParser::CATALOG_URL;
    private const ALLOWED_HOSTS = ['www.bancoestado.cl', 'investor.bancoestado.cl'];

    public function __construct(private readonly BancoEstadoBenefitsParser $parser = new BancoEstadoBenefitsParser())
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
        $delayMs = max(0, (int) ($config['request_delay_ms'] ?? 500));
        $catalog = $context->httpClient()->get(self::CATALOG_URL, self::ALLOWED_HOSTS, [
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
        ]);
        $detailUrls = $this->uniqueUrls($this->parser->parseCatalogLinks($catalog->body(), self::CATALOG_URL));
        $items = [];

        foreach ($detailUrls as $index => $detailUrl) {
            $detail = $context->httpClient()->get($detailUrl, self::ALLOWED_HOSTS, [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
            ]);

            foreach ($this->parser->parseDetail($detail->body(), $detailUrl) as $item) {
                $items[$item->sourceKey()] = $item;

                if ($maxItems > 0 && count($items) >= $maxItems) {
                    break 2;
                }
            }

            if ($delayMs > 0 && $index < count($detailUrls) - 1) {
                usleep($delayMs * 1000);
            }
        }

        return array_values($items);
    }

    /**
     * @param array<int, string> $urls
     * @return array<int, string>
     */
    private function uniqueUrls(array $urls): array
    {
        $unique = [];

        foreach ($urls as $url) {
            $unique[$url] = $url;
        }

        return array_values($unique);
    }
}
