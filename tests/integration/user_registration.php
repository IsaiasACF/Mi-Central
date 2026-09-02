<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Video\VideoRepository;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$prefix = 'test_reg_' . bin2hex(random_bytes(4));
$adminUsername = $prefix . '_admin';
$normalUsername = $prefix . '_normal';
$existingUsername = $prefix . '_existing';
$newUsername = $prefix . '_new';
$limitedUsername = $prefix . '_limited';
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFiles = [];
$exitCode = 1;

function reg_cleanup_test_users(PDO $pdo, string $prefix): void
{
    $pdo->exec('DELETE os FROM organization_spaces os LEFT JOIN users u ON u.id = os.user_id WHERE u.id IS NULL');
    $pdo->prepare('DELETE FROM organization_spaces WHERE user_id IN (SELECT id FROM users WHERE username LIKE :prefix)')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM organization_tasks WHERE user_id IN (SELECT id FROM users WHERE username LIKE :prefix)')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM friends WHERE user_id IN (SELECT id FROM users WHERE username LIKE :prefix)')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM video_files WHERE user_id IN (SELECT id FROM users WHERE username LIKE :prefix)')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM user_discount_benefits WHERE user_id IN (SELECT id FROM users WHERE username LIKE :prefix)')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM login_attempts WHERE username LIKE :prefix OR username = :register_user')
        ->execute(['prefix' => $prefix . '%', 'register_user' => '_register']);
    $pdo->prepare('DELETE FROM users WHERE username LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
}

function reg_ensure_user(PDO $pdo, AuthService $auth, string $username, string $password): int
{
    $normalized = AuthService::normalizeUsername($username);
    $statement = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $statement->execute(['username' => $normalized]);
    $existingId = $statement->fetchColumn();

    if (is_numeric($existingId) && (int) $existingId > 0) {
        return (int) $existingId;
    }

    $newId = $auth->createUser($username, $password);

    if ($newId <= 0) {
        $refetch = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $refetch->execute(['username' => $normalized]);
        $refetched = $refetch->fetchColumn();

        if (!is_numeric($refetched) || (int) $refetched <= 0) {
            throw new RuntimeException('User could not be created or recovered: ' . $username);
        }

        return (int) $refetched;
    }

    return $newId;
}

function reg_request(string $url, string $method = 'GET', array $postFields = [], ?string $cookieFile = null): array
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

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function reg_cookie(array &$cookieFiles): string
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-register-');

    if (!is_string($cookieFile)) {
        throw new RuntimeException('Could not create cookie file.');
    }

    $cookieFiles[] = $cookieFile;

    return $cookieFile;
}

function reg_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function reg_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reg_login(array &$cookieFiles, string $username, string $password): array
{
    $cookie = reg_cookie($cookieFiles);
    $loginPage = reg_request('http://127.0.0.1/login.php', 'GET', [], $cookie);
    $login = reg_request('http://127.0.0.1/login.php', 'POST', [
        'csrf_token' => reg_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookie);

    return [$cookie, $login];
}

function reg_register(array &$cookieFiles, string $username, string $password, string $confirmation, array $extra = []): array
{
    $cookie = reg_cookie($cookieFiles);
    $page = reg_request('http://127.0.0.1/register.php', 'GET', [], $cookie);

    return reg_request('http://127.0.0.1/register.php', 'POST', array_merge([
        'csrf_token' => reg_csrf($page['body']),
        'username' => $username,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], $extra), $cookie);
}

function reg_user(PDO $pdo, string $username): array
{
    $statement = $pdo->prepare(
        'SELECT id, username, password_hash, approval_status, is_active, is_admin FROM users WHERE username = :username'
    );
    $statement->execute(['username' => AuthService::normalizeUsername($username)]);
    $user = $statement->fetch();

    if (!is_array($user)) {
        throw new RuntimeException('User not found: ' . $username);
    }

    return $user;
}

function reg_count(PDO $pdo, string $sql, array $params): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

try {
    reg_cleanup_test_users($pdo, $prefix);
    $pdo->prepare("DELETE FROM login_attempts WHERE username = '_register' AND ip_address = :ip")
        ->execute(['ip' => '127.0.0.1']);

    $adminId = reg_ensure_user($pdo, $auth, $adminUsername, $password);
    $normalId = reg_ensure_user($pdo, $auth, $normalUsername, $password);
    $existingId = reg_ensure_user($pdo, $auth, $existingUsername, $password);
    $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = :id')->execute(['id' => $adminId]);

    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);
    $spaceId = (int) (new SpaceRepository($pdo))->listForUser($adminId)[0]['id'];
    $ownerTask = (new TaskService(new TaskRepository($pdo)))->create($adminId, [
        'title' => 'Owner private task ' . $prefix,
        'space_id' => $spaceId,
    ]);

    $pdo->prepare('INSERT INTO friends (user_id, name, university, default_campus) VALUES (:user_id, :name, :university, :campus)')
        ->execute([
            'user_id' => $adminId,
            'name' => 'Owner private friend ' . $prefix,
            'university' => 'Universidad Test',
            'campus' => 'Campus Test',
        ]);

    (new VideoRepository($pdo))->create($adminId, [
        'original_name' => 'owner-private-video-' . $prefix . '.mp4',
        'stored_name' => 'owner-private-video-' . $prefix . '.mp4',
        'storage_path' => 'tests/owner-private-video-' . $prefix . '.mp4',
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 123,
        'status' => 'uploaded',
        'metadata_status' => 'pending',
    ]);

    $programId = (new DiscountBenefitProgramRepository($pdo))->create([
        'provider_name' => 'Global provider ' . $prefix,
        'normalized_provider_name' => 'global_provider_' . $prefix,
        'name' => 'Global program ' . $prefix,
        'normalized_name' => 'global_program_' . $prefix,
        'benefit_type' => 'membership',
        'product_name' => null,
        'normalized_product_name' => '',
        'active' => 1,
    ]);
    (new UserDiscountBenefitRepository($pdo))->create($adminId, [
        'benefit_program_id' => $programId,
        'nickname' => 'Owner private benefit ' . $prefix,
        'notes' => 'Owner private benefit notes ' . $prefix,
        'active' => 1,
    ]);

    $registerPage = reg_request('http://127.0.0.1/register.php');
    reg_assert($registerPage['status'] === 200 && str_contains($registerPage['body'], 'Registrarse'), 'Public registration page was not available.');
    reg_assert(!str_contains($registerPage['body'], 'Solicitud enviada') && !str_contains($registerPage['body'], 'Enviar solicitud'), 'Registration page still shows approval request copy.');

    $loginPage = reg_request('http://127.0.0.1/login.php');
    reg_assert(str_contains($loginPage['body'], 'Registrarse'), 'Login page did not link to registration.');
    reg_assert(!str_contains($loginPage['body'], 'esperando aprobacion'), 'Login page still shows pending approval copy.');

    $missingUsername = reg_register($cookieFiles, '', $password, $password);
    reg_assert(str_contains($missingUsername['body'], 'nombre de usuario'), 'Missing username was not rejected.');

    $weakPassword = reg_register($cookieFiles, $prefix . '_weak', 'short', 'short');
    reg_assert(str_contains($weakPassword['body'], 'al menos 12 caracteres'), 'Weak password was not rejected.');

    $badConfirmation = reg_register($cookieFiles, $prefix . '_mismatch', $password, $password . 'x');
    reg_assert(str_contains($badConfirmation['body'], 'no coincide'), 'Mismatched confirmation was not rejected.');

    $duplicate = reg_register($cookieFiles, strtoupper($existingUsername), $password, $password);
    reg_assert(str_contains($duplicate['body'], 'Ese nombre de usuario no esta disponible.'), 'Duplicate username was not rejected.');

    $created = reg_register($cookieFiles, $newUsername, $password, $password, [
        'approval_status' => 'pending',
        'is_active' => '0',
        'user_id' => (string) $adminId,
    ]);
    reg_assert($created['status'] === 303 && str_contains($created['headers'], 'Location: /login.php?registered=1'), 'Valid registration did not redirect to login.');

    $createdLoginPage = reg_request('http://127.0.0.1/login.php?registered=1');
    reg_assert(str_contains($createdLoginPage['body'], 'Cuenta creada correctamente. Ya puedes iniciar sesion.'), 'Registration success message was not shown on login.');

    $newUser = reg_user($pdo, $newUsername);
    reg_assert($newUser['approval_status'] === 'approved', 'New registration did not persist legacy approval_status as approved.');
    reg_assert((int) $newUser['is_active'] === 1, 'New registration was not active immediately.');
    reg_assert((int) $newUser['id'] !== $adminId, 'Registration trusted a browser-sent user_id.');
    reg_assert((string) $newUser['password_hash'] !== $password, 'Password was stored in plain text.');
    reg_assert(password_verify($password, (string) $newUser['password_hash']), 'Password hash did not verify.');

    reg_assert(reg_count($pdo, 'SELECT COUNT(*) FROM organization_tasks WHERE user_id = :user_id', ['user_id' => $newUser['id']]) === 0, 'New user started with private tasks.');
    reg_assert(reg_count($pdo, 'SELECT COUNT(*) FROM friends WHERE user_id = :user_id', ['user_id' => $newUser['id']]) === 0, 'New user started with private friends.');
    reg_assert(reg_count($pdo, 'SELECT COUNT(*) FROM video_files WHERE user_id = :user_id', ['user_id' => $newUser['id']]) === 0, 'New user started with private videos.');
    reg_assert(reg_count($pdo, 'SELECT COUNT(*) FROM user_discount_benefits WHERE user_id = :user_id', ['user_id' => $newUser['id']]) === 0, 'New user started with private discount benefits.');

    [$newCookie, $newLogin] = reg_login($cookieFiles, $newUsername, $password);
    reg_assert($newLogin['status'] === 302, 'New active user could not log in immediately.');

    $newOrg = reg_request('http://127.0.0.1/index.php?section=organization&tab=tasks&status=all', 'GET', [], $newCookie);
    reg_assert($newOrg['status'] === 200 && !str_contains($newOrg['body'], 'Owner private task ' . $prefix), 'New user could see another user task.');

    $foreignTask = reg_request('http://127.0.0.1/api/organization/tasks.php?id=' . (int) $ownerTask['id'], 'GET', [], $newCookie);
    reg_assert($foreignTask['status'] === 404, 'New user could fetch another user task by ID.');

    $newFriends = reg_request('http://127.0.0.1/index.php?section=friends&tab=friends&status=all', 'GET', [], $newCookie);
    reg_assert($newFriends['status'] === 200 && !str_contains($newFriends['body'], 'Owner private friend ' . $prefix), 'New user could see another user friend.');

    $newVideos = reg_request('http://127.0.0.1/index.php?section=video', 'GET', [], $newCookie);
    reg_assert($newVideos['status'] === 200 && !str_contains($newVideos['body'], 'owner-private-video-' . $prefix), 'New user could see another user video.');

    $newBenefits = reg_request('http://127.0.0.1/index.php?section=settings&tab=benefits', 'GET', [], $newCookie);
    reg_assert($newBenefits['status'] === 200, 'New user could not open profile discount benefits.');
    reg_assert(str_contains($newBenefits['body'], 'Global program ' . $prefix), 'Global discount catalog was not shared.');
    reg_assert(!str_contains($newBenefits['body'], 'Owner private benefit ' . $prefix), 'New user could see another user private discount benefit.');

    [$normalCookie] = reg_login($cookieFiles, $normalUsername, $password);
    $normalSettings = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'GET', [], $normalCookie);
    reg_assert($normalSettings['status'] === 200 && str_contains($normalSettings['body'], 'Mis tarjetas y beneficios') && !str_contains($normalSettings['body'], 'Desactivar'), 'Normal user could administer accounts.');

    [$adminCookie, $adminLogin] = reg_login($cookieFiles, $adminUsername, $password);
    reg_assert($adminLogin['status'] === 302, 'Admin could not log in.');
    $settings = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'GET', [], $adminCookie);
    reg_assert($settings['status'] === 200 && str_contains($settings['body'], $newUsername), 'Admin could not see users list.');
    reg_assert(!str_contains($settings['body'], 'Solicitudes pendientes') && !str_contains($settings['body'], 'Aprobar') && !str_contains($settings['body'], 'Rechazar'), 'Approval flow still appears in settings.');
    reg_assert(!str_contains($settings['body'], 'password_hash') && !str_contains($settings['body'], $password), 'Sensitive password data appeared in UI.');

    $missingCsrfDeactivate = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'POST', [
        'user_id' => (string) $newUser['id'],
        'action' => 'deactivate',
    ], $adminCookie);
    reg_assert($missingCsrfDeactivate['status'] === 400, 'Admin action without CSRF was accepted.');
    reg_assert((int) reg_user($pdo, $newUsername)['is_active'] === 1, 'CSRF failure changed user state.');

    $deactivate = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'POST', [
        'csrf_token' => reg_csrf($settings['body']),
        'user_id' => (string) $newUser['id'],
        'action' => 'deactivate',
    ], $adminCookie);
    reg_assert($deactivate['status'] === 303 && (int) reg_user($pdo, $newUsername)['is_active'] === 0, 'Active user was not deactivated.');

    $inactiveApi = reg_request('http://127.0.0.1/api/organization/tasks.php', 'GET', [], $newCookie);
    reg_assert($inactiveApi['status'] === 401, 'Deactivated existing session could still access an internal API.');

    [, $inactiveLogin] = reg_login($cookieFiles, $newUsername, $password);
    reg_assert($inactiveLogin['status'] === 200 && str_contains($inactiveLogin['body'], 'cuenta esta desactivada'), 'Inactive user did not get inactive login message.');

    $settingsAfterDeactivate = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'GET', [], $adminCookie);
    $reactivate = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'POST', [
        'csrf_token' => reg_csrf($settingsAfterDeactivate['body']),
        'user_id' => (string) $newUser['id'],
        'action' => 'reactivate',
    ], $adminCookie);
    reg_assert($reactivate['status'] === 303 && (int) reg_user($pdo, $newUsername)['is_active'] === 1, 'Inactive user was not reactivated.');

    $selfSettings = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'GET', [], $adminCookie);
    $selfDeactivate = reg_request('http://127.0.0.1/index.php?section=settings&tab=users', 'POST', [
        'csrf_token' => reg_csrf($selfSettings['body']),
        'user_id' => (string) $adminId,
        'action' => 'deactivate',
    ], $adminCookie);
    reg_assert($selfDeactivate['status'] === 303 && (int) reg_user($pdo, $adminUsername)['is_active'] === 1, 'Admin could deactivate self.');

    [, $existingLogin] = reg_login($cookieFiles, $existingUsername, $password);
    reg_assert($existingLogin['status'] === 302, 'Existing user login regressed.');

    $pdo->prepare("DELETE FROM login_attempts WHERE username = '_register' AND ip_address = :ip")
        ->execute(['ip' => '127.0.0.1']);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $auth->recordRegistrationAttempt('127.0.0.1');
    }
    $rateLimited = reg_register($cookieFiles, $limitedUsername, $password, $password);
    reg_assert($rateLimited['status'] === 200 && str_contains($rateLimited['body'], 'No se pudo procesar la solicitud en este momento.'), 'Registration rate limit did not block repeated attempts.');
    reg_assert(reg_count($pdo, 'SELECT COUNT(*) FROM users WHERE username = :username', ['username' => $limitedUsername]) === 0, 'Rate-limited registration still created a user.');

    echo "User registration: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "User registration: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($cookieFiles as $cookieFile) {
        if (is_file($cookieFile)) {
            unlink($cookieFile);
        }
    }

    reg_cleanup_test_users($pdo, $prefix);
    $pdo->prepare("DELETE FROM login_attempts WHERE username LIKE :prefix OR username = '_register'")
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM users WHERE username LIKE :prefix')
        ->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM discount_benefit_programs WHERE normalized_provider_name = :provider')
        ->execute(['provider' => 'global_provider_' . $prefix]);
}

exit($exitCode);
