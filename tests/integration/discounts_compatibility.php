<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountCompatibilityService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionAvailabilityService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Discounts\UserDiscountBenefitService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$suffix = bin2hex(random_bytes(4));
$username = 'test_discount_compat_' . $suffix;
$otherUsername = 'test_discount_compat_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$promotionIds = [];
$programIds = [];
$userIds = [];
$exitCode = 1;

function discount_compat_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $password);
    $userIds = [$userId, $otherUserId];
    $programService = new DiscountBenefitProgramService(new DiscountBenefitProgramRepository($pdo));
    $userBenefitRepository = new UserDiscountBenefitRepository($pdo);
    $userBenefitService = new UserDiscountBenefitService($userBenefitRepository, $programService);
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $promotionService = new DiscountPromotionService(
        $promotionRepository,
        new DiscountMerchantService(new DiscountMerchantRepository($pdo)),
        $programService,
    );
    $compatibility = new DiscountCompatibilityService($promotionRepository, $userBenefitRepository);
    $availability = new DiscountPromotionAvailabilityService('America/Santiago');
    $today = $availability->today();
    $weekdayToday = $availability->weekdayToday();
    $otherWeekday = $weekdayToday === 7 ? 1 : $weekdayToday + 1;

    $bankProgram = $programService->create([
        'provider_name' => 'Banco de Chile ' . $suffix,
        'name' => 'Tarjetas Banco de Chile',
        'benefit_type' => 'bank_card',
    ]);
    $mobileProgram = $programService->create([
        'provider_name' => 'Entel ' . $suffix,
        'name' => 'Beneficios Entel',
        'benefit_type' => 'mobile',
    ]);
    $walletProgram = $programService->create([
        'provider_name' => 'Mercado Pago ' . $suffix,
        'name' => 'Wallet Mercado Pago',
        'benefit_type' => 'wallet',
    ]);
    $inactiveProgram = $programService->create([
        'provider_name' => 'Banco Inactivo ' . $suffix,
        'name' => 'Tarjeta inactiva',
        'benefit_type' => 'bank_card',
    ]);
    $programIds = [
        (int) $bankProgram['id'],
        (int) $mobileProgram['id'],
        (int) $walletProgram['id'],
        (int) $inactiveProgram['id'],
    ];

    $generalPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo general ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
    ]);
    $promotionIds[] = (int) $generalPromotion['id'];
    $general = $compatibility->evaluatePromotionForUser((int) $generalPromotion['id'], $userId);
    discount_compat_assert(is_array($general) && $general['status'] === DiscountCompatibilityService::STATUS_GENERAL && $general['matched_benefits'] === [], 'General promotion was not evaluated as general.');
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $generalPromotion['id'], $otherUserId)['status'] === 'general', 'User without benefits did not get general status.');

    $bankPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo Banco ' . $suffix,
        'discount_type' => 'percentage',
        'discount_value' => 30,
        'channel' => 'both',
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $promotionIds[] = (int) $bankPromotion['id'];
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $bankPromotion['id'], $userId)['status'] === 'incompatible', 'Required benefit matched before the user had it.');

    $userBankBenefit = $userBenefitService->create($userId, ['benefit_program_id' => (int) $bankProgram['id']]);
    $bankResult = $compatibility->evaluatePromotionForUser((int) $bankPromotion['id'], $userId);
    discount_compat_assert($bankResult['status'] === 'compatible' && count($bankResult['matched_benefits']) === 1 && str_contains($bankResult['reason'], 'Tarjetas Banco de Chile'), 'Single required benefit was not compatible.');
    discount_compat_assert($compatibility->getActiveUserBenefitIds($userId) === [(int) $bankProgram['id']], 'Active user benefit ids were not returned.');
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $bankPromotion['id'], $otherUserId)['status'] === 'incompatible', 'Another user benefit leaked into compatibility.');

    $orPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo OR ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'benefit_program_ids' => [(string) $bankProgram['id'], (string) $mobileProgram['id'], (string) $walletProgram['id']],
    ]);
    $promotionIds[] = (int) $orPromotion['id'];
    $orOne = $compatibility->evaluatePromotionForUser((int) $orPromotion['id'], $userId);
    discount_compat_assert($orOne['status'] === 'compatible' && count($orOne['matched_benefits']) === 1, 'OR promotion did not match one benefit.');
    $userBenefitService->create($userId, ['benefit_program_id' => (int) $mobileProgram['id']]);
    $orMany = $compatibility->evaluatePromotionForUser((int) $orPromotion['id'], $userId);
    discount_compat_assert($orMany['status'] === 'compatible' && count($orMany['matched_benefits']) === 2 && str_contains($orMany['reason'], '2 de tus beneficios'), 'OR promotion did not report several matches.');

    $inactiveUserBenefit = $userBenefitService->create($userId, ['benefit_program_id' => (int) $walletProgram['id']]);
    $userBenefitService->deactivate($userId, (int) $inactiveUserBenefit['id']);
    $walletPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo Wallet ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'benefit_program_ids' => [(string) $walletProgram['id']],
    ]);
    $promotionIds[] = (int) $walletPromotion['id'];
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $walletPromotion['id'], $userId)['status'] === 'incompatible', 'Inactive user benefit counted as compatible.');
    $userBenefitService->create($userId, ['benefit_program_id' => (int) $walletProgram['id']]);
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $walletPromotion['id'], $userId)['status'] === 'compatible', 'Reactivated benefit did not change compatibility immediately.');
    $reactivatedWallet = $userBenefitService->get($userId, (int) $inactiveUserBenefit['id']);
    $userBenefitService->deactivate($userId, (int) $reactivatedWallet['id']);
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $walletPromotion['id'], $userId)['status'] === 'incompatible', 'Deactivated benefit did not change compatibility immediately.');

    $inactiveProgramBenefit = $userBenefitService->create($userId, ['benefit_program_id' => (int) $inactiveProgram['id']]);
    $inactiveProgramPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo programa inactivo ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'benefit_program_ids' => [(string) $inactiveProgram['id']],
    ]);
    $promotionIds[] = (int) $inactiveProgramPromotion['id'];
    $pdo->prepare('UPDATE discount_benefit_programs SET active = 0 WHERE id = :id')->execute(['id' => (int) $inactiveProgram['id']]);
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $inactiveProgramPromotion['id'], $userId)['status'] === 'incompatible', 'Inactive benefit program produced active compatibility.');
    discount_compat_assert(!in_array((int) $inactiveProgram['id'], $compatibility->getActiveUserBenefitIds($userId), true) && (int) $inactiveProgramBenefit['id'] > 0, 'Inactive benefit program appeared in active user ids.');

    $futurePromotion = $promotionService->createManual($userId, [
        'title' => 'Promo futura ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'starts_on' => (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d'),
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $promotionIds[] = (int) $futurePromotion['id'];
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $futurePromotion['id'], $userId)['status'] === 'compatible', 'Future promotion was not structurally compatible.');
    discount_compat_assert(!$availability->isApplicableToday($futurePromotion, []), 'Future promotion was applicable today.');

    $endedPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo finalizada ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'ends_on' => (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'),
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $promotionIds[] = (int) $endedPromotion['id'];
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $endedPromotion['id'], $userId)['status'] === 'compatible', 'Ended promotion was not structurally compatible.');
    discount_compat_assert(!$availability->isApplicableToday($endedPromotion, []), 'Ended promotion was applicable today.');

    $todayPromotion = $promotionService->createManual($userId, [
        'title' => 'Promo hoy ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'starts_on' => (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'),
        'ends_on' => (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'),
        'weekdays' => [(string) $weekdayToday],
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $promotionIds[] = (int) $todayPromotion['id'];
    discount_compat_assert($availability->isApplicableToday($todayPromotion, [(int) $weekdayToday]), 'Valid weekday promotion was not applicable today.');
    discount_compat_assert(!$availability->isApplicableToday($todayPromotion, [$otherWeekday]), 'Wrong weekday promotion was applicable today.');
    discount_compat_assert($availability->isApplicableToday($generalPromotion, []), 'Promotion without weekdays was not applicable any day.');

    $inactivePromotion = $promotionService->createManual($userId, [
        'title' => 'Promo inactiva ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'benefit_program_ids' => [(string) $bankProgram['id']],
        'is_active' => '0',
    ]);
    $promotionIds[] = (int) $inactivePromotion['id'];
    $activeCompatibleIds = array_map(static fn (array $promotion): int => (int) $promotion['id'], $compatibility->getCompatibleActivePromotionsForUser($userId));
    discount_compat_assert(!in_array((int) $inactivePromotion['id'], $activeCompatibleIds, true), 'Inactive promotion appeared in active compatible list.');

    $collectorPromotion = $promotionService->create([
        'title' => 'Collector compatible ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'source_type' => 'collector',
        'source_key' => 'collector-' . $suffix,
    ]);
    $promotionIds[] = (int) $collectorPromotion['id'];
    $promotionService->addBenefit((int) $collectorPromotion['id'], (int) $bankProgram['id']);
    discount_compat_assert($compatibility->evaluatePromotionForUser((int) $collectorPromotion['id'], $userId)['status'] === 'compatible', 'Collector promotion was not evaluated with the same rules.');
    $activeCompatibleIds = array_map(static fn (array $promotion): int => (int) $promotion['id'], $compatibility->getCompatibleActivePromotionsForUser($userId));
    discount_compat_assert(in_array((int) $generalPromotion['id'], $activeCompatibleIds, true) && in_array((int) $collectorPromotion['id'], $activeCompatibleIds, true), 'Compatible active list missed general or collector promotions.');
    discount_compat_assert(!in_array((int) $walletPromotion['id'], $activeCompatibleIds, true), 'Incompatible promotion appeared in compatible list.');

    $allCompatibleIds = array_map(static fn (array $promotion): int => (int) $promotion['id'], $compatibility->getCompatiblePromotionsForUser($userId));
    discount_compat_assert(in_array((int) $inactivePromotion['id'], $allCompatibleIds, true), 'Non-active compatible list unexpectedly filtered inactive promotion.');

    echo "Discounts compatibility: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discounts compatibility: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($promotionIds as $promotionId) {
        $statement = $pdo->prepare('DELETE FROM discount_promotions WHERE id = :id');
        $statement->execute(['id' => $promotionId]);
    }

    foreach ($userIds as $userId) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    foreach ($programIds as $programId) {
        $statement = $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id');
        $statement->execute(['id' => $programId]);
    }

    foreach ([$username, $otherUsername] as $loginUsername) {
        $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
        $statement->execute(['username' => $loginUsername]);
    }
}

exit($exitCode);
