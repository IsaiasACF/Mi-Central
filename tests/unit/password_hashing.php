<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!defined('PASSWORD_ARGON2ID')) {
    fwrite(STDERR, "Password hashing: FAILED\n");
    fwrite(STDERR, "PASSWORD_ARGON2ID is not available.\n");
    exit(1);
}

$password = 'test-secret-' . bin2hex(random_bytes(8));
$wrongPassword = 'wrong-secret-' . bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_ARGON2ID);

if (!is_string($hash)) {
    fwrite(STDERR, "Password hashing: FAILED\n");
    exit(1);
}

if (!password_verify($password, $hash)) {
    fwrite(STDERR, "Password hashing: FAILED\n");
    exit(1);
}

if (password_verify($wrongPassword, $hash)) {
    fwrite(STDERR, "Password hashing: FAILED\n");
    exit(1);
}

echo "Password hashing: OK\n";
exit(0);
