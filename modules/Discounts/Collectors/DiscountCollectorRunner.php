<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DiscountCollectorRunner implements DiscountCollectorRunService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly DiscountCollectorRegistry $registry,
        private readonly CollectorHttpClient $httpClient,
        private readonly string $timezone = 'America/Santiago',
        private readonly array $config = [],
    ) {
    }

    public function run(string $collectorKey): CollectorResult
    {
        $startedAt = $this->now();

        try {
            $collector = $this->registry->get($collectorKey);
            $context = CollectorContext::create(
                $this->timezone,
                $this->httpClient,
                is_array($this->config[$collectorKey] ?? null) ? $this->config[$collectorKey] : [],
            );
            $items = $collector->collect($context);

            foreach ($items as $item) {
                if (!$item instanceof CollectedPromotion) {
                    throw new CollectorParseException('Collector devolvio un item invalido.');
                }
            }

            return CollectorResult::ok($collector->getKey(), $startedAt, $this->now(), array_values($items));
        } catch (CollectorException $exception) {
            return CollectorResult::failed(
                DiscountCollectorRegistry::isValidKey($collectorKey) ? $collectorKey : 'invalid_collector',
                $startedAt,
                $this->now(),
                $this->shortClass($exception),
                $this->safeMessage($exception->getMessage()),
            );
        } catch (Throwable $exception) {
            return CollectorResult::failed(
                DiscountCollectorRegistry::isValidKey($collectorKey) ? $collectorKey : 'invalid_collector',
                $startedAt,
                $this->now(),
                $this->shortClass($exception),
                'Fallo inesperado del collector.',
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        $timezone = $this->timezone !== '' ? $this->timezone : 'America/Santiago';

        return new DateTimeImmutable('now', new DateTimeZone($timezone));
    }

    private function shortClass(Throwable $exception): string
    {
        $parts = explode('\\', $exception::class);

        return (string) end($parts);
    }

    private function safeMessage(string $message): string
    {
        $message = trim(strip_tags($message));

        if ($message === '') {
            return 'El collector fallo.';
        }

        return substr($message, 0, 500);
    }
}
