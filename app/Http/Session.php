<?php
declare(strict_types=1);

namespace App\Http;

use App\Database\Connection;

final class Session
{
    private static ?bool $authenticated = null;
    private static ?int $authenticatedUserId = null;

    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::configure($config);

        if ((string) ($config['driver'] ?? 'file') === 'database') {
            $handler = new DatabaseSessionHandler(
                Connection::get(),
                (string) ($config['table'] ?? 'sessions'),
                (int) ($config['ttl'] ?? $config['idle_timeout'] ?? 7200),
            );

            session_set_save_handler($handler, true);
            self::cleanupExpiredSessions();
        }

        session_start();
        self::enforceIdleTimeout((int) ($config['idle_timeout'] ?? 7200), $config);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function authenticate(int $userId, string $username): void
    {
        $_SESSION['auth'] = [
            'user_id' => $userId,
            'username' => $username,
        ];
        $_SESSION['last_activity'] = time();
        self::$authenticated = true;
        self::$authenticatedUserId = $userId;
    }

    public static function isAuthenticated(): bool
    {
        if (!isset($_SESSION['auth']['user_id'], $_SESSION['auth']['username'])) {
            self::$authenticated = false;
            self::$authenticatedUserId = null;
            return false;
        }

        $userId = (int) ($_SESSION['auth']['user_id'] ?? 0);

        if ($userId <= 0) {
            unset($_SESSION['auth']);
            self::$authenticated = false;
            self::$authenticatedUserId = null;
            return false;
        }

        if (self::$authenticated !== null && self::$authenticatedUserId === $userId) {
            return self::$authenticated;
        }

        try {
            $statement = Connection::get()->prepare(
                "SELECT 1
                 FROM users
                 WHERE id = :id
                   AND is_active = 1
                 LIMIT 1"
            );
            $statement->execute(['id' => $userId]);

            if ($statement->fetchColumn() !== false) {
                self::$authenticated = true;
                self::$authenticatedUserId = $userId;
                return true;
            }
        } catch (\Throwable) {
            unset($_SESSION['auth']);
            self::$authenticated = false;
            self::$authenticatedUserId = null;
            return false;
        }

        unset($_SESSION['auth']);
        self::$authenticated = false;
        self::$authenticatedUserId = null;
        return false;
    }

    public static function user(): ?array
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        return $_SESSION['auth'];
    }

    public static function destroy(): void
    {
        self::$authenticated = false;
        self::$authenticatedUserId = null;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    public static function cleanupExpiredSessions(): int
    {
        try {
            $statement = Connection::get()->prepare('DELETE FROM sessions WHERE expires_at <= :expires_at');
            $statement->execute(['expires_at' => date('Y-m-d H:i:s.u', time())]);

            return $statement->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function configure(array $config): void
    {
        session_name((string) ($config['name'] ?? 'mi_central_session'));
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) ($config['ttl'] ?? $config['idle_timeout'] ?? 7200)));

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) ($config['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function enforceIdleTimeout(int $timeout, array $config): void
    {
        if ($timeout <= 0 || !self::isAuthenticated()) {
            return;
        }

        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);

        if ($lastActivity > 0 && time() - $lastActivity > $timeout) {
            self::destroy();
            self::configure($config);
            session_start();
            return;
        }

        $_SESSION['last_activity'] = time();
    }
}
