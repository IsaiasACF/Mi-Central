<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpenseHistoryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_history_' . $suffix;
$otherUsername = 'test_expenses_history_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-history-');
$userIds = [];
$exitCode = 1;

function expenses_history_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_history_create_user(PDO $pdo, string $username, string $password): int
{
    $hash = password_hash($password, PASSWORD_ARGON2ID);

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

function expenses_history_http(string $url, string $method = 'GET', ?string $cookieFile = null): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function expenses_history_form(string $url, array $postFields, string $cookieFile): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function expenses_history_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function expenses_history_month(array $history, string $periodMonth): array
{
    foreach ((array) ($history['monthly_totals'] ?? []) as $month) {
        if (($month['period_month'] ?? '') === $periodMonth) {
            return $month;
        }
    }

    throw new RuntimeException('Month not found: ' . $periodMonth);
}

function expenses_history_row_by_name(array $rows, string $name): array
{
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $name) {
            return $row;
        }
    }

    throw new RuntimeException('Row not found: ' . $name);
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

    $userId = expenses_history_create_user($pdo, $username, $password);
    $otherUserId = expenses_history_create_user($pdo, $otherUsername, $password);
    $userIds = [$userId, $otherUserId];

    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $expenseService = new ExpenseService(new ExpenseRepository($pdo));
    $historyService = new ExpenseHistoryService($pdo);
    $today = new DateTimeImmutable('2026-08-15 10:00:00', new DateTimeZone('America/Santiago'));

    $subscriptions = $categoryService->create($userId, ['name' => 'Suscripciones', 'color' => '#22C55E']);
    $utilities = $categoryService->create($userId, ['name' => 'Servicios basicos', 'color' => '#3366CC']);
    $historic = $categoryService->create($userId, ['name' => 'Categoria historica', 'color' => '#AA5500', 'active' => false]);
    $otherCategory = $categoryService->create($otherUserId, ['name' => 'Suscripciones']);

    $visa = $paymentMethodService->create($userId, ['name' => 'Visa Santander', 'type' => 'card']);
    $account = $paymentMethodService->create($userId, ['name' => 'Cuenta Santander', 'type' => 'bank_account']);
    $inactiveMethod = $paymentMethodService->create($userId, ['name' => 'Metodo historico', 'type' => 'card']);
    $otherMethod = $paymentMethodService->create($otherUserId, ['name' => 'Visa ajena', 'type' => 'card']);

    $spotify = $serviceDefinitionService->create($userId, ['name' => 'Spotify', 'category_id' => (string) $subscriptions['id']]);
    $water = $serviceDefinitionService->create($userId, ['name' => 'Aguas Andinas', 'category_id' => (string) $utilities['id']]);
    $entel = $serviceDefinitionService->create($userId, ['name' => 'Entel', 'category_id' => (string) $historic['id'], 'active' => false]);
    $dividend = $serviceDefinitionService->create($userId, ['name' => 'Dividendo']);
    $otherService = $serviceDefinitionService->create($otherUserId, ['name' => 'Servicio ajeno']);

    $expenseService->create($userId, [
        'service_id' => (string) $spotify['id'],
        'category_id' => (string) $subscriptions['id'],
        'payment_method_id' => (string) $visa['id'],
        'period_month' => '2025-12-01',
        'description' => 'Spotify historico',
        'amount_clp' => '30.000',
        'status' => 'paid',
        'paid_on' => '2025-12-10',
    ]);
    $expenseService->create($userId, [
        'service_id' => (string) $spotify['id'],
        'category_id' => (string) $subscriptions['id'],
        'payment_method_id' => (string) $visa['id'],
        'period_month' => '2026-03-01',
        'description' => 'Spotify',
        'amount_clp' => '10.000',
        'status' => 'paid',
        'paid_on' => '2026-03-10',
    ]);
    $expenseService->create($userId, [
        'service_id' => (string) $water['id'],
        'category_id' => (string) $utilities['id'],
        'payment_method_id' => (string) $account['id'],
        'period_month' => '2026-04-01',
        'description' => 'Aguas Andinas',
        'amount_clp' => '20.000',
        'status' => 'paid',
        'paid_on' => '2026-04-12',
    ]);
    $expenseService->create($userId, [
        'category_id' => (string) $utilities['id'],
        'period_month' => '2026-06-01',
        'description' => 'Gas variable',
        'amount_clp' => '',
        'due_on' => '2026-08-20',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'service_id' => (string) $entel['id'],
        'category_id' => (string) $historic['id'],
        'payment_method_id' => (string) $inactiveMethod['id'],
        'period_month' => '2026-07-01',
        'description' => 'Entel',
        'amount_clp' => '40.000',
        'due_on' => '2026-07-10',
        'status' => 'pending',
    ]);
    $paymentMethodService->update($userId, (int) $inactiveMethod['id'], ['active' => false]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Gasto sin categoria',
        'amount_clp' => '50.000',
        'due_on' => '2026-08-20',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'service_id' => (string) $dividend['id'],
        'period_month' => '2026-08-01',
        'description' => 'Dividendo',
        'amount_clp' => '70.000',
        'status' => 'paid',
        'paid_on' => '2026-08-05',
    ]);
    $expenseService->create($userId, [
        'category_id' => (string) $subscriptions['id'],
        'payment_method_id' => (string) $visa['id'],
        'period_month' => '2026-08-01',
        'description' => 'Cancelado fuera',
        'amount_clp' => '999.999',
        'status' => 'cancelled',
    ]);
    $expenseService->create($otherUserId, [
        'service_id' => (string) $otherService['id'],
        'category_id' => (string) $otherCategory['id'],
        'payment_method_id' => (string) $otherMethod['id'],
        'period_month' => '2026-08-01',
        'description' => 'Gasto ajeno',
        'amount_clp' => '888.888',
        'status' => 'paid',
        'paid_on' => '2026-08-01',
    ]);

    $six = $historyService->history($userId, ['range' => '6m'], $today);
    expenses_history_assert($six['start_month'] === '2026-03-01' && $six['end_month'] === '2026-08-01', '6m range is wrong.');
    expenses_history_assert(count($six['monthly_totals']) === 6, '6m range did not include six months.');
    expenses_history_assert((int) $six['total_known_clp'] === 190000, 'Known total is wrong.');
    expenses_history_assert((int) $six['total_paid_clp'] === 100000, 'Paid total is wrong.');
    expenses_history_assert((int) $six['average_monthly_clp'] === 31667, 'Average did not include empty months.');
    expenses_history_assert((int) $six['monthly_unknown_count'] === 1, 'Unknown count is wrong.');
    expenses_history_assert((int) expenses_history_month($six, '2026-05-01')['known_amount_clp'] === 0, 'Empty month was not included as zero.');
    expenses_history_assert((int) expenses_history_month($six, '2026-06-01')['unknown_amount_count'] === 1, 'Monthly unknown count failed.');
    expenses_history_assert(($six['highest_month']['period_month'] ?? '') === '2026-08-01', 'Highest month is wrong.');
    expenses_history_assert(($six['lowest_month']['period_month'] ?? '') === '2026-05-01', 'Lowest month is wrong.');
    expenses_history_assert((expenses_history_month($six, '2026-08-01')['comparison']['percentage'] ?? null) === 200.0, 'Monthly percentage comparison failed.');
    $juneComparison = expenses_history_month($six, '2026-06-01')['comparison'];
    expenses_history_assert(is_array($juneComparison) && array_key_exists('percentage', $juneComparison) && $juneComparison['percentage'] === null, 'Previous zero comparison should not calculate percentage.');
    expenses_history_assert((int) ($six['status_breakdown']['pending_clp'] ?? 0) === 50000 && (int) ($six['status_breakdown']['overdue_clp'] ?? 0) === 40000, 'Status breakdown is wrong.');

    $three = $historyService->history($userId, ['range' => '3m'], $today);
    expenses_history_assert($three['start_month'] === '2026-06-01' && (int) $three['total_known_clp'] === 160000, '3m range failed.');
    expenses_history_assert((int) $three['average_monthly_clp'] === 53333, '3m average failed.');

    $twelve = $historyService->history($userId, ['range' => '12m'], $today);
    expenses_history_assert($twelve['start_month'] === '2025-09-01' && $twelve['end_month'] === '2026-08-01' && (int) $twelve['total_known_clp'] === 220000, '12m moving window failed.');

    $ytd = $historyService->history($userId, ['range' => 'ytd'], $today);
    expenses_history_assert($ytd['start_month'] === '2026-01-01' && $ytd['end_month'] === '2026-08-01' && count($ytd['monthly_totals']) === 8, 'YTD range failed.');
    expenses_history_assert((int) $ytd['average_monthly_clp'] === 23750, 'YTD average failed.');

    $previousYear = $historyService->history($userId, ['range' => 'previous_year'], $today);
    expenses_history_assert($previousYear['start_month'] === '2025-01-01' && $previousYear['end_month'] === '2025-12-01' && count($previousYear['monthly_totals']) === 12, 'Previous year range failed.');
    expenses_history_assert((int) $previousYear['total_known_clp'] === 30000 && (int) $previousYear['average_monthly_clp'] === 2500, 'Previous year totals failed.');

    $categoryRows = $six['category_breakdown'];
    expenses_history_assert((int) expenses_history_row_by_name($categoryRows, 'Sin categoria')['known_amount_clp'] === 120000, 'Uncategorized breakdown failed.');
    expenses_history_assert((int) expenses_history_row_by_name($categoryRows, 'Categoria historica')['known_amount_clp'] === 40000, 'Inactive category is missing.');
    expenses_history_assert((int) expenses_history_row_by_name($categoryRows, 'Servicios basicos')['unknown_amount_count'] === 1, 'Category unknown count failed.');

    $paymentRows = $six['payment_method_breakdown'];
    expenses_history_assert((int) expenses_history_row_by_name($paymentRows, 'Sin medio definido')['known_amount_clp'] === 120000, 'Undefined payment method failed.');
    expenses_history_assert((int) expenses_history_row_by_name($paymentRows, 'Metodo historico')['known_amount_clp'] === 40000, 'Inactive payment method is missing.');

    $serviceRows = $six['service_breakdown'];
    expenses_history_assert(($serviceRows[0]['name'] ?? '') === 'Dividendo' && (int) $serviceRows[0]['known_amount_clp'] === 70000, 'Top service is wrong.');
    expenses_history_assert(!str_contains(json_encode($serviceRows, JSON_THROW_ON_ERROR), 'Gasto sin categoria'), 'Ad-hoc expenses should not be mixed into top services.');

    $filteredCategory = $historyService->history($userId, ['range' => '6m', 'category_id' => (string) $utilities['id']], $today);
    expenses_history_assert((int) $filteredCategory['total_known_clp'] === 20000 && (int) $filteredCategory['monthly_unknown_count'] === 1, 'Category filter failed.');
    expenses_history_assert($filteredCategory['category_series'] !== [], 'Category series was not returned for selected category.');

    $filteredService = $historyService->history($userId, ['range' => '6m', 'service_id' => (string) $entel['id']], $today);
    expenses_history_assert((int) $filteredService['total_known_clp'] === 40000 && $filteredService['service_series'] !== [], 'Service filter or series failed.');

    $filteredPayment = $historyService->history($userId, ['range' => '6m', 'payment_method_id' => (string) $inactiveMethod['id']], $today);
    expenses_history_assert((int) $filteredPayment['total_known_clp'] === 40000, 'Payment method filter failed.');

    $foreignCategory = $historyService->history($userId, ['range' => '6m', 'category_id' => (string) $otherCategory['id']], $today);
    $foreignService = $historyService->history($userId, ['range' => '6m', 'service_id' => (string) $otherService['id']], $today);
    $foreignMethod = $historyService->history($userId, ['range' => '6m', 'payment_method_id' => (string) $otherMethod['id']], $today);
    expenses_history_assert((int) $foreignCategory['total_known_clp'] === 190000 && $foreignCategory['filters']['category_id'] === null, 'Foreign category filter leaked or applied.');
    expenses_history_assert((int) $foreignService['total_known_clp'] === 190000 && $foreignService['filters']['service_id'] === null, 'Foreign service filter leaked or applied.');
    expenses_history_assert((int) $foreignMethod['total_known_clp'] === 190000 && $foreignMethod['filters']['payment_method_id'] === null, 'Foreign payment method filter leaked or applied.');

    $loginPage = expenses_history_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expenses_history_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expenses_history_form('http://127.0.0.1/login.php', [
        'csrf_token' => expenses_history_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expenses_history_assert($login['status'] === 302, 'Login failed.');
    $page = expenses_history_http('http://127.0.0.1/index.php?section=expenses&tab=history&range=6m', 'GET', $cookieFile);
    expenses_history_assert($page['status'] === 200, 'History page did not load.');
    $httpHistory = $historyService->history($userId, ['range' => '6m'], DateTimeHelper::nowLocal());
    $expectedHttpTotal = '$' . number_format((int) ($httpHistory['total_known_clp'] ?? 0), 0, ',', '.');
    expenses_history_assert(str_contains($page['body'], 'Historial de gastos') && str_contains($page['body'], 'Total registrado') && str_contains($page['body'], $expectedHttpTotal), 'History KPIs did not render.');
    expenses_history_assert(str_contains($page['body'], 'Evoluci') && str_contains($page['body'], 'Datos parciales') && str_contains($page['body'], 'Servicios con mayor gasto'), 'History analysis sections did not render.');
    expenses_history_assert(str_contains($page['body'], 'href="/index.php?section=expenses&amp;month=2026-08"'), 'History month link did not point to monthly view.');
    expenses_history_assert(!str_contains($page['body'], '$999.999') && !str_contains($page['body'], 'Gasto ajeno'), 'History page included cancelled or foreign data.');
    $filteredPage = expenses_history_http('http://127.0.0.1/index.php?section=expenses&tab=history&range=6m&category_id=' . (int) $utilities['id'], 'GET', $cookieFile);
    expenses_history_assert($filteredPage['status'] === 200 && str_contains($filteredPage['body'], 'Evoluci') && str_contains($filteredPage['body'], 'Servicios basicos'), 'Filtered history page did not render category evolution.');
    expenses_history_assert(str_contains($filteredPage['body'], 'expense-history-chart') && str_contains($filteredPage['body'], 'expense-history-status-grid'), 'History CSS hooks are missing.');

    echo "Expenses history: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses history: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $cleanup = $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
        $cleanup->execute($userIds);
    }

    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }
}

exit($exitCode);
