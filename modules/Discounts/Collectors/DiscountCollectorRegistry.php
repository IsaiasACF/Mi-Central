<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class DiscountCollectorRegistry
{
    /**
     * @var array<string, DiscountCollectorInterface>
     */
    private array $collectors = [];

    /**
     * @param array<int, DiscountCollectorInterface>|null $collectors
     */
    public function __construct(?array $collectors = null)
    {
        $collectors = $collectors ?? self::defaultCollectors();

        foreach ($collectors as $collector) {
            $this->register($collector);
        }
    }

    /**
     * @return array<int, DiscountCollectorInterface>
     */
    public static function defaultCollectors(): array
    {
        return [
            new BancoChileDiscountCollector(),
            new SantanderChileDiscountCollector(),
            new BancoEstadoDiscountCollector(),
        ];
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_]{1,80}\z/', $key) === 1;
    }

    public function register(DiscountCollectorInterface $collector): void
    {
        $key = $collector->getKey();

        if (!self::isValidKey($key)) {
            throw new CollectorConfigurationException('Collector key invalida: ' . $key . '.');
        }

        if (isset($this->collectors[$key])) {
            throw new CollectorConfigurationException('Collector duplicado: ' . $key . '.');
        }

        $this->collectors[$key] = $collector;
    }

    public function get(string $key): DiscountCollectorInterface
    {
        if (!self::isValidKey($key)) {
            throw new CollectorConfigurationException('Collector key invalida.');
        }

        if (!isset($this->collectors[$key])) {
            throw new CollectorConfigurationException('Collector no registrado: ' . $key . '.');
        }

        return $this->collectors[$key];
    }

    public function has(string $key): bool
    {
        return self::isValidKey($key) && isset($this->collectors[$key]);
    }

    /**
     * @return array<string, DiscountCollectorInterface>
     */
    public function all(): array
    {
        return $this->collectors;
    }
}
