<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpenseDateHelper;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRecurringAdjustmentRepository;
use Modules\Expenses\ExpenseRecurringAdjustmentService;
use Modules\Expenses\ExpenseRecurringRuleRepository;
use Modules\Expenses\ExpenseRecurringRuleService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
use Modules\Expenses\ExpenseValidationException;
use Modules\Expenses\RecurringExpenseWorker;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_recurring_' . $suffix;
$otherUsername = 'test_expenses_recurring_other_' . $suffix;
$userIds = [];
$exitCode = 1;

function expenses_recurring_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_recurring_expect_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ExpenseValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function expenses_recurring_create_user(PDO $pdo, string $username): int
{
    $hash = password_hash('test-secret-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);

    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash password.');
    }

    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);

    return (int) $pdo->lastInsertId();
}

function expenses_recurring_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);

    return (int) $statement->fetchColumn() === 1;
}

function expenses_recurring_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index'
    );
    $statement->execute(['table' => $table, 'index' => $index]);

    return (int) $statement->fetchColumn() >= 1;
}

function expenses_recurring_find_expense(PDO $pdo, int $ruleId, string $periodMonth): array
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM expenses
         WHERE recurring_rule_id = :rule_id
           AND period_month = :period_month
         LIMIT 1'
    );
    $statement->execute([
        'rule_id' => $ruleId,
        'period_month' => $periodMonth,
    ]);
    $expense = $statement->fetch();

    if (!is_array($expense)) {
        throw new RuntimeException('Generated expense was not found.');
    }

    return $expense;
}

function expenses_recurring_expense_exists(PDO $pdo, int $ruleId, string $periodMonth): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM expenses
         WHERE recurring_rule_id = :rule_id
           AND period_month = :period_month'
    );
    $statement->execute([
        'rule_id' => $ruleId,
        'period_month' => $periodMonth,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080028_create_expense_tables.php',
        '202608080029_create_expense_recurring_rules.php',
        '202608080032_create_expense_recurring_adjustments.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    expenses_recurring_assert(expenses_recurring_column_exists($pdo, 'expenses', 'recurring_rule_id'), 'recurring_rule_id was not added to expenses.');
    expenses_recurring_assert(expenses_recurring_index_exists($pdo, 'expenses', 'expenses_recurring_rule_period_unique'), 'Recurring unique index is missing.');
    expenses_recurring_assert(ExpenseDateHelper::resolveDayOfMonth(2027, 2, 31) === '2027-02-28', 'Non-leap February day 31 failed.');
    expenses_recurring_assert(ExpenseDateHelper::resolveDayOfMonth(2028, 2, 31) === '2028-02-29', 'Leap February day 31 failed.');
    expenses_recurring_assert(ExpenseDateHelper::resolveDayOfMonth(2026, 4, 31) === '2026-04-30', 'April day 31 failed.');
    expenses_recurring_assert(ExpenseDateHelper::addMonths('2026-12-01', 1, DateTimeHelper::timezone()) === '2027-01-01', 'December to January failed.');

    $userId = expenses_recurring_create_user($pdo, $username);
    $otherUserId = expenses_recurring_create_user($pdo, $otherUsername);
    $userIds = [$userId, $otherUserId];

    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $ruleRepository = new ExpenseRecurringRuleRepository($pdo);
    $adjustmentRepository = new ExpenseRecurringAdjustmentRepository($pdo);
    $adjustmentService = new ExpenseRecurringAdjustmentService($adjustmentRepository, DateTimeHelper::DEFAULT_TIMEZONE);
    $ruleService = new ExpenseRecurringRuleService($ruleRepository, DateTimeHelper::DEFAULT_TIMEZONE);
    $worker = new RecurringExpenseWorker($ruleRepository, DateTimeHelper::DEFAULT_TIMEZONE, $adjustmentRepository);
    $expenseService = new ExpenseService(new ExpenseRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
    $today = new DateTimeImmutable('2026-08-15 09:00:00', new DateTimeZone('America/Santiago'));

    $homeCategory = $categoryService->create($userId, ['name' => 'Hogar']);
    $subscriptionCategory = $categoryService->create($userId, ['name' => 'Suscripciones']);
    $inactiveCategory = $categoryService->create($userId, ['name' => 'Legacy', 'active' => false]);
    $otherCategory = $categoryService->create($otherUserId, ['name' => 'Hogar']);
    $pat = $paymentMethodService->create($userId, ['name' => 'PAT Santander', 'type' => 'automatic_payment']);
    $visa = $paymentMethodService->create($userId, ['name' => 'Visa Santander', 'type' => 'card']);
    $inactiveMethod = $paymentMethodService->create($userId, ['name' => 'Metodo inactivo', 'type' => 'card', 'active' => false]);
    $otherMethod = $paymentMethodService->create($otherUserId, ['name' => 'Mercado Pago', 'type' => 'wallet']);
    $spotifyService = $serviceDefinitionService->create($userId, [
        'name' => 'Spotify',
        'category_id' => (string) $subscriptionCategory['id'],
        'default_amount_clp' => '8.250',
        'payment_method_ids' => [(string) $visa['id']],
        'default_payment_method_id' => (string) $visa['id'],
    ]);
    $waterService = $serviceDefinitionService->create($userId, [
        'name' => 'Aguas Andinas',
        'category_id' => (string) $homeCategory['id'],
        'payment_method_ids' => [(string) $pat['id']],
        'default_payment_method_id' => (string) $pat['id'],
    ]);
    $biMonthlyService = $serviceDefinitionService->create($userId, ['name' => 'Seguro trimestral']);
    $inactiveService = $serviceDefinitionService->create($userId, ['name' => 'Servicio inactivo', 'active' => false]);
    $otherService = $serviceDefinitionService->create($otherUserId, ['name' => 'Servicio ajeno']);

    $spotifyRule = $ruleService->saveForService($userId, (int) $spotifyService['id'], [
        'frequency' => 'monthly',
        'interval_value' => '1',
        'day_of_month' => '31',
        'default_amount_clp' => '9.000',
        'default_category_id' => (string) $inactiveCategory['id'],
        'default_payment_method_id' => (string) $inactiveMethod['id'],
        'starts_on' => '2026-08-01',
    ], $today);
    $waterRule = $ruleService->saveForService($userId, (int) $waterService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 30,
        'starts_on' => '2026-08-01',
    ], $today);
    $biMonthlyRule = $ruleService->saveForService($userId, (int) $biMonthlyService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 2,
        'day_of_month' => 10,
        'starts_on' => '2026-08-01',
    ], $today);
    $inactiveRule = $ruleService->saveForService($userId, (int) $inactiveService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 5,
        'starts_on' => '2026-08-01',
    ], $today);

    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $spotifyService['id'], ['frequency' => 'weekly', 'interval_value' => 1, 'day_of_month' => 1, 'starts_on' => '2026-08-01'], $today),
        'Invalid frequency was accepted.'
    );
    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $spotifyService['id'], ['frequency' => 'monthly', 'interval_value' => 1, 'day_of_month' => 32, 'starts_on' => '2026-08-01'], $today),
        'Invalid day was accepted.'
    );
    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $spotifyService['id'], ['frequency' => 'monthly', 'interval_value' => 0, 'day_of_month' => 1, 'starts_on' => '2026-08-01'], $today),
        'Invalid interval was accepted.'
    );
    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $otherService['id'], ['frequency' => 'monthly', 'interval_value' => 1, 'day_of_month' => 1, 'starts_on' => '2026-08-01'], $today),
        'Foreign service was accepted.'
    );
    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $spotifyService['id'], ['frequency' => 'monthly', 'interval_value' => 1, 'day_of_month' => 1, 'default_category_id' => (int) $otherCategory['id'], 'starts_on' => '2026-08-01'], $today),
        'Foreign category was accepted.'
    );
    expenses_recurring_expect_validation(
        fn () => $ruleService->saveForService($userId, (int) $spotifyService['id'], ['frequency' => 'monthly', 'interval_value' => 1, 'day_of_month' => 1, 'default_payment_method_id' => (int) $otherMethod['id'], 'starts_on' => '2026-08-01'], $today),
        'Foreign payment method was accepted.'
    );

    $firstRun = $worker->process($today);
    expenses_recurring_assert($firstRun['created_expenses'] === 3 && $firstRun['skipped_existing'] === 0, 'First worker run did not create expected expenses.');
    $secondRun = $worker->process($today);
    expenses_recurring_assert($secondRun['created_expenses'] === 0 && $secondRun['skipped_existing'] === 3, 'Second worker run was not idempotent.');

    $spotifyAugust = expenses_recurring_find_expense($pdo, (int) $spotifyRule['id'], '2026-08-01');
    expenses_recurring_assert((int) $spotifyAugust['user_id'] === $userId, 'Generated expense user_id is wrong.');
    expenses_recurring_assert((string) $spotifyAugust['description'] === 'Spotify', 'Service name snapshot failed.');
    expenses_recurring_assert((int) $spotifyAugust['amount_clp'] === 9000, 'Rule amount did not override service amount.');
    expenses_recurring_assert((int) $spotifyAugust['category_id'] === (int) $inactiveCategory['id'], 'Rule category was not used.');
    expenses_recurring_assert((int) $spotifyAugust['payment_method_id'] === (int) $visa['id'], 'Inactive rule payment method did not fall back to active service default.');
    expenses_recurring_assert($spotifyAugust['status'] === 'pending' && $spotifyAugust['paid_on'] === null, 'Generated expense should start pending and unpaid.');
    expenses_recurring_assert($spotifyAugust['due_on'] === '2026-08-31', 'Due date from day 31 failed.');

    $waterAugust = expenses_recurring_find_expense($pdo, (int) $waterRule['id'], '2026-08-01');
    expenses_recurring_assert($waterAugust['amount_clp'] === null, 'Variable service should generate amount NULL.');
    expenses_recurring_assert((int) $waterAugust['category_id'] === (int) $homeCategory['id'], 'Service category fallback failed.');
    expenses_recurring_assert((int) $waterAugust['payment_method_id'] === (int) $pat['id'], 'Service default payment fallback failed.');

    $flexService = $serviceDefinitionService->create($userId, [
        'name' => 'Plan flexible',
        'category_id' => (string) $subscriptionCategory['id'],
        'default_amount_clp' => '12.000',
        'payment_method_ids' => [(string) $visa['id']],
        'default_payment_method_id' => (string) $visa['id'],
    ]);
    $flexRule = $ruleService->saveForService($userId, (int) $flexService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 5,
        'default_amount_clp' => '12.000',
        'starts_on' => '2026-08-01',
    ], $today);

    $skipAdjustment = $adjustmentService->saveForService($userId, (int) $flexService['id'], [
        'recurring_rule_id' => (string) $flexRule['id'],
        'period_month' => '2026-08',
        'adjustment_action' => 'skip',
        'notes' => 'Congelado por este mes.',
    ]);
    expenses_recurring_assert((string) $skipAdjustment['action'] === 'skip', 'Skip adjustment was not saved.');

    $offerAdjustment = $adjustmentService->saveForService($userId, (int) $flexService['id'], [
        'recurring_rule_id' => (string) $flexRule['id'],
        'period_month' => '2026-09',
        'adjustment_action' => 'generate',
        'description' => 'Plan flexible oferta',
        'amount_override' => '1',
        'amount_clp' => '0',
        'due_on' => '2026-10-02',
        'category_id' => (string) $homeCategory['id'],
        'payment_method_id' => (string) $pat['id'],
        'notes' => 'Oferta 100% por un mes y vencimiento aplazado.',
    ]);
    expenses_recurring_assert((int) $offerAdjustment['amount_override'] === 1 && (int) $offerAdjustment['amount_clp'] === 0, 'Zero amount adjustment was not saved.');

    $updatedOfferAdjustment = $adjustmentService->saveForService($userId, (int) $flexService['id'], [
        'id' => (string) $offerAdjustment['id'],
        'recurring_rule_id' => (string) $flexRule['id'],
        'period_month' => '2026-09',
        'adjustment_action' => 'generate',
        'description' => 'Plan flexible oferta',
        'amount_override' => '1',
        'amount_clp' => '0',
        'due_on' => '2026-10-02',
        'category_id' => (string) $homeCategory['id'],
        'payment_method_id' => (string) $pat['id'],
        'notes' => 'Oferta 100% por un mes y vencimiento aplazado.',
    ]);
    expenses_recurring_assert((int) $updatedOfferAdjustment['id'] === (int) $offerAdjustment['id'], 'Adjustment update ignored the id.');

    expenses_recurring_expect_validation(
        fn () => $adjustmentService->saveForService($userId, (int) $flexService['id'], [
            'recurring_rule_id' => (string) $flexRule['id'],
            'period_month' => '2026-10',
            'adjustment_action' => 'generate',
            'category_id' => (string) $otherCategory['id'],
        ]),
        'Foreign adjustment category was accepted.'
    );

    $flexAugustRun = $worker->process($today, (int) $flexRule['id']);
    expenses_recurring_assert($flexAugustRun['created_expenses'] === 0 && $flexAugustRun['skipped_existing'] >= 1, 'Skip adjustment generated an expense.');
    expenses_recurring_assert(!expenses_recurring_expense_exists($pdo, (int) $flexRule['id'], '2026-08-01'), 'Skipped adjustment generated an August expense.');

    $summary = $expenseService->listForMonth($userId, 2026, 8, $today);
    expenses_recurring_assert($summary['total_amount_clp'] === 9000 && $summary['unknown_amount_count'] === 2, 'KPIs did not exclude unknown amounts.');

    $expenseService->update($userId, (int) $waterAugust['id'], ['amount_clp' => '19.994']);
    $updatedWater = $expenseService->get($userId, (int) $waterAugust['id']);
    $sameWaterRule = $ruleService->get($userId, (int) $waterRule['id']);
    expenses_recurring_assert((int) ($updatedWater['amount_clp'] ?? 0) === 19994 && $sameWaterRule['default_amount_clp'] === null, 'Editing generated expense changed the rule.');

    $ruleService->saveForService($userId, (int) $spotifyService['id'], [
        'frequency' => 'monthly',
        'interval_value' => '1',
        'day_of_month' => '20',
        'starts_on' => '2026-08-01',
    ], $today);
    $unchangedSpotifyAugust = expenses_recurring_find_expense($pdo, (int) $spotifyRule['id'], '2026-08-01');
    expenses_recurring_assert($unchangedSpotifyAugust['due_on'] === '2026-08-31', 'Changing rule modified historical expense.');

    $septemberRun = $worker->process(new DateTimeImmutable('2026-09-03 09:00:00', new DateTimeZone('America/Santiago')));
    expenses_recurring_assert($septemberRun['created_expenses'] === 3, 'Catch-up on September 3 did not create monthly expenses.');
    expenses_recurring_assert(expenses_recurring_find_expense($pdo, (int) $spotifyRule['id'], '2026-09-01')['due_on'] === '2026-09-20', 'Updated day was not used for future generation.');

    $flexSeptember = expenses_recurring_find_expense($pdo, (int) $flexRule['id'], '2026-09-01');
    expenses_recurring_assert((string) $flexSeptember['description'] === 'Plan flexible oferta', 'Adjustment description was not used.');
    expenses_recurring_assert((int) $flexSeptember['amount_clp'] === 0, 'Adjustment zero amount was not used.');
    expenses_recurring_assert($flexSeptember['due_on'] === '2026-10-02', 'Adjustment postponed due date was not used.');
    expenses_recurring_assert((int) $flexSeptember['category_id'] === (int) $homeCategory['id'], 'Adjustment category was not used.');
    expenses_recurring_assert((int) $flexSeptember['payment_method_id'] === (int) $pat['id'], 'Adjustment payment method was not used.');
    expenses_recurring_assert((string) $flexSeptember['notes'] === 'Oferta 100% por un mes y vencimiento aplazado.', 'Adjustment notes were not used.');

    expenses_recurring_assert(count($adjustmentService->listForService($userId, (int) $flexService['id'])) === 2, 'Adjustments were not listed for service.');
    expenses_recurring_assert($adjustmentService->delete($userId, (int) $skipAdjustment['id']), 'Adjustment was not deleted.');
    expenses_recurring_assert(count($adjustmentService->listForService($userId, (int) $flexService['id'])) === 1, 'Adjustment delete did not update service list.');

    $octoberRun = $worker->process(new DateTimeImmutable('2026-10-03 09:00:00', new DateTimeZone('America/Santiago')));
    expenses_recurring_assert($octoberRun['created_expenses'] >= 3, 'Interval 2 or monthly October generation failed.');
    expenses_recurring_assert(expenses_recurring_find_expense($pdo, (int) $biMonthlyRule['id'], '2026-10-01')['due_on'] === '2026-10-10', 'Interval 2 did not generate October.');

    $endedRule = $ruleService->saveForService($userId, (int) $waterService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 30,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-31',
    ], new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('America/Santiago')));
    expenses_recurring_assert($endedRule['next_generation_on'] === null, 'Ended rule should not keep generating future periods.');

    $newMidMonthService = $serviceDefinitionService->create($userId, ['name' => 'YouTube Premium']);
    $newMidMonthRule = $ruleService->saveForService($userId, (int) $newMidMonthService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 14,
        'starts_on' => '2020-01-01',
    ], $today);
    $midMonthRun = $worker->process($today, (int) $newMidMonthRule['id']);
    expenses_recurring_assert($midMonthRun['created_expenses'] === 1 && $midMonthRun['skipped_existing'] === 0, 'New mid-month rule did not generate current month.');
    expenses_recurring_assert(expenses_recurring_find_expense($pdo, (int) $newMidMonthRule['id'], '2026-08-01')['period_month'] === '2026-08-01', 'New old-start rule generated wrong period.');

    $ruleService->setActive($userId, (int) $spotifyRule['id'], false, $today);
    $inactiveRun = $worker->process(new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('America/Santiago')), (int) $spotifyRule['id']);
    expenses_recurring_assert($inactiveRun['created_expenses'] === 0, 'Inactive rule generated expenses.');
    $ruleService->setActive($userId, (int) $spotifyRule['id'], true, new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('America/Santiago')));
    $reactivatedRun = $worker->process(new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('America/Santiago')), (int) $spotifyRule['id']);
    expenses_recurring_assert($reactivatedRun['created_expenses'] === 1, 'Reactivated rule did not continue.');

    $otherRuleService = new ExpenseRecurringRuleService($ruleRepository, DateTimeHelper::DEFAULT_TIMEZONE);
    $otherRule = $otherRuleService->saveForService($otherUserId, (int) $otherService['id'], [
        'frequency' => 'monthly',
        'interval_value' => 1,
        'day_of_month' => 1,
        'starts_on' => '2026-08-01',
    ], $today);
    $worker->process($today, (int) $otherRule['id']);
    expenses_recurring_assert((int) expenses_recurring_find_expense($pdo, (int) $otherRule['id'], '2026-08-01')['user_id'] === $otherUserId, 'Worker mixed users.');

    expenses_recurring_assert(!$ruleRepository->createGeneratedExpense($userId, [
        'service_id' => (int) $spotifyService['id'],
        'recurring_rule_id' => (int) $spotifyRule['id'],
        'category_id' => null,
        'period_month' => '2026-08-01',
        'description' => 'Spotify duplicado',
        'amount_clp' => 1,
        'due_on' => '2026-08-20',
        'payment_method_id' => null,
        'status' => 'pending',
    ]), 'Repository did not surface UNIQUE idempotency protection.');

    echo "Expenses recurring: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses recurring: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $cleanup = $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
        $cleanup->execute($userIds);
    }
}

exit($exitCode);
