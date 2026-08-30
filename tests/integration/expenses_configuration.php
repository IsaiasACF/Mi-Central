<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
use Modules\Expenses\ExpenseValidationException;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_config_' . $suffix;
$otherUsername = 'test_expenses_config_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-config-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-config-other-');
$userIds = [];
$exitCode = 1;

function expenses_config_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_config_expect_validation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ExpenseValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function expenses_config_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $payload = null, array $headers = []): array
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

function expenses_config_form(string $url, array $postFields, string $cookieFile): array
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

function expenses_config_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function expenses_config_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = expenses_config_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expenses_config_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expenses_config_form('http://127.0.0.1/login.php', [
        'csrf_token' => expenses_config_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expenses_config_assert($login['status'] === 302, 'Login failed.');
    $page = expenses_config_http('http://127.0.0.1/index.php?section=expenses', 'GET', $cookieFile);
    expenses_config_assert($page['status'] === 200, 'Expenses page did not load after login.');

    return expenses_config_csrf($page['body']);
}

function expenses_config_create_user(PDO $pdo, string $username, string $password): int
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

function expenses_config_post(string $action, array $payload, string $cookieFile, string $csrf): array
{
    return expenses_config_http(
        'http://127.0.0.1/api/expenses/configuration.php',
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

    $userId = expenses_config_create_user($pdo, $username, $password);
    $otherUserId = expenses_config_create_user($pdo, $otherUsername, $password);
    $userIds = [$userId, $otherUserId];
    $csrf = expenses_config_login($username, $password, $cookieFile);
    $otherCsrf = expenses_config_login($otherUsername, $password, $otherCookieFile);

    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    $appCss = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    expenses_config_assert(is_string($appJs) && str_contains($appJs, 'page.dataset.expensesInitialized'), 'Expenses JavaScript was not registered.');
    expenses_config_assert(is_string($appJs) && str_contains($appJs, 'configureInactivePaymentOptions') && str_contains($appJs, 'configureInactiveCategoryOptions'), 'Expenses JavaScript does not handle inactive selectors.');
    expenses_config_assert(is_string($appJs) && str_contains($appJs, 'closeModalFromBackdrop') && str_contains($appJs, 'pointerdown'), 'Expenses modals do not guard against drag-select backdrop closes.');
    expenses_config_assert(is_string($appCss) && str_contains($appCss, 'color-scheme: dark') && str_contains($appCss, '.expense-modal') && str_contains($appCss, '@media'), 'Expenses UI did not keep dark/responsive CSS hooks.');

    $unauthenticated = expenses_config_http('http://127.0.0.1/index.php?section=expenses');
    expenses_config_assert($unauthenticated['status'] === 302 && str_contains($unauthenticated['headers'], 'Location: /login.php'), 'Expenses page did not require authentication.');

    $emptyPage = expenses_config_http('http://127.0.0.1/index.php?section=expenses&tab=categories', 'GET', $cookieFile);
    expenses_config_assert(str_contains($emptyPage['body'], 'Gastos') && str_contains($emptyPage['body'], 'Configuraci') && str_contains($emptyPage['body'], 'Todav'), 'Expenses configuration page or empty state did not render.');
    expenses_config_assert(str_contains($emptyPage['body'], 'Categor') && str_contains($emptyPage['body'], 'Medios de pago') && str_contains($emptyPage['body'], 'Servicios'), 'Expenses internal tabs were not rendered.');
    expenses_config_assert(str_contains($emptyPage['body'], 'href="/index.php?section=expenses"'), 'Expenses sidebar link was not rendered.');
    expenses_config_assert(!str_contains($emptyPage['body'], 'Nuevo gasto mensual') && !str_contains($emptyPage['body'], 'Registrar pago'), 'Monthly expense actions appeared too early.');

    $missingCsrf = expenses_config_http('http://127.0.0.1/api/expenses/configuration.php', 'POST', $cookieFile, [
        'action' => 'create-category',
        'name' => 'Sin CSRF',
    ]);
    expenses_config_assert($missingCsrf['status'] === 403, 'Expenses API did not require CSRF.');

    $categoryCreate = expenses_config_post('create-category', [
        'name' => 'Servicios basicos',
        'color' => '#3366cc',
    ], $cookieFile, $csrf);
    expenses_config_assert($categoryCreate['status'] === 201 && ($categoryCreate['json']['data']['item']['color'] ?? '') === '#3366CC', 'Category create/color failed.');
    $categoryId = (int) $categoryCreate['json']['data']['item']['id'];

    $duplicateCategory = expenses_config_post('create-category', [
        'name' => 'servicios basicos',
        'color' => '#111111',
    ], $cookieFile, $csrf);
    expenses_config_assert($duplicateCategory['status'] === 422, 'Duplicate category was accepted.');

    $otherCategory = expenses_config_post('create-category', [
        'name' => 'Servicios basicos',
        'color' => '#224466',
    ], $otherCookieFile, $otherCsrf);
    expenses_config_assert($otherCategory['status'] === 201, 'Same category name was not allowed for another user.');
    $otherCategoryId = (int) $otherCategory['json']['data']['item']['id'];

    $categoryUpdate = expenses_config_post('update-category', [
        'id' => $categoryId,
        'name' => 'Servicios del hogar',
        'color' => '#AA5500',
    ], $cookieFile, $csrf);
    expenses_config_assert($categoryUpdate['status'] === 200 && ($categoryUpdate['json']['data']['item']['name'] ?? '') === 'Servicios del hogar', 'Category update failed.');

    $foreignCategoryUpdate = expenses_config_post('update-category', [
        'id' => $otherCategoryId,
        'name' => 'Categoria robada',
        'color' => '#111111',
    ], $cookieFile, $csrf);
    expenses_config_assert($foreignCategoryUpdate['status'] === 404, 'User could update another user category.');

    $categoryDeactivate = expenses_config_post('deactivate-category', ['id' => $categoryId], $cookieFile, $csrf);
    $categoryReactivate = expenses_config_post('reactivate-category', ['id' => $categoryId], $cookieFile, $csrf);
    expenses_config_assert($categoryDeactivate['status'] === 200 && $categoryReactivate['status'] === 200, 'Category deactivate/reactivate failed.');

    $visa = expenses_config_post('create-payment-method', [
        'name' => 'Visa Santander',
        'type' => 'card',
        'institution_name' => 'Santander',
    ], $cookieFile, $csrf);
    $pat = expenses_config_post('create-payment-method', [
        'name' => 'PAT Santander',
        'type' => 'automatic_payment',
        'institution_name' => 'Santander',
    ], $cookieFile, $csrf);
    $webpay = expenses_config_post('create-payment-method', [
        'name' => 'WebPay',
        'type' => 'webpay',
    ], $cookieFile, $csrf);
    $cash = expenses_config_post('create-payment-method', [
        'name' => 'Efectivo',
        'type' => 'cash',
    ], $cookieFile, $csrf);
    $otherWallet = expenses_config_post('create-payment-method', [
        'name' => 'Mercado Pago',
        'type' => 'wallet',
    ], $otherCookieFile, $otherCsrf);
    expenses_config_assert($visa['status'] === 201 && $pat['status'] === 201 && $webpay['status'] === 201 && $cash['status'] === 201, 'Payment method create failed.');
    $visaId = (int) $visa['json']['data']['item']['id'];
    $patId = (int) $pat['json']['data']['item']['id'];
    $webpayId = (int) $webpay['json']['data']['item']['id'];
    $cashId = (int) $cash['json']['data']['item']['id'];
    $otherWalletId = (int) $otherWallet['json']['data']['item']['id'];

    $invalidPaymentType = expenses_config_post('create-payment-method', [
        'name' => 'Metodo invalido',
        'type' => 'crypto',
    ], $cookieFile, $csrf);
    $sensitivePayment = expenses_config_post('create-payment-method', [
        'name' => 'Tarjeta secreta',
        'type' => 'card',
        'notes' => 'CVV 123',
    ], $cookieFile, $csrf);
    expenses_config_assert($invalidPaymentType['status'] === 422 && $sensitivePayment['status'] === 422, 'Invalid payment method validation failed.');

    $paymentUpdate = expenses_config_post('update-payment-method', [
        'id' => $visaId,
        'name' => 'Visa Santander Principal',
        'type' => 'card',
        'institution_name' => 'Banco Santander',
        'notes' => 'Uso conceptual',
    ], $cookieFile, $csrf);
    expenses_config_assert($paymentUpdate['status'] === 200 && ($paymentUpdate['json']['data']['item']['institution_name'] ?? '') === 'Banco Santander', 'Payment method update failed.');

    $foreignPaymentUpdate = expenses_config_post('update-payment-method', [
        'id' => $otherWalletId,
        'name' => 'Wallet robada',
        'type' => 'wallet',
    ], $cookieFile, $csrf);
    expenses_config_assert($foreignPaymentUpdate['status'] === 404, 'User could update another user payment method.');

    expenses_config_assert(expenses_config_post('deactivate-payment-method', ['id' => $cashId], $cookieFile, $csrf)['status'] === 200, 'Payment method deactivate failed.');
    expenses_config_assert(expenses_config_post('reactivate-payment-method', ['id' => $cashId], $cookieFile, $csrf)['status'] === 200, 'Payment method reactivate failed.');
    expenses_config_assert(expenses_config_post('deactivate-payment-method', ['id' => $cashId], $cookieFile, $csrf)['status'] === 200, 'Payment method second deactivate failed.');

    $serviceNoMethods = expenses_config_post('create-service', [
        'name' => 'Otro gasto habitual',
        'category_id' => '',
        'default_amount_clp' => '',
        'notes' => 'Sin metodo todavia',
        'payment_method_ids' => [],
        'default_payment_method_id' => '',
    ], $cookieFile, $csrf);
    expenses_config_assert($serviceNoMethods['status'] === 201 && ($serviceNoMethods['json']['data']['item']['payment_methods'] ?? []) === [], 'Service without payment methods failed.');

    $serviceCreate = expenses_config_post('create-service', [
        'name' => 'Aguas Andinas',
        'category_id' => (string) $categoryId,
        'default_amount_clp' => '8.250',
        'notes' => 'El monto cambia algunos meses.',
        'payment_method_ids' => [(string) $patId, (string) $webpayId],
        'default_payment_method_id' => (string) $patId,
    ], $cookieFile, $csrf);
    expenses_config_assert($serviceCreate['status'] === 201 && (int) ($serviceCreate['json']['data']['item']['default_amount_clp'] ?? 0) === 8250, 'Service create/default amount failed.');
    $serviceId = (int) $serviceCreate['json']['data']['item']['id'];
    $serviceMethods = $serviceCreate['json']['data']['item']['payment_methods'] ?? [];
    expenses_config_assert(count($serviceMethods) === 2 && (int) $serviceMethods[0]['is_default'] === 1, 'Service payment methods/default failed.');

    $duplicateService = expenses_config_post('create-service', [
        'name' => 'aguas andinas',
        'category_id' => '',
        'default_amount_clp' => '',
        'payment_method_ids' => [],
    ], $cookieFile, $csrf);
    expenses_config_assert($duplicateService['status'] === 422, 'Duplicate service was accepted.');

    $foreignServiceCreate = expenses_config_post('create-service', [
        'name' => 'Servicio con metodo ajeno',
        'payment_method_ids' => [(string) $otherWalletId],
        'default_payment_method_id' => (string) $otherWalletId,
    ], $cookieFile, $csrf);
    expenses_config_assert($foreignServiceCreate['status'] === 422, 'Service accepted another user payment method.');

    $inactiveMethodCreate = expenses_config_post('create-service', [
        'name' => 'Servicio con efectivo inactivo',
        'payment_method_ids' => [(string) $cashId],
        'default_payment_method_id' => '',
    ], $cookieFile, $csrf);
    expenses_config_assert($inactiveMethodCreate['status'] === 422, 'Inactive payment method was offered for new associations.');

    $serviceUpdate = expenses_config_post('update-service', [
        'id' => $serviceId,
        'name' => 'Aguas Andinas Hogar',
        'category_id' => (string) $categoryId,
        'default_amount_clp' => '9.100',
        'notes' => 'Boleta variable',
        'payment_method_ids' => [(string) $patId, (string) $webpayId, (string) $visaId],
        'default_payment_method_id' => (string) $webpayId,
    ], $cookieFile, $csrf);
    expenses_config_assert($serviceUpdate['status'] === 200 && count($serviceUpdate['json']['data']['item']['payment_methods'] ?? []) === 3, 'Service update/sync add failed.');

    $defaultCount = $pdo->prepare('SELECT COUNT(*) FROM expense_service_payment_methods WHERE service_id = :service_id AND is_default = 1');
    $defaultCount->execute(['service_id' => $serviceId]);
    expenses_config_assert((int) $defaultCount->fetchColumn() === 1, 'More than one default payment method was stored.');

    $serviceRemoveDefault = expenses_config_post('update-service', [
        'id' => $serviceId,
        'name' => 'Aguas Andinas Hogar',
        'category_id' => (string) $categoryId,
        'default_amount_clp' => '9.100',
        'notes' => 'Boleta variable',
        'payment_method_ids' => [(string) $webpayId],
        'default_payment_method_id' => '',
    ], $cookieFile, $csrf);
    expenses_config_assert($serviceRemoveDefault['status'] === 200 && count($serviceRemoveDefault['json']['data']['item']['payment_methods'] ?? []) === 1, 'Service sync remove failed.');
    $defaultCount->execute(['service_id' => $serviceId]);
    expenses_config_assert((int) $defaultCount->fetchColumn() === 0, 'Default remained after relation was removed.');

    $invalidDefault = expenses_config_post('update-service', [
        'id' => $serviceId,
        'name' => 'Aguas Andinas Hogar',
        'payment_method_ids' => [(string) $webpayId],
        'default_payment_method_id' => (string) $patId,
    ], $cookieFile, $csrf);
    expenses_config_assert($invalidDefault['status'] === 422, 'Default outside selected payment methods was accepted.');

    $foreignServiceUpdate = expenses_config_post('update-service', [
        'id' => $serviceId,
        'name' => 'No autorizado',
    ], $otherCookieFile, $otherCsrf);
    expenses_config_assert($foreignServiceUpdate['status'] === 404, 'User could update another user service.');

    expenses_config_assert(expenses_config_post('deactivate-service', ['id' => $serviceId], $cookieFile, $csrf)['status'] === 200, 'Service deactivate failed.');
    $stateAfterInactive = expenses_config_http('http://127.0.0.1/api/expenses/configuration.php', 'GET', $cookieFile);
    expenses_config_assert($stateAfterInactive['status'] === 200 && count($stateAfterInactive['json']['data']['active_services'] ?? []) === 1, 'Inactive service appeared in active service list.');
    expenses_config_assert(expenses_config_post('reactivate-service', ['id' => $serviceId], $cookieFile, $csrf)['status'] === 200, 'Service reactivate failed.');

    $serviceRepository = new ExpenseServiceRepository($pdo);
    $serviceDefinitionService = new ExpenseServiceDefinitionService($serviceRepository);
    expenses_config_expect_validation(
        fn () => $serviceDefinitionService->addPaymentMethod($userId, $serviceId, $webpayId),
        'Duplicate service/payment relation was accepted.'
    );
    expenses_config_expect_validation(
        fn () => $serviceDefinitionService->addPaymentMethod($userId, $serviceId, $otherWalletId),
        'Domain service accepted another user payment method.'
    );

    $configuredPage = expenses_config_http('http://127.0.0.1/index.php?section=expenses&tab=services', 'GET', $cookieFile);
    expenses_config_assert(
        str_contains($configuredPage['body'], 'Aguas Andinas Hogar')
        && str_contains($configuredPage['body'], '$9.100')
        && str_contains($configuredPage['body'], 'Buscar servicio')
        && str_contains($configuredPage['body'], 'data-expense-service-filter')
        && str_contains($configuredPage['body'], 'data-payment-method-ids')
        && !str_contains($configuredPage['body'], 'Gasto mensual'),
        'Configured services UI did not render expected controls.'
    );
    expenses_config_assert(!str_contains($configuredPage['body'], 'Tarjeta secreta'), 'Sensitive rejected payment method appeared in UI.');

    $paymentPage = expenses_config_http('http://127.0.0.1/index.php?section=expenses&tab=payment-methods', 'GET', $cookieFile);
    expenses_config_assert(str_contains($paymentPage['body'], 'Visa Santander Principal') && str_contains($paymentPage['body'], 'Tarjeta') && str_contains($paymentPage['body'], 'Banco Santander') && str_contains($paymentPage['body'], 'No ingreses'), 'Payment method UI did not render friendly labels/help.');
    expenses_config_assert(!str_contains($paymentPage['body'], '>card<') && !str_contains($paymentPage['body'], '>automatic_payment<'), 'Internal payment method codes were visible.');

    $otherState = expenses_config_http('http://127.0.0.1/api/expenses/configuration.php', 'GET', $otherCookieFile);
    $otherJson = json_encode($otherState['json'], JSON_THROW_ON_ERROR);
    expenses_config_assert(!str_contains($otherJson, 'Aguas Andinas Hogar') && !str_contains($otherJson, 'Visa Santander Principal'), 'Another user could list private expenses configuration.');

    echo "Expenses configuration: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses configuration: FAILED\n");
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
