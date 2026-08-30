<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

interface DiscountCollectorRunService
{
    public function run(string $collectorKey): CollectorResult;
}
