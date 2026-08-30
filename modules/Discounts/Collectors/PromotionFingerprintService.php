<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountTextNormalizer;

final class PromotionFingerprintService
{
    public function __construct(private readonly DiscountTextNormalizer $text = new DiscountTextNormalizer())
    {
    }

    public function fingerprint(NormalizedPromotion $promotion): string
    {
        $weekdays = $promotion->weekdays();
        sort($weekdays);
        $benefits = array_map(fn (string $benefit): string => $this->text->comparable($benefit), $promotion->benefits());
        sort($benefits);

        return hash('sha256', json_encode([
            'merchant' => $this->text->comparable($promotion->merchantName()),
            'title' => $this->text->comparable($promotion->title()),
            'discount_type' => $promotion->discountType(),
            'discount_value' => $promotion->discountValue(),
            'starts_on' => $promotion->startsOn(),
            'ends_on' => $promotion->endsOn(),
            'weekdays' => $weekdays,
            'benefits' => $benefits,
            'channel' => $promotion->channel(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
