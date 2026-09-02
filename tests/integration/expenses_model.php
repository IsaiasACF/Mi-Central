<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
use Modules\Expenses\ExpenseValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_' . $suffix;
$otherUsername = 'test_expenses_other_' . $suffix;
$freshUsername = 'test_expenses_fresh_' . $suffix;
$userIds = [];
$exitCode = 1;

function expenses_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_expect_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ExpenseValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function expenses_table_exists(PDO $pdo, string $table): bool
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

function expenses_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index'
    );
    $statement->execute([
        'table' => $table,
        'index' => $index,
    ]);

    return (int) $statement->fetchColumn() >= 1;
}

function expenses_create_user(PDO $pdo, string $username): int
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

    foreach ([
        'expense_categories',
        'expense_payment_methods',
        'expense_services',
        'expense_service_payment_methods',
        'expenses',
    ] as $table) {
        expenses_assert(expenses_table_exists($pdo, $table), $table . ' was not created.');
    }

    foreach ([
        ['expenses', 'expenses_user_period_index'],
        ['expenses', 'expenses_user_due_on_index'],
        ['expenses', 'expenses_user_status_index'],
        ['expenses', 'expenses_service_index'],
        ['expenses', 'expenses_category_index'],
        ['expenses', 'expenses_payment_method_index'],
    ] as [$table, $index]) {
        expenses_assert(expenses_index_exists($pdo, $table, $index), $index . ' index is missing.');
    }

    $userId = expenses_create_user($pdo, $username);
    $otherUserId = expenses_create_user($pdo, $otherUsername);
    $freshUserId = expenses_create_user($pdo, $freshUsername);
    $userIds = [$userId, $otherUserId, $freshUserId];

    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $expenseService = new ExpenseService(new ExpenseRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
    $today = new DateTimeImmutable('2026-08-15 10:00:00', new DateTimeZone('America/Santiago'));

    expenses_assert($categoryService->list($freshUserId) === [], 'New user should not receive default expense categories.');
    expenses_assert($paymentMethodService->list($freshUserId) === [], 'New user should not receive default payment methods.');
    expenses_assert($serviceDefinitionService->list($freshUserId) === [], 'New user should not receive default expense services.');
    expenses_assert($expenseService->listForMonth($freshUserId, 2026, 8, $today)['items'] === [], 'New user should not receive default monthly expenses.');

    $basicCategory = $categoryService->create($userId, [
        'name' => 'Servicios   basicos',
        'color' => '#1ab2c3',
    ]);
    $subscriptionCategory = $categoryService->create($userId, ['name' => 'Suscripciones']);
    $techCategory = $categoryService->create($userId, ['name' => 'Tecnologia', 'active' => false]);
    $otherBasicCategory = $categoryService->create($otherUserId, ['name' => 'Servicios basicos']);

    expenses_assert($basicCategory['color'] === '#1AB2C3' && (int) $basicCategory['active'] === 1, 'Category was not normalized.');
    expenses_expect_validation(
        fn () => $categoryService->create($userId, ['name' => 'servicios basicos']),
        'Duplicate category for same user was accepted.'
    );
    expenses_assert((int) $otherBasicCategory['id'] > 0, 'Same category name should be allowed for another user.');
    expenses_assert($categoryService->get($otherUserId, (int) $basicCategory['id']) === null, 'Category ownership was not enforced.');
    expenses_assert((int) $techCategory['active'] === 0, 'Inactive category was not persisted.');

    $visa = $paymentMethodService->create($userId, [
        'name' => 'Visa Santander',
        'type' => 'card',
        'institution_name' => 'Santander',
    ]);
    $pat = $paymentMethodService->create($userId, [
        'name' => 'PAT Santander',
        'type' => 'automatic_payment',
        'institution_name' => 'Santander',
    ]);
    $webpay = $paymentMethodService->create($userId, [
        'name' => 'WebPay',
        'type' => 'webpay',
    ]);
    $cash = $paymentMethodService->create($userId, [
        'name' => 'Efectivo',
        'type' => 'cash',
        'active' => false,
    ]);
    $otherWallet = $paymentMethodService->create($otherUserId, [
        'name' => 'Mercado Pago',
        'type' => 'wallet',
    ]);

    expenses_assert(count($paymentMethodService->list($userId)) === 4 && (int) $cash['active'] === 0, 'User payment methods were not persisted.');
    expenses_expect_validation(
        fn () => $paymentMethodService->create($userId, ['name' => 'Metodo invalido', 'type' => 'crypto']),
        'Invalid payment method type was accepted.'
    );
    expenses_expect_validation(
        fn () => $paymentMethodService->create($userId, [
            'name' => 'Tarjeta secreta',
            'type' => 'card',
            'card_number' => '4111111111111111',
        ]),
        'Sensitive payment method data was accepted.'
    );
    expenses_expect_validation(
        fn () => $paymentMethodService->create($userId, [
            'name' => 'Tarjeta con CVV',
            'type' => 'card',
            'notes' => 'CVV 123',
        ]),
        'Sensitive notes were accepted in payment method.'
    );
    expenses_assert($paymentMethodService->get($otherUserId, (int) $visa['id']) === null, 'Payment method ownership was not enforced.');

    $waterService = $serviceDefinitionService->create($userId, [
        'name' => 'Aguas Andinas',
        'category_id' => (string) $basicCategory['id'],
        'notes' => 'Boleta mensual variable',
    ]);
    $spotifyService = $serviceDefinitionService->create($userId, [
        'name' => 'Spotify',
        'category_id' => (int) $subscriptionCategory['id'],
        'default_amount_clp' => '8250',
    ]);
    $gasService = $serviceDefinitionService->create($userId, ['name' => 'Gas']);
    $inactiveService = $serviceDefinitionService->create($userId, ['name' => 'Servicio inactivo', 'active' => false]);
    $otherService = $serviceDefinitionService->create($otherUserId, ['name' => 'Aguas Andinas']);

    expenses_assert((int) $waterService['category_id'] === (int) $basicCategory['id'], 'Service category was not persisted.');
    expenses_assert($gasService['category_id'] === null && $gasService['default_amount_clp'] === null, 'Service without category/default amount failed.');
    expenses_assert((int) $spotifyService['default_amount_clp'] === 8250 && (int) $inactiveService['active'] === 0, 'Service default amount or inactive flag failed.');
    expenses_assert($serviceDefinitionService->get($otherUserId, (int) $waterService['id']) === null, 'Service ownership was not enforced.');
    expenses_expect_validation(
        fn () => $serviceDefinitionService->create($userId, ['name' => 'Servicio ajeno', 'category_id' => (int) $otherBasicCategory['id']]),
        'Service accepted category from another user.'
    );

    $waterMethods = $serviceDefinitionService->addPaymentMethod($userId, (int) $waterService['id'], (int) $pat['id'], true);
    $serviceDefinitionService->addPaymentMethod($userId, (int) $waterService['id'], (int) $webpay['id'], false);
    $serviceDefinitionService->addPaymentMethod($userId, (int) $waterService['id'], (int) $visa['id'], false);
    $serviceDefinitionService->addPaymentMethod($userId, (int) $spotifyService['id'], (int) $visa['id'], true);
    expenses_assert(count($waterMethods) === 1 && (int) $waterMethods[0]['is_default'] === 1, 'Default payment method was not persisted.');
    expenses_assert($serviceDefinitionService->setDefaultPaymentMethod($userId, (int) $waterService['id'], (int) $webpay['id']), 'Could not change default payment method.');
    $updatedWaterService = $serviceDefinitionService->get($userId, (int) $waterService['id']);
    expenses_assert(count($updatedWaterService['payment_methods']) === 3 && (int) $updatedWaterService['payment_methods'][0]['id'] === (int) $webpay['id'], 'Service did not support several payment methods with one default.');
    expenses_expect_validation(
        fn () => $serviceDefinitionService->addPaymentMethod($userId, (int) $waterService['id'], (int) $webpay['id']),
        'Duplicate service payment method relation was accepted.'
    );
    expenses_expect_validation(
        fn () => $serviceDefinitionService->addPaymentMethod($userId, (int) $waterService['id'], (int) $otherWallet['id']),
        'Service accepted payment method from another user.'
    );

    $waterAugust = $expenseService->create($userId, [
        'service_id' => (int) $waterService['id'],
        'period_month' => '2026-08-01',
        'amount_clp' => '19994',
        'due_on' => '2026-08-30',
        'payment_method_id' => (int) $pat['id'],
        'status' => 'pending',
        'notes' => 'boleta llego tarde',
    ]);
    $spotifyAugust = $expenseService->create($userId, [
        'service_id' => (int) $spotifyService['id'],
        'period_month' => '2026-08-01',
        'amount_clp' => 8250,
        'paid_on' => '2026-08-05',
        'payment_method_id' => (int) $visa['id'],
        'status' => 'paid',
    ]);
    $adHocAugust = $expenseService->create($userId, [
        'category_id' => (int) $techCategory['id'],
        'period_month' => '2026-08-01',
        'description' => 'Reparacion notebook',
        'amount_clp' => 45000,
        'due_on' => '2026-08-12',
        'paid_on' => '2026-08-12',
        'payment_method_id' => (int) $cash['id'],
        'status' => 'paid',
        'notes' => 'Texto plano',
    ]);
    $installmentAugust = $expenseService->create($userId, [
        'description' => 'Compra en cuotas',
        'category_id' => (int) $techCategory['id'],
        'period_month' => '2026-08-01',
        'amount_clp' => 12000,
        'installment_current' => 3,
        'installment_total' => 12,
        'due_on' => '2026-08-01',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'description' => 'Gasto septiembre',
        'period_month' => '2026-09-01',
        'amount_clp' => 1000,
        'status' => 'pending',
    ]);
    $otherExpense = $expenseService->create($otherUserId, [
        'service_id' => (int) $otherService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Gasto ajeno',
        'amount_clp' => 99999,
    ]);

    expenses_assert((int) $waterAugust['category_id'] === (int) $basicCategory['id'] && $waterAugust['description'] === 'Aguas Andinas', 'Expense did not keep service category snapshot or description.');
    expenses_assert($adHocAugust['service_id'] === null && $adHocAugust['description'] === 'Reparacion notebook', 'Ad-hoc expense was not allowed.');
    expenses_assert((int) $waterAugust['amount_clp'] === 19994 && $waterAugust['period_month'] === '2026-08-01', 'CLP amount or period_month failed.');
    expenses_assert($waterAugust['due_on'] === '2026-08-30' && $spotifyAugust['paid_on'] === '2026-08-05', 'due_on or paid_on failed.');
    expenses_assert((int) $waterAugust['payment_method_id'] === (int) $pat['id'] && $waterAugust['notes'] === 'boleta llego tarde', 'Payment method or notes failed.');
    expenses_assert((int) $installmentAugust['installment_current'] === 3 && (int) $installmentAugust['installment_total'] === 12, 'Valid installments failed.');

    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => 'Cuota mala',
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
            'installment_current' => 13,
            'installment_total' => 12,
        ]),
        'current > total installment was accepted.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => 'Cuota incompleta',
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
            'installment_current' => 1,
        ]),
        'Partial installment data was accepted.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => 'Periodo malo',
            'period_month' => '2026-08-02',
            'amount_clp' => 1,
        ]),
        'period_month not on first day was accepted.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => '<b>HTML</b>',
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
        ]),
        'HTML description was accepted.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'service_id' => (int) $waterService['id'],
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
            'payment_method_id' => (int) $cash['id'],
        ]),
        'Expense accepted a non-allowed payment method for the service.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'service_id' => (int) $otherService['id'],
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
        ]),
        'Expense accepted service from another user.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => 'Pago ajeno',
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
            'payment_method_id' => (int) $otherWallet['id'],
        ]),
        'Expense accepted payment method from another user.'
    );
    expenses_expect_validation(
        fn () => $expenseService->create($userId, [
            'description' => 'Estado derivado no persistible',
            'period_month' => '2026-08-01',
            'amount_clp' => 1,
            'status' => 'overdue',
        ]),
        'Derived overdue status was persisted.'
    );

    $august = $expenseService->listForMonth($userId, 2026, 8, $today);
    $september = $expenseService->listForMonth($userId, 2026, 9, $today);
    $augustJson = json_encode($august, JSON_THROW_ON_ERROR);
    expenses_assert(count($august['items']) === 4 && !str_contains($augustJson, 'Gasto septiembre'), 'August expenses leaked into September or vice versa.');
    expenses_assert(count($september['items']) === 1, 'September list did not isolate period_month.');
    expenses_assert($august['total_amount_clp'] === 85244, 'Monthly total is wrong.');
    expenses_assert($august['paid_amount_clp'] === 53250 && $august['pending_amount_clp'] === 19994, 'Paid or pending totals are wrong.');
    expenses_assert($august['overdue_amount_clp'] === 12000 && $august['counts']['overdue'] === 1, 'Derived overdue total is wrong.');
    expenses_assert($expenseService->get($userId, (int) $installmentAugust['id'], $today)['derived_status'] === 'overdue', 'Overdue was not derived with America/Santiago today.');
    expenses_assert($expenseService->get($otherUserId, (int) $waterAugust['id']) === null, 'User could read another user expense.');
    expenses_assert($expenseService->get($userId, (int) $otherExpense['id']) === null, 'User could read another user expense by id.');

    $categoryService->update($userId, (int) $basicCategory['id'], ['name' => 'Servicios basicos editado']);
    $historicalWater = $expenseService->get($userId, (int) $waterAugust['id']);
    expenses_assert((int) $historicalWater['category_id'] === (int) $basicCategory['id'], 'Expense category snapshot was lost after service/category changes.');

    echo "Expenses model: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses model: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($userIds as $userId) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username, :fresh_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
        'fresh_username' => $freshUsername,
    ]);
}

exit($exitCode);
