<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only be run from CLI.\n");
    exit(1);
}

$username = '';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--username=')) {
        $username = substr($argument, strlen('--username='));
    }
}

$username = AuthService::normalizeUsername($username);

if ($username === '' || !AuthService::isValidUsername($username)) {
    fwrite(STDERR, "Usage: php bin/user-admin.php --username=<username>\n");
    exit(2);
}

$pdo = Connection::get();
$statement = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$statement->execute(['username' => $username]);
$userId = $statement->fetchColumn();

if ($userId === false) {
    fwrite(STDERR, "User not found: {$username}\n");
    exit(1);
}

$statement = $pdo->prepare(
    "UPDATE users
     SET is_admin = 1,
         approval_status = 'approved',
         is_active = 1,
         approved_at = COALESCE(approved_at, NOW()),
         rejected_at = NULL
     WHERE id = :id"
);
$statement->execute(['id' => (int) $userId]);

echo "User {$username} is now an administrator.\n";
