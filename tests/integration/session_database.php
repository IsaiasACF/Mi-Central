<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Session;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();

$sessionMigration = require dirname(__DIR__, 2) . '/database/migrations/202609010002_create_sessions_table.php';
$sessionMigration($pdo);
$pdo->exec('DELETE FROM sessions');

$sessionConfig = [
    'name' => 'mi_central_session_db',
    'driver' => 'database',
    'table' => 'sessions',
    'idle_timeout' => 30,
    'secure' => false,
];

Session::start($sessionConfig);
$_SESSION['user'] = ['id' => 42, 'name' => 'tester'];
$_SESSION['last_activity'] = time();

session_write_close();
$sessionId = session_id();

$statement = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE id = :id AND data LIKE :marker LIMIT 1');
$statement->execute([
    'id' => $sessionId,
    'marker' => '%user|a:2:%'
]);

if ((int) $statement->fetchColumn() !== 1) {
    throw new RuntimeException('Database-backed session was not persisted.');
}

$pdo->prepare('INSERT INTO sessions (id, data, expires_at) VALUES (:id, :data, :expires_at)')
    ->execute([
        'id' => 'expired-session',
        'data' => 'stale|i:1;',
        'expires_at' => date('Y-m-d H:i:s.u', time() - 60),
    ]);

$cleaned = Session::cleanupExpiredSessions();
if ($cleaned < 1) {
    throw new RuntimeException('Expired sessions were not cleaned up.');
}

Session::destroy();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

echo "Database session persistence: OK\n";
exit(0);
