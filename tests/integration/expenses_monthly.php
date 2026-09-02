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

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_monthly_' . $suffix;
$otherUsername = 'test_expenses_monthly_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-monthly-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-monthly-other-');
$userIds = [];
$exitCode = 1;

function expenses_monthly_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_monthly_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $payload = null, array $headers = []): array
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

function expenses_monthly_form(string $url, array $postFields, string $cookieFile): array
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

function expenses_monthly_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function expenses_monthly_create_user(PDO $pdo, string $username, string $password): int
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

function expenses_monthly_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = expenses_monthly_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expenses_monthly_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expenses_monthly_form('http://127.0.0.1/login.php', [
        'csrf_token' => expenses_monthly_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expenses_monthly_assert($login['status'] === 302, 'Login failed.');
    $page = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-08', 'GET', $cookieFile);
    expenses_monthly_assert($page['status'] === 200, 'Expenses monthly page did not load after login.');

    return expenses_monthly_csrf($page['body']);
}

function expenses_monthly_post(string $action, array $payload, string $cookieFile, string $csrf): array
{
    return expenses_monthly_http(
        'http://127.0.0.1/api/expenses/expenses.php',
        'POST',
        $cookieFile,
        ['action' => $action] + $payload,
        ['X-CSRF-Token' => $csrf],
    );
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

    $userId = expenses_monthly_create_user($pdo, $username, $password);
    $otherUserId = expenses_monthly_create_user($pdo, $otherUsername, $password);
    $userIds = [$userId, $otherUserId];
    $csrf = expenses_monthly_login($username, $password, $cookieFile);
    $otherCsrf = expenses_monthly_login($otherUsername, $password, $otherCookieFile);

    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $expenseService = new ExpenseService(new ExpenseRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
    $fixedToday = new DateTimeImmutable('2026-08-15 09:00:00', new DateTimeZone('America/Santiago'));

    $basicCategory = $categoryService->create($userId, ['name' => 'Servicios basicos', 'color' => '#3366CC']);
    $subscriptionCategory = $categoryService->create($userId, ['name' => 'Suscripciones', 'color' => '#22C55E']);
    $otherCategory = $categoryService->create($otherUserId, ['name' => 'Servicios basicos']);
    $pat = $paymentMethodService->create($userId, ['name' => 'PAT Santander', 'type' => 'automatic_payment', 'institution_name' => 'Santander']);
    $webpay = $paymentMethodService->create($userId, ['name' => 'WebPay', 'type' => 'webpay']);
    $visa = $paymentMethodService->create($userId, ['name' => 'Visa Santander', 'type' => 'card']);
    $otherWallet = $paymentMethodService->create($otherUserId, ['name' => 'Mercado Pago', 'type' => 'wallet']);
    $waterService = $serviceDefinitionService->create($userId, [
        'name' => 'Aguas Andinas',
        'category_id' => (string) $basicCategory['id'],
        'payment_method_ids' => [(string) $pat['id'], (string) $webpay['id']],
        'default_payment_method_id' => (string) $pat['id'],
    ]);
    $spotifyService = $serviceDefinitionService->create($userId, [
        'name' => 'Spotify',
        'category_id' => (string) $subscriptionCategory['id'],
        'default_amount_clp' => '8.250',
        'payment_method_ids' => [(string) $visa['id']],
        'default_payment_method_id' => (string) $visa['id'],
    ]);
    $otherService = $serviceDefinitionService->create($otherUserId, ['name' => 'Servicio ajeno']);

    $emptyPage = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-08', 'GET', $cookieFile);
    expenses_monthly_assert(str_contains($emptyPage['body'], 'Mes actual') && str_contains($emptyPage['body'], 'Configuraci') && str_contains($emptyPage['body'], 'No tienes gastos registrados'), 'Monthly empty state or internal navigation did not render.');
    expenses_monthly_assert(str_contains($emptyPage['body'], 'Nuevo gasto') && str_contains($emptyPage['body'], 'data-service-options') && str_contains($emptyPage['body'], '8250') && str_contains($emptyPage['body'], 'Es un pago en cuotas'), 'Monthly create UI or service suggestions did not render.');
    expenses_monthly_assert(str_contains($emptyPage['body'], 'expenses-month-filters') && !str_contains($emptyPage['body'], 'expense-breakdown-bar'), 'Monthly empty state should keep filters without empty charts.');

    $missingCsrf = expenses_monthly_http('http://127.0.0.1/api/expenses/expenses.php', 'POST', $cookieFile, [
        'action' => 'create',
        'description' => 'Sin CSRF',
    ]);
    expenses_monthly_assert($missingCsrf['status'] === 403, 'Monthly expenses API did not require CSRF.');

    $waterCreate = expenses_monthly_post('create', [
        'service_id' => (string) $waterService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Aguas Andinas',
        'category_id' => (string) $basicCategory['id'],
        'amount_clp' => '19.994',
        'due_on' => '2026-08-30',
        'payment_method_id' => (string) $pat['id'],
        'status' => 'pending',
        'notes' => 'Boleta llego tarde',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($waterCreate['status'] === 201 && (int) ($waterCreate['json']['data']['item']['amount_clp'] ?? 0) === 19994, 'Expense create from service failed.');
    $waterExpenseId = (int) $waterCreate['json']['data']['item']['id'];

    $spotifyCreate = expenses_monthly_post('create', [
        'service_id' => (string) $spotifyService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Spotify',
        'category_id' => (string) $subscriptionCategory['id'],
        'amount_clp' => '8250',
        'due_on' => '2026-08-10',
        'paid_on' => '2026-08-05',
        'payment_method_id' => (string) $visa['id'],
        'status' => 'paid',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($spotifyCreate['status'] === 201 && ($spotifyCreate['json']['data']['item']['paid_on'] ?? '') === '2026-08-05', 'Paid expense create failed.');

    $otherMethodCreate = expenses_monthly_post('create', [
        'service_id' => (string) $waterService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Aguas Andinas otro medio',
        'category_id' => (string) $basicCategory['id'],
        'amount_clp' => '1000',
        'payment_method_id' => (string) $visa['id'],
    ], $cookieFile, $csrf);
    expenses_monthly_assert($otherMethodCreate['status'] === 201, 'Active own other payment method was rejected.');

    $adHocCreate = expenses_monthly_post('create', [
        'period_month' => '2026-08-01',
        'description' => 'Reparacion notebook',
        'category_id' => (string) $basicCategory['id'],
        'amount_clp' => '45.000',
        'installment_current' => '3',
        'installment_total' => '12',
        'due_on' => '2026-08-10',
        'payment_method_id' => (string) $webpay['id'],
        'status' => 'pending',
        'notes' => 'Nota larga sin HTML',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($adHocCreate['status'] === 201 && (int) ($adHocCreate['json']['data']['item']['installment_current'] ?? 0) === 3, 'Ad-hoc/installment expense create failed.');
    $adHocExpenseId = (int) $adHocCreate['json']['data']['item']['id'];

    $september = expenses_monthly_post('create', [
        'period_month' => '2026-09-01',
        'description' => 'Septiembre aislado',
        'amount_clp' => '3000',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($september['status'] === 201, 'September expense create failed.');

    $invalidInstallment = expenses_monthly_post('create', [
        'period_month' => '2026-08-01',
        'description' => 'Cuota mala',
        'amount_clp' => '1000',
        'installment_current' => '13',
        'installment_total' => '12',
    ], $cookieFile, $csrf);
    $partialInstallment = expenses_monthly_post('create', [
        'period_month' => '2026-08-01',
        'description' => 'Cuota parcial',
        'amount_clp' => '1000',
        'installment_current' => '1',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($invalidInstallment['status'] === 422 && $partialInstallment['status'] === 422, 'Invalid installments were accepted.');

    $foreignService = expenses_monthly_post('create', [
        'service_id' => (string) $otherService['id'],
        'period_month' => '2026-08-01',
        'description' => 'Servicio ajeno',
        'amount_clp' => '1000',
    ], $cookieFile, $csrf);
    $foreignCategory = expenses_monthly_post('create', [
        'period_month' => '2026-08-01',
        'description' => 'Categoria ajena',
        'category_id' => (string) $otherCategory['id'],
        'amount_clp' => '1000',
    ], $cookieFile, $csrf);
    $foreignPayment = expenses_monthly_post('create', [
        'period_month' => '2026-08-01',
        'description' => 'Medio ajeno',
        'payment_method_id' => (string) $otherWallet['id'],
        'amount_clp' => '1000',
    ], $cookieFile, $csrf);
    expenses_monthly_assert($foreignService['status'] === 422 && $foreignCategory['status'] === 422 && $foreignPayment['status'] === 422, 'Foreign relations were accepted.');

    $markPaid = expenses_monthly_post('mark-paid', ['id' => $adHocExpenseId, 'paid_on' => '2026-08-15'], $cookieFile, $csrf);
    expenses_monthly_assert($markPaid['status'] === 200 && ($markPaid['json']['data']['item']['status'] ?? '') === 'paid' && ($markPaid['json']['data']['item']['paid_on'] ?? '') === '2026-08-15', 'Mark paid failed.');
    $reopen = expenses_monthly_post('reopen', ['id' => $adHocExpenseId], $cookieFile, $csrf);
    expenses_monthly_assert(
        $reopen['status'] === 200
        && ($reopen['json']['data']['item']['status'] ?? '') === 'pending'
        && array_key_exists('paid_on', $reopen['json']['data']['item'])
        && $reopen['json']['data']['item']['paid_on'] === null,
        'Reopen failed.'
    );
    $cancel = expenses_monthly_post('cancel', ['id' => $adHocExpenseId], $cookieFile, $csrf);
    $reactivate = expenses_monthly_post('reactivate', ['id' => $adHocExpenseId], $cookieFile, $csrf);
    expenses_monthly_assert($cancel['status'] === 200 && $reactivate['status'] === 200 && ($reactivate['json']['data']['item']['status'] ?? '') === 'pending', 'Cancel/reactivate failed.');

    $foreignUpdate = expenses_monthly_post('update', [
        'id' => $waterExpenseId,
        'description' => 'Intento ajeno',
    ], $otherCookieFile, $otherCsrf);
    expenses_monthly_assert($foreignUpdate['status'] === 404, 'Another user could edit a private expense.');

    $august = $expenseService->listForMonth($userId, 2026, 8, $fixedToday);
    $septemberList = $expenseService->listForMonth($userId, 2026, 9, $fixedToday);
    expenses_monthly_assert(count($august['items']) === 4 && count($septemberList['items']) === 1, 'Month isolation failed.');
    expenses_monthly_assert($august['total_amount_clp'] === 74244 && $august['paid_amount_clp'] === 8250 && $august['pending_amount_clp'] === 20994 && $august['overdue_amount_clp'] === 45000, 'Monthly KPI totals are wrong.');
    expenses_monthly_assert($expenseService->listForMonth($userId, 2026, 8, $fixedToday, ['status' => 'overdue'])['filtered_summary']['overdue_amount_clp'] === 45000, 'Overdue filter failed.');
    expenses_monthly_assert($expenseService->listForMonth($userId, 2026, 8, $fixedToday, [
        'status' => 'paid',
        'category_id' => (string) $subscriptionCategory['id'],
        'payment_method_id' => (string) $visa['id'],
        'service_id' => (string) $spotifyService['id'],
        'search' => 'Spotify',
    ])['filtered_summary']['paid_amount_clp'] === 8250, 'Combined filters failed.');

    $filteredPage = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-08&status=paid&search=Spotify', 'GET', $cookieFile);
    expenses_monthly_assert(str_contains($filteredPage['body'], 'Total del mes') && str_contains($filteredPage['body'], 'Resultados:') && str_contains($filteredPage['body'], 'Spotify') && !str_contains($filteredPage['body'], 'Septiembre aislado'), 'Filtered monthly page failed.');
    $invalidMonthPage = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-99', 'GET', $cookieFile);
    expenses_monthly_assert($invalidMonthPage['status'] === 200 && str_contains($invalidMonthPage['body'], 'Gastos'), 'Invalid month was not handled safely.');

    $categoryService->update($userId, (int) $basicCategory['id'], ['active' => false]);
    $paymentMethodService->update($userId, (int) $pat['id'], ['active' => false]);
    $serviceDefinitionService->update($userId, (int) $waterService['id'], ['active' => false]);
    $serviceDefinitionService->update($userId, (int) $spotifyService['id'], ['default_amount_clp' => '9000']);
    $historicalPage = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-08', 'GET', $cookieFile);
    expenses_monthly_assert(str_contains($historicalPage['body'], 'Aguas Andinas') && str_contains($historicalPage['body'], 'Servicios basicos') && str_contains($historicalPage['body'], 'PAT Santander') && str_contains($historicalPage['body'], '$8.250'), 'Inactive historical labels or snapshot amount were lost.');

    $otherPage = expenses_monthly_http('http://127.0.0.1/index.php?section=expenses&month=2026-08', 'GET', $otherCookieFile);
    expenses_monthly_assert(!str_contains($otherPage['body'], 'Boleta llego tarde') && !str_contains($otherPage['body'], 'Aguas Andinas otro medio'), 'Another user could see private monthly expenses.');

    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    expenses_monthly_assert(is_string($appJs) && str_contains($appJs, 'data-monthly-expense-form') && str_contains($appJs, 'data-monthly-expense-action'), 'Monthly expenses JavaScript was not registered.');
    expenses_monthly_assert(is_string($appCss) && str_contains($appCss, 'color-scheme: dark') && str_contains($appCss, '.expense-kpi-grid') && str_contains($appCss, '@media'), 'Monthly expenses dark/responsive CSS hooks are missing.');

    echo "Expenses monthly: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses monthly: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ([$cookieFile, $otherCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
