<?php
declare(strict_types=1);

namespace App\Services;

use App\Http\Session;
use PDO;
use RuntimeException;

final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const MAX_REGISTRATION_ATTEMPTS = 10;
    private const LOCK_SECONDS = 900;
    private const MIN_PASSWORD_LENGTH = 12;
    private const LOGIN_SUCCESS = 'success';
    public const LOGIN_INVALID = 'invalid';
    public const LOGIN_INACTIVE = 'inactive';

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

        if (!$this->isValidPassword($password)) {
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
            "INSERT INTO users (username, password_hash, approval_status, is_active, approved_at)
             VALUES (:username, :password_hash, 'approved', 1, NOW())"
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $hash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function attemptLogin(string $username, string $password, string $ipAddress): bool
    {
        return $this->attemptLoginWithStatus($username, $password, $ipAddress) === self::LOGIN_SUCCESS;
    }

    public function attemptLoginWithStatus(string $username, string $password, string $ipAddress): string
    {
        $username = self::normalizeUsername($username);
        $attemptUsername = $this->attemptUsername($username);

        if ($this->isTemporarilyBlocked($attemptUsername, $ipAddress)) {
            return self::LOGIN_INVALID;
        }

        $user = $this->findUser($username);

        if (
            $user === null
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            $this->recordFailedAttempt($attemptUsername, $ipAddress);
            return self::LOGIN_INVALID;
        }

        if ((int) $user['is_active'] !== 1) {
            return self::LOGIN_INACTIVE;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->updatePasswordHash((int) $user['id'], $password);
        }

        $this->clearFailedAttempts($attemptUsername, $ipAddress);
        $this->updateLastLogin((int) $user['id']);

        Session::regenerate();
        Session::authenticate((int) $user['id'], (string) $user['username']);

        return self::LOGIN_SUCCESS;
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

    public function isRegistrationTemporarilyBlocked(string $ipAddress): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOCK_SECONDS);

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND ip_address = :ip_address AND attempted_at >= :since'
        );
        $statement->execute([
            'username' => '_register',
            'ip_address' => substr($ipAddress, 0, 45),
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn() >= self::MAX_REGISTRATION_ATTEMPTS;
    }

    public function recordRegistrationAttempt(string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip_address)'
        );
        $statement->execute([
            'username' => '_register',
            'ip_address' => substr($ipAddress, 0, 45),
        ]);
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

    public function isSessionUserAllowed(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM users
             WHERE id = :id
               AND is_active = 1
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function isAdmin(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM users
             WHERE id = :id
               AND is_active = 1
               AND is_admin = 1
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);

        return $statement->fetchColumn() !== false;
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

    private function isValidPassword(string $password): bool
    {
        return $password !== '' && strlen($password) >= self::MIN_PASSWORD_LENGTH;
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
