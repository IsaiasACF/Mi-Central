<?php
declare(strict_types=1);

namespace App\Http;

final class ClientIp
{
    public static function current(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!is_string($ip) || $ip === '') {
            return '0.0.0.0';
        }

        return substr($ip, 0, 45);
    }
}
