<?php
declare(strict_types=1);

use App\Database\Connection;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expenses_import_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expenses-import-');
$userIds = [];
$exitCode = 1;

function expenses_import_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expenses_import_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $payload = null, array $headers = []): array
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

function expenses_import_form(string $url, array $postFields, string $cookieFile): array
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

function expenses_import_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function expenses_import_create_user(PDO $pdo, string $username, string $password): int
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

function expenses_import_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = expenses_import_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expenses_import_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expenses_import_form('http://127.0.0.1/login.php', [
        'csrf_token' => expenses_import_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expenses_import_assert($login['status'] === 302, 'Login failed.');
    $page = expenses_import_http('http://127.0.0.1/index.php?section=expenses&tab=import', 'GET', $cookieFile);
    expenses_import_assert($page['status'] === 200, 'Expenses import page did not load after login.');

    return expenses_import_csrf($page['body']);
}

function expenses_import_post(array $payload, string $cookieFile, string $csrf): array
{
    return expenses_import_http(
        'http://127.0.0.1/api/expenses/import.php',
        'POST',
        $cookieFile,
        $payload,
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

    $userId = expenses_import_create_user($pdo, $username, $password);
    $userIds = [$userId];
    $csrf = expenses_import_login($username, $password, $cookieFile);

    $page = expenses_import_http('http://127.0.0.1/index.php?section=expenses&tab=import', 'GET', $cookieFile);
    expenses_import_assert(
        str_contains($page['body'], 'Importar JSON')
        && str_contains($page['body'], 'data-import-api-url="/api/expenses/import.php"')
        && str_contains($page['body'], '&quot;amount_clp&quot;: null')
        && str_contains($page['body'], '&quot;amount_clp&quot;: 0'),
        'Import page or JSON template did not render.',
    );

    $unauthenticated = expenses_import_http('http://127.0.0.1/api/expenses/import.php', 'POST', null, []);
    expenses_import_assert($unauthenticated['status'] === 401, 'Import API did not require authentication.');

    $missingCsrf = expenses_import_http('http://127.0.0.1/api/expenses/import.php', 'POST', $cookieFile, [
        'category' => ['name' => 'Sin CSRF'],
    ]);
    expenses_import_assert($missingCsrf['status'] === 403, 'Import API did not require CSRF.');

    $import = expenses_import_post([
        'categories' => [
            ['name' => 'Servicios basicos', 'color' => '#3366CC'],
        ],
        'expenses' => [
            [
                'period_month' => '2026-08',
                'description' => 'Aguas Andinas agosto',
                'service' => 'Aguas Andinas',
                'category' => 'Servicios basicos',
                'payment_method' => [
                    'name' => 'Cuenta RUT',
                    'type' => 'bank_account',
                    'institution_name' => 'BancoEstado',
                ],
                'amount_clp' => null,
                'due_on' => '2026-08-15',
                'status' => 'pending',
            ],
            [
                'period_month' => '2026-08-01',
                'description' => 'Ajuste sin costo',
                'amount_clp' => 0,
                'category' => 'Servicios basicos',
                'status' => 'paid',
                'paid_on' => '2026-08-20',
            ],
        ],
    ], $cookieFile, $csrf);
    $summary = $import['json']['data']['summary'] ?? [];
    expenses_import_assert(
        $import['status'] === 201
        && (int) ($summary['categories_created'] ?? 0) === 1
        && (int) ($summary['payment_methods_created'] ?? 0) === 1
        && (int) ($summary['services_created'] ?? 0) === 1
        && (int) ($summary['expenses_created'] ?? 0) === 2,
        'Import did not create expected records.',
    );

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM expenses WHERE user_id = :user_id AND amount_clp IS NULL'
    );
    $statement->execute(['user_id' => $userId]);
    expenses_import_assert((int) $statement->fetchColumn() === 1, 'Null amount was not preserved as pending amount.');

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM expenses WHERE user_id = :user_id AND amount_clp = 0'
    );
    $statement->execute(['user_id' => $userId]);
    expenses_import_assert((int) $statement->fetchColumn() === 1, 'Zero amount was not imported as a valid amount.');

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM expense_service_payment_methods pivot
         INNER JOIN expense_services services ON services.id = pivot.service_id
         INNER JOIN expense_payment_methods methods ON methods.id = pivot.payment_method_id
         WHERE services.user_id = :user_id
           AND services.name = :service_name
           AND methods.name = :method_name'
    );
    $statement->execute([
        'user_id' => $userId,
        'service_name' => 'Aguas Andinas',
        'method_name' => 'Cuenta RUT',
    ]);
    expenses_import_assert((int) $statement->fetchColumn() === 1, 'Imported service was not linked to imported payment method.');

    $rollback = expenses_import_post([
        'category' => ['name' => 'Rollback categoria'],
        'expense' => [
            'period_month' => '2026-08',
            'description' => 'Cuota mala',
            'category' => 'Rollback categoria',
            'amount_clp' => 1000,
            'installment_current' => 0,
            'installment_total' => 3,
        ],
    ], $cookieFile, $csrf);
    expenses_import_assert($rollback['status'] === 422, 'Invalid installment import was accepted.');

    $statement = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE user_id = :user_id AND name = :name');
    $statement->execute([
        'user_id' => $userId,
        'name' => 'Rollback categoria',
    ]);
    expenses_import_assert((int) $statement->fetchColumn() === 0, 'Invalid import did not roll back created category.');

    $exitCode = 0;
    echo "Expenses import: OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses import: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }

    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
