<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

interface DiscountCollectorInterface
{
    public function getKey(): string;

    public function getName(): string;

    /**
     * @return array<int, CollectedPromotion>
     */
    public function collect(CollectorContext $context): array;
}
