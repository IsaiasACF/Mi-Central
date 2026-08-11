<?php
declare(strict_types=1);

namespace App\Http;

final class RequireAuth
{
    public static function ensure(array $sessionConfig): void
    {
        Session::start($sessionConfig);

        if (Session::isAuthenticated()) {
            return;
        }

        header('Location: /login.php', true, 302);
        exit;
    }
}
