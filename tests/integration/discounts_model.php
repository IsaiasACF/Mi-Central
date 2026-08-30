<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\DiscountValidationException;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Discounts\UserDiscountBenefitService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$suffix = bin2hex(random_bytes(4));
$username = 'test_discounts_' . $suffix;
$otherUsername = 'test_discounts_other_' . $suffix;
$cascadeUsername = 'test_discounts_cascade_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$createdPromotionIds = [];
$createdMerchantIds = [];
$createdProgramIds = [];
$userIds = [];
$exitCode = 1;

function discounts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discounts_expect_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DiscountValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function discounts_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
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

    foreach ([
        'discount_benefit_programs',
        'user_discount_benefits',
        'discount_merchants',
        'discount_promotions',
        'discount_promotion_benefits',
        'discount_promotion_days',
        'user_discount_favorites',
    ] as $table) {
        discounts_assert(discounts_table_exists($pdo, $table), $table . ' was not created.');
    }

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $password);
    $cascadeUserId = $auth->createUser($cascadeUsername, $password);
    $userIds = [$userId, $otherUserId, $cascadeUserId];

    $programRepository = new DiscountBenefitProgramRepository($pdo);
    $programService = new DiscountBenefitProgramService($programRepository);
    $userBenefitRepository = new UserDiscountBenefitRepository($pdo);
    $userBenefitService = new UserDiscountBenefitService($userBenefitRepository, $programService);
    $merchantRepository = new DiscountMerchantRepository($pdo);
    $merchantService = new DiscountMerchantService($merchantRepository);
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $promotionService = new DiscountPromotionService($promotionRepository, $merchantService, $programService);

    $bankProgram = $programService->create([
        'provider_name' => 'Banco de Chile ' . $suffix,
        'name' => 'Tarjetas Banco de Chile',
        'benefit_type' => 'bank_card',
    ]);
    $createdProgramIds[] = (int) $bankProgram['id'];
    discounts_assert((int) $bankProgram['id'] > 0 && $bankProgram['product_name'] === null && (int) $bankProgram['active'] === 1, 'Valid benefit program was not created.');
    discounts_expect_validation(
        fn () => $programService->create(['provider_name' => 'Banco Malo', 'name' => 'Beneficio', 'benefit_type' => 'invalid']),
        'Invalid benefit_type was accepted.'
    );

    $mobileProgram = $programService->create([
        'provider_name' => 'Entel ' . $suffix,
        'name' => 'Beneficios Entel',
        'benefit_type' => 'mobile',
        'product_name' => 'Plan movil',
    ]);
    $createdProgramIds[] = (int) $mobileProgram['id'];

    $userBankBenefit = $userBenefitService->create($userId, [
        'benefit_program_id' => (int) $bankProgram['id'],
        'nickname' => 'Mi tarjeta principal',
    ]);
    $userMobileBenefit = $userBenefitService->create($userId, [
        'benefit_program_id' => (int) $mobileProgram['id'],
        'notes' => 'Linea personal',
    ]);
    discounts_assert((int) $userBankBenefit['user_id'] === $userId && count($userBenefitService->listActive($userId)) === 2 && (int) $userMobileBenefit['benefit_program_id'] === (int) $mobileProgram['id'], 'User could not hold several benefits.');
    discounts_expect_validation(
        fn () => $userBenefitService->create($userId, ['benefit_program_id' => (int) $bankProgram['id']]),
        'Duplicate user benefit was accepted.'
    );
    discounts_assert($userBenefitService->get($otherUserId, (int) $userBankBenefit['id']) === null, 'User benefit ownership was not enforced.');

    $merchant = $merchantService->create([
        'name' => 'Dunkin ' . $suffix,
        'category' => 'food',
        'website_url' => 'https://example.com/dunkin-' . $suffix,
    ]);
    $createdMerchantIds[] = (int) $merchant['id'];
    discounts_assert((int) $merchant['id'] > 0 && $merchant['category'] === 'food', 'Merchant was not created.');

    $percentagePromotion = $promotionService->create([
        'merchant_id' => (int) $merchant['id'],
        'title' => '30% descuento ' . $suffix,
        'description' => 'Promo de prueba',
        'discount_type' => 'percentage',
        'discount_value' => 30,
        'channel' => 'both',
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-31',
        'source_type' => 'manual',
    ]);
    $createdPromotionIds[] = (int) $percentagePromotion['id'];
    discounts_assert((int) $percentagePromotion['merchant_id'] === (int) $merchant['id'] && (string) $percentagePromotion['discount_value'] === '30.00', 'Percentage promotion was not persisted.');

    $fixedPromotion = $promotionService->create([
        'title' => '$5000 descuento ' . $suffix,
        'discount_type' => 'fixed_amount',
        'discount_value' => '5000',
        'channel' => 'online',
        'source_type' => 'manual',
    ]);
    $createdPromotionIds[] = (int) $fixedPromotion['id'];
    discounts_assert($fixedPromotion['merchant_id'] === null && (string) $fixedPromotion['discount_value'] === '5000.00', 'Fixed amount promotion without merchant was not allowed.');

    $collectorPromotion = $promotionService->create([
        'title' => 'Precio especial collector ' . $suffix,
        'discount_type' => 'special_price',
        'discount_value' => null,
        'channel' => 'in_store',
        'source_type' => 'collector',
        'source_key' => 'collector-' . $suffix,
        'source_url' => 'https://example.com/promo-' . $suffix,
        'last_seen_at' => '2026-08-12 10:15:00',
    ]);
    $createdPromotionIds[] = (int) $collectorPromotion['id'];
    discounts_assert($collectorPromotion['source_type'] === 'collector' && $collectorPromotion['source_key'] === 'collector-' . $suffix, 'Collector source fields were not persisted.');
    discounts_expect_validation(
        fn () => $promotionService->create(['title' => 'Invalid source', 'discount_type' => 'other', 'channel' => 'both', 'source_type' => 'api']),
        'Invalid source_type was accepted.'
    );

    $promotionService->addBenefit((int) $percentagePromotion['id'], (int) $bankProgram['id']);
    $promotionService->addBenefit((int) $percentagePromotion['id'], (int) $mobileProgram['id']);
    discounts_assert(count($promotionService->listByBenefit((int) $bankProgram['id'])) >= 1 && count($promotionService->listByBenefit((int) $mobileProgram['id'])) >= 1, 'Promotion did not accept several OR benefits.');
    discounts_expect_validation(
        fn () => $promotionService->addBenefit((int) $percentagePromotion['id'], (int) $bankProgram['id']),
        'Duplicate promotion benefit relation was accepted.'
    );
    discounts_assert(count($promotionService->listByBenefit((int) $bankProgram['id'])) >= 1 && (int) $fixedPromotion['id'] > 0, 'Promotion without benefits was not valid.');

    $promotionService->addWeekday((int) $percentagePromotion['id'], 1);
    $promotionService->addWeekday((int) $percentagePromotion['id'], 7);
    discounts_expect_validation(fn () => $promotionService->addWeekday((int) $percentagePromotion['id'], 0), 'Weekday 0 was accepted.');
    discounts_expect_validation(fn () => $promotionService->addWeekday((int) $percentagePromotion['id'], 8), 'Weekday 8 was accepted.');
    discounts_expect_validation(fn () => $promotionService->addWeekday((int) $percentagePromotion['id'], 1), 'Duplicate weekday was accepted.');
    $mondayPromotions = $promotionService->listCurrentForDate('2026-08-03', 1);
    discounts_assert(array_filter($mondayPromotions, static fn (array $item): bool => (int) $item['id'] === (int) $percentagePromotion['id']) !== [], 'Weekday 1 did not match current promotion.');

    $promotionService->addFavorite($userId, (int) $percentagePromotion['id']);
    discounts_assert(count($promotionService->listFavorites($userId)) === 1 && count($promotionService->listFavorites($otherUserId)) === 0, 'Favorite ownership was not enforced.');
    discounts_expect_validation(
        fn () => $promotionService->addFavorite($userId, (int) $percentagePromotion['id']),
        'Duplicate favorite was accepted.'
    );

    $cascadeBenefit = $userBenefitService->create($cascadeUserId, ['benefit_program_id' => (int) $mobileProgram['id']]);
    $promotionService->addFavorite($cascadeUserId, (int) $fixedPromotion['id']);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $cascadeUserId]);
    $benefitCount = $pdo->prepare('SELECT COUNT(*) FROM user_discount_benefits WHERE user_id = :user_id');
    $benefitCount->execute(['user_id' => $cascadeUserId]);
    $favoriteCount = $pdo->prepare('SELECT COUNT(*) FROM user_discount_favorites WHERE user_id = :user_id');
    $favoriteCount->execute(['user_id' => $cascadeUserId]);
    discounts_assert((int) $benefitCount->fetchColumn() === 0 && (int) $favoriteCount->fetchColumn() === 0 && (int) $cascadeBenefit['id'] > 0, 'User cascades did not remove benefits/favorites.');

    try {
        $programRepository->delete((int) $bankProgram['id']);
        discounts_assert(false, 'Used benefit program was deleted silently.');
    } catch (PDOException) {
    }

    $pdo->prepare('DELETE FROM discount_merchants WHERE id = :id')->execute(['id' => (int) $merchant['id']]);
    $merchantAfterDelete = $promotionService->get((int) $percentagePromotion['id']);
    discounts_assert(is_array($merchantAfterDelete) && $merchantAfterDelete['merchant_id'] === null, 'Deleting merchant did not SET NULL on promotions.');
    $createdMerchantIds = array_values(array_filter($createdMerchantIds, static fn (int $id): bool => $id !== (int) $merchant['id']));

    $promotionRepository->delete((int) $percentagePromotion['id']);
    $createdPromotionIds = array_values(array_filter($createdPromotionIds, static fn (int $id): bool => $id !== (int) $percentagePromotion['id']));
    foreach (['discount_promotion_benefits', 'discount_promotion_days', 'user_discount_favorites'] as $table) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE promotion_id = :promotion_id");
        $count->execute(['promotion_id' => (int) $percentagePromotion['id']]);
        discounts_assert((int) $count->fetchColumn() === 0, $table . ' did not cascade after promotion delete.');
    }

    echo "Discounts model: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discounts model: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($createdPromotionIds as $promotionId) {
        $statement = $pdo->prepare('DELETE FROM discount_promotions WHERE id = :id');
        $statement->execute(['id' => $promotionId]);
    }

    foreach ($userIds as $userId) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    foreach ($createdMerchantIds as $merchantId) {
        $statement = $pdo->prepare('DELETE FROM discount_merchants WHERE id = :id');
        $statement->execute(['id' => $merchantId]);
    }

    foreach ($createdProgramIds as $programId) {
        try {
            $statement = $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id');
            $statement->execute(['id' => $programId]);
        } catch (Throwable) {
        }
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username, :cascade_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'cascade_username' => $cascadeUsername,
    ]);
}

exit($exitCode);
