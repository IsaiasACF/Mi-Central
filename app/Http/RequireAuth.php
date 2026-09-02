<?php
declare(strict_types=1);

namespace App\Http;

final class RequireAuth
{
    public static function ensure(array $sessionConfig): void
    {
        Session::start($sessionConfig);

        $user = Session::user();
        $userId = is_array($user) ? (int) ($user['user_id'] ?? 0) : 0;

        if ($userId > 0) {
            return;
        }

        if (Session::isAuthenticated()) {
            Session::destroy();
            Session::start($sessionConfig);
        }

        header('Location: /login.php', true, 302);
        exit;
    }
}
