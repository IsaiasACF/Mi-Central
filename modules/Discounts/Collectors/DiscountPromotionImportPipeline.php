<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountPromotionRepository;
use Throwable;

final class DiscountPromotionImportPipeline implements DiscountPromotionImporter
{
    public function __construct(
        private readonly PromotionNormalizer $normalizer,
        private readonly PromotionFingerprintService $fingerprints,
        private readonly PromotionDeduplicator $deduplicator,
        private readonly DiscountMerchantResolver $merchantResolver,
        private readonly DiscountBenefitProgramResolver $benefitResolver,
        private readonly DiscountPromotionRepository $promotions,
    ) {
    }

    public function import(CollectorResult $collectorResult, bool $dryRun = false): PromotionImportResult
    {
        $collectorKey = $collectorResult->collectorKey();
        $warnings = $collectorResult->warnings();
        $errors = [];
        $normalizedItems = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates = 0;

        if (!$collectorResult->success()) {
            $errors[] = $collectorResult->errorMessage() ?? 'Collector fallo.';

            return new PromotionImportResult($collectorKey, count($collectorResult->items()), 0, 0, 0, 0, 0, $warnings, $errors, $dryRun);
        }

        foreach ($collectorResult->items() as $item) {
            try {
                $normalized = $this->normalizer->normalize($collectorKey, $item);
                $fingerprint = $this->fingerprints->fingerprint($normalized);
                $normalizedItems[] = [
                    'promotion' => $normalized,
                    'fingerprint' => $fingerprint,
                ];
                array_push($warnings, ...$this->prefixedWarnings($normalized, $normalized->warnings()));
            } catch (Throwable $exception) {
                $skipped++;
                $errors[] = $this->safeError($item->sourceKey(), $exception);
            }
        }

        $normalizedCount = count($normalizedItems);
        $normalizedItems = $this->dedupeBatch($normalizedItems, $duplicates, $warnings, $skipped);

        foreach ($normalizedItems as $item) {
            /** @var NormalizedPromotion $promotion */
            $promotion = $item['promotion'];
            $fingerprint = (string) $item['fingerprint'];

            try {
                $decision = $this->deduplicator->decide($promotion, $fingerprint);
                array_push($warnings, ...$this->prefixedWarnings($promotion, $decision->warnings()));

                if ($dryRun) {
                    if ($decision->action() === 'update') {
                        $updated++;
                    } else {
                        $created++;
                    }

                    continue;
                }

                $this->promotions->transaction(function () use ($promotion, $fingerprint, $decision, &$created, &$updated, &$warnings, &$skipped): void {
                    $merchant = $this->merchantResolver->resolve($promotion->merchantName(), $promotion->merchantCategory(), true);
                    $benefits = $this->benefitResolver->resolveMany($promotion->benefits(), true);
                    array_push($warnings, ...$this->prefixedWarnings($promotion, $benefits['warnings']));

                    if ($promotion->hasRawBenefits() && $benefits['ids'] === []) {
                        $skipped++;
                        throw new CollectorParseException('Beneficios requeridos no resueltos; no se importo como promocion general.');
                    }

                    $data = $promotion->toPromotionData($merchant === null ? null : (int) $merchant['id'], $fingerprint);

                    if ($decision->action() === 'update') {
                        $promotionId = (int) $decision->promotionId();
                        $this->promotions->updateCollector($promotionId, $data);
                        $this->promotions->replaceBenefits($promotionId, $benefits['ids']);
                        $this->promotions->replaceWeekdays($promotionId, $promotion->weekdays());
                        $updated++;

                        return;
                    }

                    $promotionId = $this->promotions->create($data);
                    $this->promotions->replaceBenefits($promotionId, $benefits['ids']);
                    $this->promotions->replaceWeekdays($promotionId, $promotion->weekdays());
                    $created++;
                });
            } catch (Throwable $exception) {
                if (!($exception instanceof CollectorParseException && str_contains($exception->getMessage(), 'Beneficios requeridos no resueltos'))) {
                    $skipped++;
                }

                $errors[] = $this->safeError($promotion->sourceKey(), $exception);
            }
        }

        return new PromotionImportResult(
            $collectorKey,
            count($collectorResult->items()),
            $normalizedCount,
            $created,
            $updated,
            $skipped,
            $duplicates,
            array_values(array_unique($warnings)),
            $errors,
            $dryRun,
        );
    }

    /**
     * @param array<int, array{promotion: NormalizedPromotion, fingerprint: string}> $items
     * @param array<int, string> $warnings
     * @return array<int, array{promotion: NormalizedPromotion, fingerprint: string}>
     */
    private function dedupeBatch(array $items, int &$duplicates, array &$warnings, int &$skipped): array
    {
        $seenSources = [];
        $seenFingerprints = [];
        $deduped = [];

        foreach ($items as $item) {
            $promotion = $item['promotion'];
            $sourceKey = $promotion->sourceKey();
            $fingerprint = $item['fingerprint'];

            if (isset($seenSources[$sourceKey]) || isset($seenFingerprints[$fingerprint])) {
                $duplicates++;
                $skipped++;
                $warnings[] = 'Duplicate item in collector batch: ' . $sourceKey . '.';
                continue;
            }

            $seenSources[$sourceKey] = true;
            $seenFingerprints[$fingerprint] = true;
            $deduped[] = $item;
        }

        return $deduped;
    }

    /**
     * @param array<int, string> $warnings
     * @return array<int, string>
     */
    private function prefixedWarnings(NormalizedPromotion $promotion, array $warnings): array
    {
        return array_map(
            static fn (string $warning): string => $promotion->sourceKey() . ': ' . $warning,
            $warnings,
        );
    }

    private function safeError(string $sourceKey, Throwable $exception): string
    {
        $message = trim(strip_tags($exception->getMessage()));

        if ($message === '') {
            $message = 'Item no pudo importarse.';
        }

        return $sourceKey . ': ' . substr($message, 0, 500);
    }
}
