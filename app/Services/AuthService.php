<?php
declare(strict_types=1);

namespace App\Services;

use App\Http\Session;
use PDO;
use RuntimeException;

final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $username) === 1;
    }

    public function usernameExists(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => self::normalizeUsername($username)]);

        return $statement->fetchColumn() !== false;
    }

    public function createUser(string $username, string $password): int
    {
        $username = self::normalizeUsername($username);

        if (!self::isValidUsername($username)) {
            throw new RuntimeException('Invalid username.');
        }

        if ($password === '' || strlen($password) < 12) {
            throw new RuntimeException('Invalid password.');
        }

        if (!defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException('PASSWORD_ARGON2ID is not available.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if (!is_string($hash)) {
            throw new RuntimeException('Could not hash password.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $hash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function attemptLogin(string $username, string $password, string $ipAddress): bool
    {
        $username = self::normalizeUsername($username);
        $attemptUsername = $this->attemptUsername($username);

        if ($this->isTemporarilyBlocked($attemptUsername, $ipAddress)) {
            return false;
        }

        $user = $this->findUser($username);

        if (
            $user === null
            || (int) $user['is_active'] !== 1
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            $this->recordFailedAttempt($attemptUsername, $ipAddress);
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->updatePasswordHash((int) $user['id'], $password);
        }

        $this->clearFailedAttempts($attemptUsername, $ipAddress);
        $this->updateLastLogin((int) $user['id']);

        Session::regenerate();
        Session::authenticate((int) $user['id'], (string) $user['username']);

        return true;
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public function isTemporarilyBlocked(string $username, string $ipAddress): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOCK_SECONDS);

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND ip_address = :ip_address AND attempted_at >= :since'
        );
        $statement->execute([
            'username' => $this->attemptUsername($username),
            'ip_address' => substr($ipAddress, 0, 45),
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn() >= self::MAX_FAILED_ATTEMPTS;
    }

    public function recordFailedAttempt(string $username, string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip_address)'
        );
        $statement->execute([
            'username' => $this->attemptUsername($username),
            'ip_address' => substr($ipAddress, 0, 45),
        ]);
    }

    private function findUser(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    private function clearFailedAttempts(string $username, string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip_address'
        );
        $statement->execute([
            'username' => $this->attemptUsername($username),
            'ip_address' => substr($ipAddress, 0, 45),
        ]);
    }

    private function updateLastLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    private function updatePasswordHash(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if (!is_string($hash)) {
            return;
        }

        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'password_hash' => $hash,
            'id' => $userId,
        ]);
    }

    private function attemptUsername(string $username): string
    {
        $username = self::normalizeUsername($username);

        if ($username === '') {
            return '_empty';
        }

        return substr($username, 0, 64);
    }
}
