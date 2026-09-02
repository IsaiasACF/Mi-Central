<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Session;
use App\Services\AuthService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

Session::start($config['app']['session']);

$pdo = Connection::get();
$auth = new AuthService($pdo);
$username = 'test_auth_' . bin2hex(random_bytes(4));
$missingUsername = 'missing_' . $username;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$wrongPassword = 'wrong-secret-' . bin2hex(random_bytes(8));
$ipAddress = '127.0.0.1';
$exitCode = 1;

try {
    $auth->createUser($username, $password);

    if ($auth->attemptLogin($missingUsername, $password, $ipAddress)) {
        throw new RuntimeException('Nonexistent user was authenticated.');
    }

    if ($auth->attemptLogin($username, $wrongPassword, $ipAddress)) {
        throw new RuntimeException('Wrong password was authenticated.');
    }

    $sessionIdBeforeLogin = session_id();

    if (!$auth->attemptLogin($username, $password, $ipAddress)) {
        throw new RuntimeException('Valid user was not authenticated.');
    }

    if (!Session::isAuthenticated()) {
        throw new RuntimeException('Authenticated session was not stored.');
    }

    if ($sessionIdBeforeLogin === session_id()) {
        throw new RuntimeException('Session ID was not regenerated after login.');
    }

    $statement = $pdo->prepare('SELECT last_login_at FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);

    if ($statement->fetchColumn() === null) {
        throw new RuntimeException('Last login timestamp was not updated.');
    }

    AuthService::logout();

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE username = :username')
        ->execute(['username' => $username]);

    if ($auth->attemptLogin($username, $password, $ipAddress)) {
        throw new RuntimeException('Inactive user was authenticated.');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $auth->recordFailedAttempt($username, '127.0.0.2');
    }

    if (!$auth->isTemporarilyBlocked($username, '127.0.0.2')) {
        throw new RuntimeException('Repeated failed attempts did not trigger blocking.');
    }

    echo "Auth service: OK\n";
    $exitCode = 0;
} catch (\Throwable $exception) {
    fwrite(STDERR, "Auth service: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    AuthService::logout();

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $username]);

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $missingUsername]);

    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
