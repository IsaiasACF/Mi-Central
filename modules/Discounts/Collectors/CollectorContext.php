<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DateTimeImmutable;
use DateTimeZone;

final class CollectorContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly DateTimeImmutable $now,
        private readonly string $timezone,
        private readonly CollectorHttpClient $httpClient,
        private readonly array $config = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(string $timezone, CollectorHttpClient $httpClient, array $config = []): self
    {
        $timezone = $timezone !== '' ? $timezone : 'America/Santiago';

        return new self(new DateTimeImmutable('now', new DateTimeZone($timezone)), $timezone, $httpClient, $config);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function httpClient(): CollectorHttpClient
    {
        return $this->httpClient;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
