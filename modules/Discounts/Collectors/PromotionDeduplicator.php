<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountPromotionRepository;

final class PromotionDeduplicator
{
    public function __construct(private readonly DiscountPromotionRepository $promotions)
    {
    }

    public function decide(NormalizedPromotion $promotion, string $fingerprint): PromotionDeduplicationDecision
    {
        $identityMatch = $this->promotions->findCollectorByIdentity($promotion->collectorKey(), $promotion->sourceKey());

        if ($identityMatch !== null) {
            return PromotionDeduplicationDecision::update((int) $identityMatch['id'], 'identity');
        }

        $fingerprintMatch = $this->promotions->findCollectorByFingerprint($promotion->collectorKey(), $fingerprint);

        if ($fingerprintMatch !== null) {
            return PromotionDeduplicationDecision::update((int) $fingerprintMatch['id'], 'fingerprint', [
                'Promocion emparejada por fingerprint dentro del mismo collector.',
            ]);
        }

        $warnings = [];

        foreach ($this->promotions->listByFingerprint($fingerprint) as $candidate) {
            if (($candidate['source_type'] ?? '') === 'manual') {
                $warnings[] = 'Posible duplicado con promocion manual; no se sobrescribio.';
                continue;
            }

            if (($candidate['collector_key'] ?? '') !== $promotion->collectorKey()) {
                $warnings[] = 'Posible duplicado cross-source; no se fusiono automaticamente.';
            }
        }

        return PromotionDeduplicationDecision::create(array_values(array_unique($warnings)));
    }
}
