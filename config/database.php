<?php
declare(strict_types=1);

$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    return $value;
};

return [
    'host' => $env('DB_HOST', 'db'),
    'port' => (int) $env('DB_PORT', '3306'),
    'database' => $env('DB_DATABASE', 'mi_central'),
    'username' => $env('DB_USERNAME', 'mi_central'),
    'password' => $env('DB_PASSWORD'),
    'charset' => $env('DB_CHARSET', 'utf8mb4'),
];
