<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$username = 'test_http_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-cookie-');
$exitCode = 1;

function http_request(string $url, string $method = 'GET', array $postFields = [], ?string $cookieFile = null): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function csrf_from_html(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

try {
    $auth->createUser($username, $password);

    $index = http_request('http://127.0.0.1/index.php', 'GET', [], $cookieFile);

    if ((int) $index['status'] !== 302 || !str_contains($index['headers'], 'Location: /login.php')) {
        throw new RuntimeException('Index did not redirect unauthenticated request to login.');
    }

    $internalSection = http_request('http://127.0.0.1/index.php?section=video', 'GET', [], $cookieFile);

    if ((int) $internalSection['status'] !== 302 || !str_contains($internalSection['headers'], 'Location: /login.php')) {
        throw new RuntimeException('Internal section did not redirect unauthenticated request to login.');
    }

    $logoutGet = http_request('http://127.0.0.1/logout.php', 'GET', [], $cookieFile);

    if ((int) $logoutGet['status'] !== 405) {
        throw new RuntimeException('Logout accepted GET.');
    }

    $loginPage = http_request('http://127.0.0.1/login.php', 'GET', [], $cookieFile);

    if ((int) $loginPage['status'] !== 200) {
        throw new RuntimeException('Login page did not load.');
    }

    foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Content-Security-Policy:'] as $header) {
        if (!str_contains($loginPage['headers'], $header)) {
            throw new RuntimeException("Missing security header: {$header}");
        }
    }

    $missingCsrfLogin = http_request('http://127.0.0.1/login.php', 'POST', [
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    if (
        (int) $missingCsrfLogin['status'] !== 200
        || !str_contains($missingCsrfLogin['body'], 'Credenciales invalidas o acceso temporalmente bloqueado.')
    ) {
        throw new RuntimeException('Login without CSRF was not rejected generically.');
    }

    $loginToken = csrf_from_html($loginPage['body']);
    $login = http_request('http://127.0.0.1/login.php', 'POST', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    if ((int) $login['status'] !== 302 || !str_contains($login['headers'], 'Location: /index.php')) {
        throw new RuntimeException('Valid login did not redirect to index.');
    }

    $authenticatedIndex = http_request('http://127.0.0.1/index.php', 'GET', [], $cookieFile);

    if (
        (int) $authenticatedIndex['status'] !== 200
        || !str_contains($authenticatedIndex['body'], 'Sesion iniciada como: ' . $username)
        || !str_contains($authenticatedIndex['body'], 'Resumen de hoy')
        || !str_contains($authenticatedIndex['body'], 'Bandeja rapida')
        || !str_contains($authenticatedIndex['body'], 'Aun no hay actividad de video.')
        || !str_contains($authenticatedIndex['body'], 'No hay proximos elementos con fecha.')
    ) {
        throw new RuntimeException('Authenticated index was not accessible.');
    }

    $videoSection = http_request('http://127.0.0.1/index.php?section=video', 'GET', [], $cookieFile);

    if (
        (int) $videoSection['status'] !== 200
        || !str_contains($videoSection['body'], 'Seleccionar video')
        || !str_contains($videoSection['body'], 'Mis videos')
        || !str_contains($videoSection['body'], 'aria-current="page"')
    ) {
        throw new RuntimeException('Known authenticated section was not rendered correctly.');
    }

    $invalidSection = http_request('http://127.0.0.1/index.php?section=../config/database.php', 'GET', [], $cookieFile);

    if (
        (int) $invalidSection['status'] !== 404
        || !str_contains($invalidSection['body'], 'Seccion no encontrada')
    ) {
        throw new RuntimeException('Invalid section was not handled safely.');
    }

    $logoutToken = csrf_from_html($authenticatedIndex['body']);
    $invalidLogout = http_request('http://127.0.0.1/logout.php', 'POST', [
        'csrf_token' => 'invalid-token',
    ], $cookieFile);

    if ((int) $invalidLogout['status'] !== 400) {
        throw new RuntimeException('Logout with invalid CSRF was not rejected.');
    }

    $logout = http_request('http://127.0.0.1/logout.php', 'POST', [
        'csrf_token' => $logoutToken,
    ], $cookieFile);

    if ((int) $logout['status'] !== 302 || !str_contains($logout['headers'], 'Location: /login.php')) {
        throw new RuntimeException('Logout POST did not redirect to login.');
    }

    echo "HTTP auth flow: OK\n";
    $exitCode = 0;
} catch (\Throwable $exception) {
    fwrite(STDERR, "HTTP auth flow: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $username]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
