<?php
declare(strict_types=1);

$env = static function (string $key, string $default): string {
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

return [
    'name' => $env('APP_NAME', 'Mi Central'),
    'env' => $env('APP_ENV', 'development'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url' => $env('APP_URL', 'http://localhost:8080'),
    'timezone' => $env('APP_TIMEZONE', 'America/Santiago'),
    'session' => [
        'name' => $env('SESSION_NAME', 'mi_central_session'),
        'secure' => filter_var($env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'idle_timeout' => (int) $env('SESSION_IDLE_TIMEOUT', '7200'),
    ],
];
