<?php
declare(strict_types=1);

namespace App\Http;

use App\Database\Connection;

final class Session
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::configure($config);
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
    }

    public static function isAuthenticated(): bool
    {
        if (!isset($_SESSION['auth']['user_id'], $_SESSION['auth']['username'])) {
            return false;
        }

        $userId = (int) ($_SESSION['auth']['user_id'] ?? 0);

        if ($userId <= 0) {
            unset($_SESSION['auth']);
            return false;
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
                return true;
            }
        } catch (\Throwable) {
            unset($_SESSION['auth']);
            return false;
        }

        unset($_SESSION['auth']);
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

    private static function configure(array $config): void
    {
        session_name((string) ($config['name'] ?? 'mi_central_session'));
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

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
