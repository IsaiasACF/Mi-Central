<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$suffix = bin2hex(random_bytes(4));
$username = 'test_discount_profile_benefits_' . $suffix;
$otherUsername = 'test_discount_profile_benefits_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-profile-benefits-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-profile-benefits-other-');
$userIds = [];
$programIds = [];
$exitCode = 1;

function discount_benefits_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discount_benefits_http(string $url, string $method = 'GET', ?string $cookieFile = null, array $postFields = [], array $headers = []): array
{
    $handle = curl_init($url);
    $requestHeaders = [];

    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method !== 'GET') {
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
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

function discount_benefits_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function discount_benefits_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = discount_benefits_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    discount_benefits_assert((int) $loginPage['status'] === 200, 'Login page did not load.');
    $login = discount_benefits_http('http://127.0.0.1/login.php', 'POST', $cookieFile, [
        'csrf_token' => discount_benefits_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ]);
    discount_benefits_assert((int) $login['status'] === 302, 'Login failed.');
    $page = discount_benefits_http('http://127.0.0.1/index.php?section=settings&tab=benefits', 'GET', $cookieFile);
    discount_benefits_assert((int) $page['status'] === 200, 'Settings benefits page did not load after login.');

    return discount_benefits_csrf($page['body']);
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
    $visa = $programService->create([
        'provider_name' => 'Banco de Chile ' . $suffix,
        'name' => 'Visa Banco de Chile ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    $debit = $programService->create([
        'provider_name' => 'Santander ' . $suffix,
        'name' => 'Debito Santander ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    $programIds = [(int) $visa['id'], (int) $debit['id']];

    $csrf = discount_benefits_login($username, $password, $cookieFile);
    discount_benefits_login($otherUsername, $password, $otherCookieFile);

    $page = discount_benefits_http('http://127.0.0.1/index.php?section=settings&tab=benefits', 'GET', $cookieFile);
    discount_benefits_assert(
        str_contains($page['body'], 'Mis tarjetas y beneficios')
        && str_contains($page['body'], 'Visa Banco de Chile ' . $suffix)
        && str_contains($page['body'], 'Debito Santander ' . $suffix)
        && str_contains($page['body'], 'Buscar tarjeta, banco o beneficio')
        && str_contains($page['body'], 'data-benefit-toggle')
        && !str_contains($page['body'], '>Marcar<')
        && !str_contains($page['body'], '>Desmarcar<')
        && !str_contains($page['body'], 'CVV')
        && !str_contains($page['body'], 'numero de tarjeta'),
        'Profile benefit catalog did not render safely.'
    );

    $filtered = discount_benefits_http('http://127.0.0.1/index.php?section=settings&tab=benefits&benefit_provider=' . rawurlencode('Banco de Chile ' . $suffix), 'GET', $cookieFile);
    discount_benefits_assert(str_contains($filtered['body'], 'Visa Banco de Chile ' . $suffix) && !str_contains($filtered['body'], 'Debito Santander ' . $suffix), 'Provider filter did not isolate benefit programs.');

    $missingCsrf = discount_benefits_http('http://127.0.0.1/api/discounts/user-benefits.php', 'POST', $cookieFile, [
        'action' => 'toggle-program',
        'benefit_program_id' => (string) $visa['id'],
        'enabled' => '1',
    ]);
    discount_benefits_assert((int) $missingCsrf['status'] === 403, 'Benefit checkbox API did not require CSRF.');

    $toggle = discount_benefits_http('http://127.0.0.1/api/discounts/user-benefits.php', 'POST', $cookieFile, [
        'csrf_token' => $csrf,
        'action' => 'toggle-program',
        'benefit_program_id' => (string) $visa['id'],
        'enabled' => '1',
    ]);
    discount_benefits_assert((int) $toggle['status'] === 200 && str_contains($toggle['body'], '"enabled":true'), 'Benefit checkbox API did not add selection.');

    $duplicateToggle = discount_benefits_http('http://127.0.0.1/api/discounts/user-benefits.php', 'POST', $cookieFile, [
        'csrf_token' => $csrf,
        'action' => 'toggle-program',
        'benefit_program_id' => (string) $visa['id'],
        'enabled' => '1',
    ]);
    discount_benefits_assert((int) $duplicateToggle['status'] === 200, 'Double click add was not idempotent.');

    $ownCount = $pdo->prepare('SELECT COUNT(*) FROM user_discount_benefits WHERE user_id = :user_id AND benefit_program_id = :program_id AND active = 1');
    $ownCount->execute(['user_id' => $userId, 'program_id' => (int) $visa['id']]);
    $otherCount = $pdo->prepare('SELECT COUNT(*) FROM user_discount_benefits WHERE user_id = :user_id AND benefit_program_id = :program_id');
    $otherCount->execute(['user_id' => $otherUserId, 'program_id' => (int) $visa['id']]);
    discount_benefits_assert((int) $ownCount->fetchColumn() === 1 && (int) $otherCount->fetchColumn() === 0, 'Benefit selection was not private to the current user.');

    $remove = discount_benefits_http('http://127.0.0.1/api/discounts/user-benefits.php', 'POST', $cookieFile, [
        'csrf_token' => $csrf,
        'action' => 'toggle-program',
        'benefit_program_id' => (string) $visa['id'],
        'enabled' => '0',
    ]);
    discount_benefits_assert((int) $remove['status'] === 200 && str_contains($remove['body'], '"enabled":false'), 'Benefit checkbox API did not remove selection.');
    $ownCount->execute(['user_id' => $userId, 'program_id' => (int) $visa['id']]);
    discount_benefits_assert((int) $ownCount->fetchColumn() === 0, 'Benefit was not deactivated for the current user.');

    $legacyTab = discount_benefits_http('http://127.0.0.1/index.php?section=discounts&tab=benefits', 'GET', $cookieFile);
    $legacyRoute = discount_benefits_http('http://127.0.0.1/index.php?section=discounts-benefits', 'GET', $cookieFile);
    discount_benefits_assert((int) $legacyTab['status'] === 302 && str_contains($legacyTab['headers'], 'Location: /index.php?section=settings&tab=benefits'), 'Old benefits tab did not redirect to settings.');
    discount_benefits_assert((int) $legacyRoute['status'] === 302 && str_contains($legacyRoute['headers'], 'Location: /index.php?section=settings&tab=benefits'), 'Legacy discounts benefits route did not redirect.');

    $blockedCreate = discount_benefits_http('http://127.0.0.1/api/discounts/user-benefits.php', 'POST', $cookieFile, [
        'csrf_token' => $csrf,
        'action' => 'create-and-add',
        'provider_name' => 'Fixture ' . $suffix,
        'name' => 'Fake Card ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    discount_benefits_assert((int) $blockedCreate['status'] === 410, 'Free benefit program creation was still enabled.');

    echo "Discounts user benefits: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discounts user benefits: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ([$cookieFile, $otherCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    foreach ($userIds as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }

    foreach ($programIds as $id) {
        try {
            $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id')->execute(['id' => $id]);
        } catch (Throwable) {
        }
    }
}

exit($exitCode);
