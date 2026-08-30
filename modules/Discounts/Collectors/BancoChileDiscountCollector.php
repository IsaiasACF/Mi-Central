<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class BancoChileDiscountCollector implements DiscountCollectorInterface
{
    public const KEY = 'banco_chile';
    public const NAME = 'Banco de Chile - Beneficios';

    private const BASE_URL = 'https://sitiospublicos.bancochile.cl';
    private const ENTRIES_PATH = '/api/content/spaces/personas/types/beneficios/entries';
    private const ALLOWED_HOSTS = ['sitiospublicos.bancochile.cl'];
    private const PER_PAGE = 100;

    public function __construct(private readonly BancoChileBenefitsParser $parser = new BancoChileBenefitsParser())
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
        $items = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $context->httpClient()->get($this->pageUrl($page), self::ALLOWED_HOSTS, [
                'Accept' => 'application/json,text/plain;q=0.9,*/*;q=0.5',
            ]);
            $parsed = $this->parser->parsePage($response->body());
            $totalPages = max(1, $parsed['total_pages']);

            foreach ($parsed['items'] as $item) {
                $items[$item->sourceKey()] = $item;

                if ($maxItems > 0 && count($items) >= $maxItems) {
                    break 2;
                }
            }

            $page++;

            if ($page <= $totalPages && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        } while ($page <= $totalPages);

        return array_values($items);
    }

    private function pageUrl(int $page): string
    {
        return self::BASE_URL . self::ENTRIES_PATH . '?' . http_build_query([
            'per_page' => self::PER_PAGE,
            'page' => $page,
        ]);
    }
}
