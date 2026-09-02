<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\CollectedPromotion;
use Modules\Discounts\Collectors\CollectorResult;
use Modules\Discounts\Collectors\DiscountBenefitProgramResolver;
use Modules\Discounts\Collectors\DiscountMerchantResolver;
use Modules\Discounts\Collectors\DiscountPromotionImportPipeline;
use Modules\Discounts\Collectors\PromotionDeduplicator;
use Modules\Discounts\Collectors\PromotionFingerprintService;
use Modules\Discounts\Collectors\PromotionNormalizer;
use Modules\Discounts\Collectors\CollectorParseException;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountCompatibilityService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\DiscountTextNormalizer;
use Modules\Discounts\UserDiscountBenefitRepository;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$promotionIds = [];
$merchantIds = [];
$programIds = [];
$exitCode = 1;

function discount_import_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discount_import_pipeline(
    PDO $pdo,
    DiscountPromotionRepository $promotionRepository,
    DiscountMerchantRepository $merchantRepository,
    DiscountBenefitProgramRepository $programRepository,
): DiscountPromotionImportPipeline {
    $text = new DiscountTextNormalizer();

    return new DiscountPromotionImportPipeline(
        new PromotionNormalizer($text),
        new PromotionFingerprintService($text),
        new PromotionDeduplicator($promotionRepository),
        new DiscountMerchantResolver($merchantRepository, new DiscountMerchantService($merchantRepository), $text),
        new DiscountBenefitProgramResolver($programRepository, new DiscountBenefitProgramService($programRepository), $text),
        $promotionRepository,
    );
}

function discount_import_result(string $collectorKey, array $items): CollectorResult
{
    $now = new DateTimeImmutable('2026-08-12 12:00:00', new DateTimeZone('America/Santiago'));

    return CollectorResult::ok($collectorKey, $now, $now, $items);
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080018_create_discount_tables.php',
        '202608080019_add_discount_promotion_ownership.php',
        '202608080020_add_discount_collector_import_fields.php',
        '202608080026_add_discount_promotion_category.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $merchantRepository = new DiscountMerchantRepository($pdo);
    $merchantService = new DiscountMerchantService($merchantRepository);
    $programRepository = new DiscountBenefitProgramRepository($pdo);
    $programService = new DiscountBenefitProgramService($programRepository);
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $promotionService = new DiscountPromotionService($promotionRepository, $merchantService, $programService);
    $pipeline = discount_import_pipeline($pdo, $promotionRepository, $merchantRepository, $programRepository);
    $normalizer = new PromotionNormalizer(new DiscountTextNormalizer());

    $normalized = $normalizer->normalize('normalizer_source', new CollectedPromotion(
        sourceKey: 'normalizer-1',
        title: '  Café   Niño  ',
        sourceUrl: 'https://example.cl/promos/normalizer',
        merchantName: 'Dunkin',
        description: '  Texto   seguro  ',
        discountText: '30 % de descuento',
        startsOnRaw: '12/08/2026',
        endsOnRaw: '31 de agosto de 2026',
        weekdaysRaw: ['Martes y jueves'],
        channelRaw: 'presencial',
        categoryRaw: 'Sabores',
        terms: '  Sin acumulacion  ',
        collectedAt: new DateTimeImmutable('2026-08-12 10:00:00'),
    ));
    discount_import_assert($normalized->title() === 'Café Niño' && $normalized->discountType() === 'percentage' && $normalized->discountValue() === '30.00', 'Percentage normalization failed.');
    discount_import_assert($normalized->startsOn() === '2026-08-12' && $normalized->endsOn() === '2026-08-31', 'Date normalization failed.');
    discount_import_assert($normalized->weekdays() === [2, 4] && $normalized->channel() === 'in_store', 'Weekday or channel normalization failed.');
    discount_import_assert($normalized->category() === 'restaurants', 'Source category normalization failed.');

    $fixed = $normalizer->normalize('normalizer_source', new CollectedPromotion(
        sourceKey: 'normalizer-2',
        title: '$5.000 de descuento',
        discountText: '$5.000 de descuento',
        channelRaw: 'online',
    ));
    discount_import_assert($fixed->discountType() === 'fixed_amount' && $fixed->discountValue() === '5000.00' && $fixed->channel() === 'online', 'Fixed amount normalization failed.');

    $unknown = $normalizer->normalize('normalizer_source', new CollectedPromotion(
        sourceKey: 'normalizer-3',
        title: 'Beneficio sorpresa',
        discountText: '2x1',
        startsOnRaw: '03/04',
        weekdaysRaw: ['Todos los dias'],
    ));
    discount_import_assert($unknown->discountType() === 'other' && $unknown->weekdays() === [] && count($unknown->warnings()) >= 2, 'Unknown discount or ambiguous date warning failed.');

    try {
        $normalizer->normalize('normalizer_source', new CollectedPromotion(
            sourceKey: 'normalizer-invalid',
            title: '101%',
            discountText: '101%',
        ));
        throw new RuntimeException('Invalid percentage was accepted.');
    } catch (CollectorParseException) {
    }

    $merchant = $merchantService->create(['name' => "Dunkin' Import " . $suffix, 'category' => 'food']);
    $merchantIds[] = (int) $merchant['id'];
    $merchantResolver = new DiscountMerchantResolver($merchantRepository, $merchantService, new DiscountTextNormalizer());
    $sameMerchant = $merchantResolver->resolve('DUNKIN Import ' . $suffix);
    discount_import_assert((int) ($sameMerchant['id'] ?? 0) === (int) $merchant['id'], 'Merchant comparable normalization did not reuse existing row.');
    $uber = $merchantService->create(['name' => 'Uber Import ' . $suffix]);
    $merchantIds[] = (int) $uber['id'];
    $uberEats = $merchantResolver->resolve('Uber Eats Import ' . $suffix);
    $merchantIds[] = (int) $uberEats['id'];
    discount_import_assert((int) $uberEats['id'] !== (int) $uber['id'], 'Uber and Uber Eats were merged.');

    $bankProgram = $programService->create([
        'provider_name' => 'Banco Import ' . $suffix,
        'name' => 'Tarjetas Import ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    $programIds[] = (int) $bankProgram['id'];
    $benefitResolver = new DiscountBenefitProgramResolver($programRepository, $programService, new DiscountTextNormalizer());
    $resolved = $benefitResolver->resolveMany(['Tarjetas Import ' . $suffix]);
    discount_import_assert($resolved['ids'] === [(int) $bankProgram['id']], 'Existing benefit was not resolved by exact comparable name.');
    $createdBenefit = $benefitResolver->resolveMany(['mobile|Operador Import ' . $suffix . '|Beneficios Operador Import ' . $suffix . '|Plan']);
    $programIds[] = (int) $createdBenefit['ids'][0];
    discount_import_assert(count($createdBenefit['ids']) === 1, 'Structured benefit was not created.');
    $ambiguousBenefit = $benefitResolver->resolveMany(['Banco']);
    discount_import_assert($ambiguousBenefit['ids'] === [] && $ambiguousBenefit['unresolved'] !== [], 'Ambiguous benefit created a false association.');

    $first = $pipeline->import(discount_import_result('import_source', [
        new CollectedPromotion(
            sourceKey: 'promo-1',
            title: '30% descuento import ' . $suffix,
            merchantName: 'Dunkin Import ' . $suffix,
            discountText: '30% de descuento',
            endsOnRaw: '2026-08-31',
            weekdaysRaw: ['Martes'],
            benefitNamesRaw: ['Tarjetas Import ' . $suffix],
            channelRaw: 'Presencial y online',
            categoryRaw: 'Sabores',
        ),
        new CollectedPromotion(
            sourceKey: 'promo-1',
            title: 'Duplicada misma source',
            discountText: '30%',
        ),
    ]));
    discount_import_assert($first->createdCount() === 1 && $first->duplicateCount() === 1 && $first->skippedCount() === 1 && $first->errorCount() === 0, 'Initial import did not create/dedupe batch correctly.');

    $created = $promotionRepository->findCollectorByIdentity('import_source', 'promo-1');
    discount_import_assert(is_array($created) && $created['source_type'] === 'collector' && $created['collector_key'] === 'import_source' && $created['dedupe_fingerprint'] !== null, 'Collector identity fields were not persisted.');
    $promotionIds[] = (int) $created['id'];
    discount_import_assert($created['category'] === 'restaurants' && $promotionRepository->listWeekdays((int) $created['id']) === [2] && count($promotionRepository->listBenefits((int) $created['id'])) === 1, 'Imported category or relations were not persisted.');

    $second = $pipeline->import(discount_import_result('import_source', [
        new CollectedPromotion(
            sourceKey: 'promo-1',
            title: '35% descuento import ' . $suffix,
            merchantName: 'Dunkin Import ' . $suffix,
            discountText: '35% de descuento',
            endsOnRaw: '2026-09-30',
            weekdaysRaw: ['Jueves'],
            benefitNamesRaw: ['Tarjetas Import ' . $suffix],
            channelRaw: 'online',
            categoryRaw: 'Sabores',
        ),
    ]));
    discount_import_assert($second->updatedCount() === 1 && $second->createdCount() === 0, 'Identity update failed.');
    $updated = $promotionRepository->findCollectorByIdentity('import_source', 'promo-1');
    discount_import_assert(is_array($updated) && (string) $updated['discount_value'] === '35.00' && $promotionRepository->listWeekdays((int) $updated['id']) === [4], 'Update did not replace data and weekdays.');

    $third = $pipeline->import(discount_import_result('import_source', [
        new CollectedPromotion(
            sourceKey: 'promo-1-renamed',
            title: '35% descuento import ' . $suffix,
            merchantName: 'Dunkin Import ' . $suffix,
            discountText: '35% de descuento',
            endsOnRaw: '2026-09-30',
            weekdaysRaw: ['Jueves'],
            benefitNamesRaw: ['Tarjetas Import ' . $suffix],
            channelRaw: 'online',
        ),
    ]));
    discount_import_assert($third->updatedCount() === 1 && $third->createdCount() === 0 && $promotionRepository->findCollectorByIdentity('import_source', 'promo-1-renamed') !== null, 'Same collector fingerprint did not update source identity.');

    $crossSource = $pipeline->import(discount_import_result('other_import_source', [
        new CollectedPromotion(
            sourceKey: 'promo-1-renamed',
            title: '35% descuento import ' . $suffix,
            merchantName: 'Dunkin Import ' . $suffix,
            discountText: '35% de descuento',
            endsOnRaw: '2026-09-30',
            weekdaysRaw: ['Jueves'],
            benefitNamesRaw: ['Tarjetas Import ' . $suffix],
            channelRaw: 'online',
        ),
    ]));
    discount_import_assert($crossSource->createdCount() === 1 && $crossSource->warningCount() >= 1, 'Cross-source fingerprint was incorrectly merged or not warned.');
    $cross = $promotionRepository->findCollectorByIdentity('other_import_source', 'promo-1-renamed');
    $promotionIds[] = (int) $cross['id'];

    $collisionRaw = new CollectedPromotion(
        sourceKey: 'manual-collision-collector',
        title: '40% manual collision ' . $suffix,
        discountText: '40%',
        channelRaw: 'online',
    );
    $collisionFingerprint = (new PromotionFingerprintService(new DiscountTextNormalizer()))
        ->fingerprint($normalizer->normalize('manual_collision_source', $collisionRaw));
    $manualDuplicate = $promotionService->create([
        'title' => '40% manual collision ' . $suffix,
        'discount_type' => 'percentage',
        'discount_value' => '40',
        'channel' => 'online',
        'source_type' => 'manual',
        'dedupe_fingerprint' => $collisionFingerprint,
    ]);
    $promotionIds[] = (int) $manualDuplicate['id'];
    $manualCollision = $pipeline->import(discount_import_result('manual_collision_source', [$collisionRaw]));
    $collectorCollision = $promotionRepository->findCollectorByIdentity('manual_collision_source', 'manual-collision-collector');
    $promotionIds[] = (int) $collectorCollision['id'];
    $manualAfterCollision = $promotionRepository->findById((int) $manualDuplicate['id']);
    discount_import_assert(
        $manualCollision->createdCount() === 1
        && $manualCollision->warningCount() >= 1
        && is_array($collectorCollision)
        && is_array($manualAfterCollision)
        && $manualAfterCollision['source_type'] === 'manual',
        'Collector overwrote or merged a manual promotion.'
    );

    $unresolved = $pipeline->import(discount_import_result('import_source_unresolved', [
        new CollectedPromotion(
            sourceKey: 'unresolved-1',
            title: 'Promo beneficio ambiguo ' . $suffix,
            merchantName: 'Rollback Merchant ' . $suffix,
            discountText: '20%',
            benefitNamesRaw: ['Beneficio que no existe ' . $suffix],
        ),
        new CollectedPromotion(
            sourceKey: 'unresolved-2',
            title: 'Promo valida posterior ' . $suffix,
            discountText: '15%',
        ),
    ]));
    discount_import_assert($unresolved->createdCount() === 1 && $unresolved->skippedCount() === 1 && $unresolved->errorCount() === 1, 'Unresolved benefit did not skip safely while continuing batch.');
    $rollbackMerchant = $merchantRepository->findByNormalizedName('rollback merchant ' . $suffix);
    discount_import_assert($rollbackMerchant === null, 'Rollback did not undo merchant created by failed item.');
    $validAfterError = $promotionRepository->findCollectorByIdentity('import_source_unresolved', 'unresolved-2');
    $promotionIds[] = (int) $validAfterError['id'];

    $beforeDryRunCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    $dryRun = $pipeline->import(discount_import_result('dry_source', [
        new CollectedPromotion(
            sourceKey: 'dry-1',
            title: 'Dry run promo ' . $suffix,
            discountText: '$5.000 de descuento',
            channelRaw: 'web',
        ),
    ]), true);
    $afterDryRunCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    discount_import_assert($dryRun->dryRun() && $dryRun->createdCount() === 1 && $beforeDryRunCount === $afterDryRunCount, 'Dry-run modified database or did not report create.');

    $compatibility = new DiscountCompatibilityService($promotionRepository, new UserDiscountBenefitRepository($pdo));
    discount_import_assert($compatibility->evaluatePromotionForUser((int) $created['id'], 999999)['status'] === 'incompatible', 'Imported required benefit was interpreted as general.');

    echo "Discount import pipeline: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discount import pipeline: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($promotionIds as $promotionId) {
        $pdo->prepare('DELETE FROM discount_promotions WHERE id = :id')->execute(['id' => $promotionId]);
    }

    foreach ($merchantIds as $merchantId) {
        try {
            $pdo->prepare('DELETE FROM discount_merchants WHERE id = :id')->execute(['id' => $merchantId]);
        } catch (Throwable) {
        }
    }

    foreach ($programIds as $programId) {
        try {
            $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id')->execute(['id' => $programId]);
        } catch (Throwable) {
        }
    }
}

exit($exitCode);
