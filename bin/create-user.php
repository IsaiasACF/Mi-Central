<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "User creation can only be run from CLI.\n");
    exit(1);
}

if (!defined('PASSWORD_ARGON2ID')) {
    fwrite(STDERR, "PASSWORD_ARGON2ID is not available in this PHP image.\n");
    exit(1);
}

function prompt_line(string $label): string
{
    fwrite(STDOUT, $label);

    $line = fgets(STDIN);

    return $line === false ? '' : trim($line);
}

function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);

    $sttyMode = shell_exec('stty -g 2>/dev/null');

    if (is_string($sttyMode) && $sttyMode !== '') {
        shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if (is_string($sttyMode) && $sttyMode !== '') {
        shell_exec('stty ' . escapeshellarg(trim($sttyMode)) . ' 2>/dev/null');
        fwrite(STDOUT, "\n");
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}

try {
    $pdo = Connection::get();
    $auth = new AuthService($pdo);

    $username = AuthService::normalizeUsername(prompt_line('Username: '));

    if (!AuthService::isValidUsername($username)) {
        fwrite(STDERR, "Invalid username. Use 3-64 characters: lowercase letters, numbers, dots, underscores or hyphens.\n");
        exit(1);
    }

    $password = prompt_secret('Password: ');
    $confirmation = prompt_secret('Confirm password: ');

    if ($password === '' || strlen($password) < 12) {
        fwrite(STDERR, "Password must have at least 12 characters.\n");
        exit(1);
    }

    if ($password !== $confirmation) {
        fwrite(STDERR, "Password confirmation does not match.\n");
        exit(1);
    }

    if ($auth->usernameExists($username)) {
        fwrite(STDERR, "Username already exists.\n");
        exit(1);
    }

    $auth->createUser($username, $password);

    fwrite(STDOUT, "User created: {$username}\n");
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, "User creation failed.\n");

    if (($config['app']['debug'] ?? false) === true) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    }

    exit(1);
}
