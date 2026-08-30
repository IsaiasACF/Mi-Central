<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpenseMonthlySummaryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_summary_' . $suffix;
$otherUsername = 'test_expenses_summary_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-summary-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-summary-other-');
$userIds = [];
$exitCode = 1;

function expenses_summary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_summary_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $payload = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $requestHeaders = [];

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
    }

    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

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

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $requestHeaders[] = 'Content-Type: application/json';
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    $json = json_decode($responseBody, true);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
    ];
}

function expenses_summary_form(string $url, array $postFields, string $cookieFile): array
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

function expenses_summary_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function expenses_summary_create_user(PDO $pdo, string $username, string $password): int
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

function expenses_summary_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = expenses_summary_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expenses_summary_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expenses_summary_form('http://127.0.0.1/login.php', [
        'csrf_token' => expenses_summary_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expenses_summary_assert($login['status'] === 302, 'Login failed.');
    $page = expenses_summary_http('http://127.0.0.1/index.php?section=expenses&month=2026-08', 'GET', $cookieFile);
    expenses_summary_assert($page['status'] === 200, 'Expenses page did not load after login.');

    return expenses_summary_csrf($page['body']);
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

    $userId = expenses_summary_create_user($pdo, $username, $password);
    $otherUserId = expenses_summary_create_user($pdo, $otherUsername, $password);
    $userIds = [$userId, $otherUserId];
    $csrf = expenses_summary_login($username, $password, $cookieFile);
    expenses_summary_login($otherUsername, $password, $otherCookieFile);

    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $expenseService = new ExpenseService(new ExpenseRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
    $summaryService = new ExpenseMonthlySummaryService($pdo, DateTimeHelper::DEFAULT_TIMEZONE);
    $today = new DateTimeImmutable('2026-08-15 09:00:00', new DateTimeZone('America/Santiago'));

    $servicesCategory = $categoryService->create($userId, ['name' => 'Servicios basicos', 'color' => '#3366CC']);
    $subscriptionCategory = $categoryService->create($userId, ['name' => 'Suscripciones', 'color' => '#22C55E']);
    $inactiveCategory = $categoryService->create($userId, ['name' => 'Categoria historica', 'color' => '#AA5500', 'active' => false]);
    $otherCategory = $categoryService->create($otherUserId, ['name' => 'Servicios basicos']);

    $visa = $paymentMethodService->create($userId, ['name' => 'Visa Santander', 'type' => 'card']);
    $account = $paymentMethodService->create($userId, ['name' => 'Cuenta Santander', 'type' => 'bank_account']);
    $pat = $paymentMethodService->create($userId, ['name' => 'PAT Santander', 'type' => 'automatic_payment']);
    $inactiveMethod = $paymentMethodService->create($userId, ['name' => 'Metodo historico', 'type' => 'card', 'active' => false]);
    $otherMethod = $paymentMethodService->create($otherUserId, ['name' => 'Mercado Pago', 'type' => 'wallet']);

    $spotifyService = $serviceDefinitionService->create($userId, ['name' => 'Spotify', 'category_id' => (string) $subscriptionCategory['id']]);
    $waterService = $serviceDefinitionService->create($userId, ['name' => 'Aguas Andinas', 'category_id' => (string) $servicesCategory['id']]);
    $otherService = $serviceDefinitionService->create($otherUserId, ['name' => 'Servicio ajeno', 'category_id' => (string) $otherCategory['id']]);

    $expenseService->create($userId, [
        'service_id' => (string) $spotifyService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Spotify',
        'category_id' => (string) $subscriptionCategory['id'],
        'amount_clp' => '10.000',
        'due_on' => '2026-08-10',
        'paid_on' => '2026-08-10',
        'payment_method_id' => (string) $visa['id'],
        'status' => 'paid',
    ]);
    $expenseService->create($userId, [
        'service_id' => (string) $waterService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Aguas Andinas',
        'category_id' => (string) $servicesCategory['id'],
        'amount_clp' => '5.000',
        'due_on' => '2026-08-12',
        'payment_method_id' => (string) $pat['id'],
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Internet',
        'category_id' => (string) $servicesCategory['id'],
        'amount_clp' => '20.000',
        'due_on' => '2026-08-16',
        'payment_method_id' => (string) $account['id'],
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Gas variable',
        'category_id' => (string) $servicesCategory['id'],
        'amount_clp' => '',
        'due_on' => '2026-08-18',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Cuenta sin categoria',
        'amount_clp' => '3.000',
        'due_on' => '2026-08-15',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Historico inactivo',
        'category_id' => (string) $inactiveCategory['id'],
        'amount_clp' => '15.000',
        'due_on' => '2026-08-30',
        'payment_method_id' => (string) $inactiveMethod['id'],
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Cancelado fuera de resumen',
        'category_id' => (string) $subscriptionCategory['id'],
        'amount_clp' => '999.999',
        'payment_method_id' => (string) $visa['id'],
        'status' => 'cancelled',
    ]);
    $expenseService->create($otherUserId, [
        'service_id' => (string) $otherService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Gasto ajeno',
        'category_id' => (string) $otherCategory['id'],
        'amount_clp' => '777.777',
        'payment_method_id' => (string) $otherMethod['id'],
        'status' => 'paid',
    ]);

    $summary = $summaryService->summary($userId, '2026-08-01', $today);
    expenses_summary_assert($summary['expense_count'] === 6, 'Expense count should exclude cancelled and foreign expenses.');
    expenses_summary_assert($summary['total_known_clp'] === 53000, 'Total known is wrong.');
    expenses_summary_assert($summary['paid_known_clp'] === 10000, 'Paid known is wrong.');
    expenses_summary_assert($summary['pending_known_clp'] === 38000, 'Pending known is wrong.');
    expenses_summary_assert($summary['overdue_known_clp'] === 5000, 'Overdue known is wrong.');
    expenses_summary_assert($summary['unknown_amount_count'] === 1, 'Unknown amount count is wrong.');
    expenses_summary_assert($summary['paid_percentage'] === 19, 'Paid percentage is wrong.');

    $categoryNames = array_column($summary['category_breakdown'], 'name');
    expenses_summary_assert(in_array('Servicios basicos', $categoryNames, true), 'Category breakdown omitted active category.');
    expenses_summary_assert(in_array('Categoria historica', $categoryNames, true), 'Category breakdown omitted inactive category.');
    expenses_summary_assert(in_array('Sin categoria', $categoryNames, true), 'Category breakdown omitted uncategorized expenses.');
    expenses_summary_assert((int) $summary['category_breakdown'][0]['known_amount_clp'] === 25000, 'Category sums are wrong.');

    $paymentNames = array_column($summary['payment_method_breakdown'], 'name');
    expenses_summary_assert(in_array('Sin medio definido', $paymentNames, true), 'Payment breakdown omitted undefined method.');
    expenses_summary_assert(in_array('Metodo historico', $paymentNames, true), 'Payment breakdown omitted inactive method.');
    expenses_summary_assert($summary['upcoming_due'][0]['description'] === 'Cuenta sin categoria', 'Today due item should be first upcoming.');
    expenses_summary_assert($summary['overdue_items'][0]['description'] === 'Aguas Andinas', 'Overdue item was not listed.');

    $filteredByCategory = $expenseService->listForMonth($userId, 2026, 8, $today, ['search' => 'Servicios basicos']);
    $filteredByPayment = $expenseService->listForMonth($userId, 2026, 8, $today, ['search' => 'Cuenta Santander']);
    expenses_summary_assert(count($filteredByCategory['items']) === 3, 'Search by category failed.');
    expenses_summary_assert(count($filteredByPayment['items']) === 1, 'Search by payment method failed.');
    expenses_summary_assert($summaryService->summary($userId, '2026-09-01', $today)['expense_count'] === 0, 'Future empty month should be readable.');

    $page = expenses_summary_http('http://127.0.0.1/index.php?section=expenses&month=2026-08&search=Spotify', 'GET', $cookieFile);
    expenses_summary_assert($page['status'] === 200, 'Monthly summary page did not load.');
    expenses_summary_assert(str_contains($page['body'], 'Agosto 2026') && str_contains($page['body'], 'Total conocido') && str_contains($page['body'], '$53.000'), 'Monthly KPIs did not render.');
    expenses_summary_assert(str_contains($page['body'], 'Distribuci&oacute;n por categor') && str_contains($page['body'], 'Distribuci&oacute;n por medio de pago') && str_contains($page['body'], 'expense-progress-bar') && !str_contains($page['body'], 'expense-breakdown-bar'), 'Monthly distribution or payment progress did not render clearly.');
    expenses_summary_assert(str_contains($page['body'], 'Pr&oacute;ximos vencimientos') && str_contains($page['body'], 'Vencidos') && str_contains($page['body'], 'Monto pendiente'), 'Due sections or unknown amount label did not render.');
    expenses_summary_assert(str_contains($page['body'], 'Resultados:') && str_contains($page['body'], 'Spotify') && str_contains($page['body'], '$53.000'), 'Filters should not replace monthly KPIs.');

    $emptyPage = expenses_summary_http('http://127.0.0.1/index.php?section=expenses&month=2026-09', 'GET', $cookieFile);
    expenses_summary_assert(str_contains($emptyPage['body'], 'Septiembre 2026') && str_contains($emptyPage['body'], 'No tienes gastos registrados') && !str_contains($emptyPage['body'], 'expense-breakdown-bar'), 'Empty month rendered noisy charts.');

    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    expenses_summary_assert(
        is_string($appCss)
        && str_contains($appCss, 'color-scheme: dark')
        && str_contains($appCss, '.expense-summary-grid')
        && str_contains($appCss, '@media')
        && str_contains($appCss, '.expense-due-item'),
        'Monthly summary CSS hooks are missing.'
    );

    echo "Expenses monthly summary: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses monthly summary: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $cleanup = $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
        $cleanup->execute($userIds);
    }

    if (is_string($cookieFile)) {
        @unlink($cookieFile);
    }

    if (is_string($otherCookieFile)) {
        @unlink($otherCookieFile);
    }
}

exit($exitCode);
