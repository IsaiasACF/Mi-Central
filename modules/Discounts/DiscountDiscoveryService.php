<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDOException;

final class DiscountDiscoveryService
{
    public function __construct(
        private readonly DiscountPromotionRepository $promotions,
        private readonly DiscountCompatibilityService $compatibility,
        private readonly DiscountPromotionAvailabilityService $availability,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function forMe(int $userId, array $filters = []): array
    {
        $promotions = $this->compatibility->getCompatibleActivePromotionsForUser($userId, $this->filters($filters));
        $promotions = $this->enrich($userId, $promotions);
        $promotions = array_values(array_filter($promotions, function (array $promotion): bool {
            return $promotion['availability_state'] !== 'Vencida' && $promotion['availability_state'] !== 'Inactiva';
        }));

        usort($promotions, fn (array $a, array $b): int => $this->forMeRank($a) <=> $this->forMeRank($b) ?: strcmp((string) ($a['ends_on'] ?? '9999-12-31'), (string) ($b['ends_on'] ?? '9999-12-31')));

        return $promotions;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function today(int $userId, bool $onlyForMe, array $filters = []): array
    {
        $promotions = $this->promotions->listForCompatibility($userId, $this->filters($filters), true);
        $promotions = $this->enrich($userId, $this->compatibility->evaluatePromotionsForUser($userId, $promotions));
        $promotions = array_values(array_filter($promotions, function (array $promotion) use ($onlyForMe): bool {
            if (!$promotion['applicable_today']) {
                return false;
            }

            return !$onlyForMe || ($promotion['compatibility']['status'] ?? DiscountCompatibilityService::STATUS_INCOMPATIBLE) !== DiscountCompatibilityService::STATUS_INCOMPATIBLE;
        }));

        usort($promotions, fn (array $a, array $b): int => $this->compatibilityRank($a) <=> $this->compatibilityRank($b));

        return $promotions;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function all(int $userId, array $filters = []): array
    {
        $promotions = $this->promotions->listForCompatibility($userId, $this->filters($filters), false);
        $promotions = $this->enrich($userId, $this->compatibility->evaluatePromotionsForUser($userId, $promotions));
        $state = is_string($filters['state'] ?? null) ? (string) $filters['state'] : 'available';

        if (!in_array($state, ['available', 'current', 'upcoming', 'expired', 'all'], true)) {
            $state = 'available';
        }

        if ($state !== 'all') {
            $promotions = array_values(array_filter($promotions, static function (array $promotion) use ($state): bool {
                $availability = (string) ($promotion['availability_state'] ?? '');

                if ($state === 'current') {
                    return $availability === 'Vigente' || $availability === 'Sin vigencia definida';
                }

                if ($state === 'upcoming') {
                    return $availability === 'Proximamente';
                }

                if ($state === 'expired') {
                    return $availability === 'Vencida';
                }

                return $availability !== 'Vencida' && $availability !== 'Inactiva';
            }));
        }

        usort($promotions, fn (array $a, array $b): int => $this->allRank($a) <=> $this->allRank($b));

        return $promotions;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function favorites(int $userId, array $filters = []): array
    {
        $promotions = $this->promotions->listFavoritesVisibleForUser($userId, $this->filters($filters));
        $promotions = $this->enrich($userId, $this->compatibility->evaluatePromotionsForUser($userId, $promotions));

        usort($promotions, fn (array $a, array $b): int => $this->allRank($a) <=> $this->allRank($b));

        return $promotions;
    }

    public function setFavorite(int $userId, int $promotionId, bool $favorite): bool
    {
        $promotionId = $this->positiveId($promotionId);

        if ($this->promotions->findVisibleForUser($userId, $promotionId) === null) {
            throw new DiscountValidationException('Promocion no encontrada.');
        }

        if (!$favorite) {
            $this->promotions->removeFavorite($userId, $promotionId);

            return false;
        }

        try {
            if (!$this->promotions->favoriteExists($userId, $promotionId)) {
                $this->promotions->addFavorite($userId, $promotionId);
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $promotions
     * @return array<int, array<string, mixed>>
     */
    private function enrich(int $userId, array $promotions): array
    {
        if ($promotions === []) {
            return [];
        }

        $promotionIds = array_map(static fn (array $promotion): int => (int) $promotion['id'], $promotions);
        $weekdaysByPromotion = $this->promotions->listWeekdaysForPromotions($promotionIds);
        $requirementsByPromotion = $this->promotionsHaveBenefits($promotions)
            ? []
            : $this->promotions->listBenefitsForPromotions($promotionIds);
        $favoriteIds = array_fill_keys($this->promotions->listFavoriteIdsForUser($userId), true);

        foreach ($promotions as &$promotion) {
            $promotionId = (int) $promotion['id'];
            $promotion['weekdays'] = $weekdaysByPromotion[$promotionId] ?? [];
            $promotion['benefits'] = is_array($promotion['benefits'] ?? null)
                ? $promotion['benefits']
                : ($requirementsByPromotion[$promotionId] ?? []);
            $promotion['is_favorite'] = isset($favoriteIds[$promotionId]);
            $promotion['discount_label'] = DiscountPromotionFormat::discountLabel($promotion);
            $promotion['validity_label'] = DiscountPromotionFormat::validityLabel($promotion);
            $promotion['availability_state'] = $this->availability->temporalState($promotion);
            $promotion['applicable_today'] = $this->availability->isApplicableToday($promotion, $promotion['weekdays']);
        }
        unset($promotion);

        return $promotions;
    }

    /**
     * @param array<int, array<string, mixed>> $promotions
     */
    private function promotionsHaveBenefits(array $promotions): bool
    {
        foreach ($promotions as $promotion) {
            if (!array_key_exists('benefits', $promotion) || !is_array($promotion['benefits'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function filters(array $filters): array
    {
        $normalized = [];

        foreach (['merchant_id', 'benefit_program_id'] as $field) {
            if (($filters[$field] ?? '') !== '' && (int) ($filters[$field] ?? 0) > 0) {
                $normalized[$field] = (int) $filters[$field];
            }
        }

        if (is_string($filters['channel'] ?? null) && in_array($filters['channel'], ['in_store', 'online', 'both'], true)) {
            $normalized['channel'] = (string) $filters['channel'];
        }

        if (is_string($filters['category'] ?? null) && DiscountPromotionCategory::isValid((string) $filters['category'])) {
            $normalized['category'] = (string) $filters['category'];
        }

        if (is_string($filters['search'] ?? null)) {
            $normalized['search'] = trim((string) $filters['search']);
        }

        if (is_string($filters['state'] ?? null)) {
            $normalized['state'] = (string) $filters['state'];
        }

        $normalized['today'] = date('Y-m-d');

        if (is_string($filters['collector_key'] ?? null) && preg_match('/\A[a-z0-9_\\-]+\z/', (string) $filters['collector_key']) === 1) {
            $normalized['collector_key'] = (string) $filters['collector_key'];
        }

        return $normalized;
    }

    private function forMeRank(array $promotion): int
    {
        if ($promotion['applicable_today'] ?? false) {
            return 0;
        }

        if (($promotion['availability_state'] ?? '') === 'Vigente' || ($promotion['availability_state'] ?? '') === 'Sin vigencia definida') {
            return 1;
        }

        return 2;
    }

    private function allRank(array $promotion): int
    {
        if (($promotion['availability_state'] ?? '') === 'Vigente' || ($promotion['availability_state'] ?? '') === 'Sin vigencia definida') {
            return 0;
        }

        if (($promotion['availability_state'] ?? '') === 'Proximamente') {
            return 1;
        }

        return 2;
    }

    private function compatibilityRank(array $promotion): int
    {
        return match ((string) ($promotion['compatibility']['status'] ?? 'incompatible')) {
            DiscountCompatibilityService::STATUS_GENERAL => 0,
            DiscountCompatibilityService::STATUS_COMPATIBLE => 1,
            default => 2,
        };
    }

    private function positiveId(int $id): int
    {
        if ($id <= 0) {
            throw new DiscountValidationException('Promocion no encontrada.');
        }

        return $id;
    }
}
