<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

interface DiscountPromotionImporter
{
    public function import(CollectorResult $collectorResult, bool $dryRun = false): PromotionImportResult;
}
