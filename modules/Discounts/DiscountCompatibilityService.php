<?php
declare(strict_types=1);

namespace Modules\Discounts;

final class DiscountCompatibilityService
{
    public const STATUS_GENERAL = 'general';
    public const STATUS_COMPATIBLE = 'compatible';
    public const STATUS_INCOMPATIBLE = 'incompatible';

    public function __construct(
        private readonly DiscountPromotionRepository $promotions,
        private readonly UserDiscountBenefitRepository $userBenefits,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function getActiveUserBenefitIds(int $userId): array
    {
        $benefits = $this->activeUserBenefitsByProgram($userId);

        return array_values(array_map('intval', array_keys($benefits)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function evaluatePromotionForUser(int $promotionId, int $userId): ?array
    {
        $promotion = $this->promotions->findById($this->positiveId($promotionId));

        if ($promotion === null) {
            return null;
        }

        $requirements = $this->promotions->listBenefits((int) $promotion['id']);
        $userBenefits = $this->activeUserBenefitsByProgram($userId);

        return $this->evaluateRequirements($requirements, $userBenefits);
    }

    /**
     * @param array<int, array<string, mixed>> $promotions
     * @return array<int, array<string, mixed>>
     */
    public function evaluatePromotionsForUser(int $userId, array $promotions): array
    {
        if ($promotions === []) {
            return [];
        }

        $promotionIds = array_map(static fn (array $promotion): int => (int) $promotion['id'], $promotions);
        $requirementsByPromotion = $this->promotions->listBenefitsForPromotions($promotionIds);
        $userBenefits = $this->activeUserBenefitsByProgram($userId);
        $evaluated = [];

        foreach ($promotions as $promotion) {
            $promotion['compatibility'] = $this->evaluateRequirements($requirementsByPromotion[(int) $promotion['id']] ?? [], $userBenefits);
            $evaluated[] = $promotion;
        }

        return $evaluated;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getCompatiblePromotionsForUser(int $userId, array $filters = []): array
    {
        return $this->compatiblePromotions($userId, $filters, false);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getCompatibleActivePromotionsForUser(int $userId, array $filters = []): array
    {
        return $this->compatiblePromotions($userId, $filters, true);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function compatiblePromotions(int $userId, array $filters, bool $activeOnly): array
    {
        $promotions = $this->promotions->listForCompatibility($userId, $filters, $activeOnly);

        if ($promotions === []) {
            return [];
        }

        $evaluated = $this->evaluatePromotionsForUser($userId, $promotions);
        $compatible = [];

        foreach ($evaluated as $promotion) {
            if (($promotion['compatibility']['status'] ?? self::STATUS_INCOMPATIBLE) === self::STATUS_INCOMPATIBLE) {
                continue;
            }

            $compatible[] = $promotion;
        }

        return $compatible;
    }

    /**
     * @param array<int, array<string, mixed>> $requirements
     * @param array<int, array<string, mixed>> $userBenefits
     * @return array<string, mixed>
     */
    private function evaluateRequirements(array $requirements, array $userBenefits): array
    {
        if ($requirements === []) {
            return [
                'status' => self::STATUS_GENERAL,
                'required_benefits' => [],
                'matched_benefits' => [],
                'reason' => 'Disponible sin un beneficio especifico.',
            ];
        }

        $required = [];
        $matched = [];

        foreach ($requirements as $requirement) {
            $benefitProgramId = (int) ($requirement['id'] ?? 0);

            if ($benefitProgramId <= 0) {
                continue;
            }

            $required[] = $this->benefitPayload($requirement);

            if ((int) ($requirement['active'] ?? 0) !== 1 || !isset($userBenefits[$benefitProgramId])) {
                continue;
            }

            $matched[] = $this->benefitPayload($requirement);
        }

        if ($matched !== []) {
            return [
                'status' => self::STATUS_COMPATIBLE,
                'required_benefits' => $required,
                'matched_benefits' => $matched,
                'reason' => $this->compatibleReason($matched),
            ];
        }

        return [
            'status' => self::STATUS_INCOMPATIBLE,
            'required_benefits' => $required,
            'matched_benefits' => [],
            'reason' => $this->incompatibleReason($required),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeUserBenefitsByProgram(int $userId): array
    {
        $rows = $this->userBenefits->listForUser($userId, ['active' => true]);
        $benefits = [];

        foreach ($rows as $row) {
            if ((int) ($row['benefit_program_active'] ?? 0) !== 1) {
                continue;
            }

            $benefits[(int) $row['benefit_program_id']] = $row;
        }

        return $benefits;
    }

    /**
     * @return array<string, mixed>
     */
    private function benefitPayload(array $benefit): array
    {
        $name = is_string($benefit['name'] ?? null)
            ? (string) $benefit['name']
            : (string) ($benefit['benefit_name'] ?? '');

        return [
            'id' => (int) ($benefit['id'] ?? $benefit['benefit_program_id'] ?? 0),
            'provider_name' => (string) ($benefit['provider_name'] ?? ''),
            'name' => $name,
            'benefit_type' => (string) ($benefit['benefit_type'] ?? ''),
            'product_name' => $benefit['product_name'] ?? null,
            'active' => (int) ($benefit['active'] ?? $benefit['benefit_program_active'] ?? 0),
            'label' => $this->benefitLabel($benefit),
        ];
    }

    private function compatibleReason(array $matched): string
    {
        $labels = array_map(fn (array $benefit): string => (string) $benefit['label'], $matched);

        if (count($labels) === 1) {
            return 'Compatible con tu beneficio: ' . $labels[0] . '.';
        }

        return 'Compatible con ' . count($labels) . ' de tus beneficios: ' . $this->joinLabels($labels) . '.';
    }

    private function incompatibleReason(array $required): string
    {
        $labels = array_map(fn (array $benefit): string => (string) $benefit['label'], $required);

        if ($labels === []) {
            return 'No tienes actualmente ninguno de los beneficios requeridos.';
        }

        return 'No tienes actualmente ninguno de los beneficios requeridos. Requiere ' . $this->joinLabels($labels) . '.';
    }

    private function benefitLabel(array $benefit): string
    {
        $name = is_string($benefit['name'] ?? null)
            ? trim((string) $benefit['name'])
            : trim((string) ($benefit['benefit_name'] ?? ''));
        $provider = trim((string) ($benefit['provider_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $provider !== '' ? $provider : 'Beneficio';
    }

    /**
     * @param array<int, string> $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));

        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' y ' . $last;
    }

    private function positiveId(int $value): int
    {
        if ($value <= 0) {
            throw new DiscountValidationException('Identificador invalido.');
        }

        return $value;
    }
}
