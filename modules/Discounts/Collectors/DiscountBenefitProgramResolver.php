<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountBenefitType;
use Modules\Discounts\DiscountTextNormalizer;

final class DiscountBenefitProgramResolver
{
    public function __construct(
        private readonly DiscountBenefitProgramRepository $programs,
        private readonly DiscountBenefitProgramService $programService,
        private readonly DiscountTextNormalizer $text = new DiscountTextNormalizer(),
    ) {
    }

    /**
     * @param array<int, string> $rawBenefits
     * @return array{ids: array<int, int>, resolved: array<int, array<string, mixed>>, unresolved: array<int, string>, warnings: array<int, string>}
     */
    public function resolveMany(array $rawBenefits, bool $allowCreate = true): array
    {
        $ids = [];
        $resolved = [];
        $unresolved = [];
        $warnings = [];

        foreach ($rawBenefits as $rawBenefit) {
            $rawBenefit = $this->text->cleanVisible($rawBenefit);

            if ($rawBenefit === null) {
                continue;
            }

            $program = $this->resolveStructured($rawBenefit, $allowCreate)
                ?? $this->resolveByComparableName($rawBenefit, $warnings);

            if ($program === null) {
                $unresolved[] = $rawBenefit;
                $warnings[] = 'Beneficio no resuelto: ' . $rawBenefit . '.';
                continue;
            }

            $id = (int) $program['id'];
            $ids[$id] = $id;
            $resolved[$id] = $program;
        }

        return [
            'ids' => array_values($ids),
            'resolved' => array_values($resolved),
            'unresolved' => array_values(array_unique($unresolved)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Structured raw format for trusted collectors:
     * type|provider|name|product
     *
     * @return array<string, mixed>|null
     */
    private function resolveStructured(string $rawBenefit, bool $allowCreate): ?array
    {
        $parts = array_map(fn (string $part): ?string => $this->text->cleanVisible($part), explode('|', $rawBenefit));

        if (count($parts) < 3) {
            return null;
        }

        [$type, $provider, $name] = $parts;
        $product = $parts[3] ?? null;
        $type = $type ?? '';

        if ($provider === null || $name === null || !in_array($type, DiscountBenefitType::TYPES, true)) {
            return null;
        }

        $normalizedProvider = $this->text->comparable($provider);
        $normalizedName = $this->text->comparable($name);
        $normalizedProduct = $this->text->comparable($product ?? '');
        $existing = $this->programs->findByNormalized($normalizedProvider, $normalizedName, $type, $normalizedProduct);

        if ($existing !== null && (int) ($existing['active'] ?? 0) === 1) {
            return $existing;
        }

        if (!$allowCreate) {
            return null;
        }

        return $this->programService->createOrFind([
            'provider_name' => $provider,
            'name' => $name,
            'benefit_type' => $type,
            'product_name' => $product,
            'active' => true,
        ]);
    }

    /**
     * @param array<int, string> $warnings
     * @return array<string, mixed>|null
     */
    private function resolveByComparableName(string $rawBenefit, array &$warnings): ?array
    {
        $target = $this->text->comparable($rawBenefit);
        $matches = [];

        foreach ($this->programs->list(['active' => true]) as $program) {
            $variants = [
                (string) ($program['name'] ?? ''),
                trim((string) ($program['provider_name'] ?? '') . ' ' . (string) ($program['name'] ?? '')),
                trim((string) ($program['provider_name'] ?? '') . ' ' . (string) ($program['name'] ?? '') . ' ' . (string) ($program['product_name'] ?? '')),
            ];

            foreach ($variants as $variant) {
                if ($this->text->comparable($variant) === $target) {
                    $matches[(int) $program['id']] = $program;
                }
            }
        }

        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        if (count($matches) > 1) {
            $warnings[] = 'Beneficio ambiguo no asociado: ' . $rawBenefit . '.';
        }

        return null;
    }
}
