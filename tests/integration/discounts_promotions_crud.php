<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountPromotionAvailabilityService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$suffix = bin2hex(random_bytes(4));
$username = 'test_discount_manual_disabled_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-discount-manual-');
$userIds = [];
$exitCode = 1;

function discount_promos_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discount_promos_http(string $url, string $method = 'GET', ?string $cookieFile = null, array $postFields = [], array $headers = []): array
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

function discount_promos_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
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
    $userIds[] = $userId;

    $loginPage = discount_promos_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    discount_promos_assert((int) $loginPage['status'] === 200, 'Login page did not load.');
    $login = discount_promos_http('http://127.0.0.1/login.php', 'POST', $cookieFile, [
        'csrf_token' => discount_promos_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ]);
    discount_promos_assert((int) $login['status'] === 302, 'Login failed.');

    $root = discount_promos_http('http://127.0.0.1/index.php?section=discounts', 'GET', $cookieFile);
    $csrf = discount_promos_csrf($root['body']);
    discount_promos_assert((int) $root['status'] === 200 && !str_contains($root['body'], 'Nueva promocion') && !str_contains($root['body'], 'Agregar promocion'), 'Manual promotion UI was still visible.');

    $oldTab = discount_promos_http('http://127.0.0.1/index.php?section=discounts&tab=promotions', 'GET', $cookieFile);
    $oldRoute = discount_promos_http('http://127.0.0.1/index.php?section=discounts-sources', 'GET', $cookieFile);
    discount_promos_assert((int) $oldTab['status'] === 302 && str_contains($oldTab['headers'], 'Location: /index.php?section=discounts&tab=all'), 'Old promotions tab did not redirect to Todos.');
    discount_promos_assert((int) $oldRoute['status'] === 302 && str_contains($oldRoute['headers'], 'Location: /index.php?section=discounts&tab=all'), 'Legacy promotions route did not redirect to Todos.');

    $missingCsrf = discount_promos_http('http://127.0.0.1/api/discounts/promotions.php', 'POST', $cookieFile, [
        'action' => 'create',
    ]);
    discount_promos_assert((int) $missingCsrf['status'] === 403, 'Manual promotions API accepted missing CSRF.');

    $blockedCreate = discount_promos_http('http://127.0.0.1/api/discounts/promotions.php', 'POST', $cookieFile, [
        'csrf_token' => $csrf,
        'action' => 'create',
        'title' => 'Manual bloqueada ' . $suffix,
        'discount_type' => 'percentage',
        'discount_value' => '25',
        'channel' => 'both',
    ]);
    discount_promos_assert((int) $blockedCreate['status'] === 410, 'Manual promotion creation was still enabled.');

    $availability = new DiscountPromotionAvailabilityService('America/Santiago');
    $today = new DateTimeImmutable($availability->today());
    discount_promos_assert(
        $availability->temporalState(['is_active' => 1, 'ends_on' => $today->modify('-1 day')->format('Y-m-d')]) === 'Vencida'
        && $availability->temporalState(['is_active' => 1, 'starts_on' => $today->modify('+1 day')->format('Y-m-d')]) === 'Proximamente'
        && $availability->temporalState(['is_active' => 1, 'starts_on' => $today->format('Y-m-d'), 'ends_on' => $today->format('Y-m-d')]) === 'Vigente',
        'Temporal states were not derived correctly.'
    );

    echo "Discounts manual promotions disabled: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discounts manual promotions disabled: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }

    foreach ($userIds as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
}

exit($exitCode);
