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
    'emulate_prepares' => filter_var($env('DB_EMULATE_PREPARES', 'false'), FILTER_VALIDATE_BOOLEAN),
    'ssl' => [
        'enabled' => filter_var($env('DB_SSL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'ca' => $env('DB_SSL_CA'),
        'cert' => $env('DB_SSL_CERT'),
        'key' => $env('DB_SSL_KEY'),
        'verify_server_cert' => filter_var($env('DB_SSL_VERIFY_SERVER_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
];
