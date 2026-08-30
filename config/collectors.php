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
    'scheduler' => [
        'default_interval_minutes' => max(60, (int) $env('COLLECTOR_DEFAULT_INTERVAL_MINUTES', '1440')),
        'min_interval_minutes' => max(1, (int) $env('COLLECTOR_MIN_INTERVAL_MINUTES', '60')),
        'retry_minutes' => max(60, (int) $env('COLLECTOR_RETRY_MINUTES', '60')),
        'stale_after_minutes' => max(1, (int) $env('COLLECTOR_STALE_AFTER_MINUTES', '180')),
    ],
    'http' => [
        'timeout_seconds' => max(1, (int) $env('COLLECTOR_HTTP_TIMEOUT', '15')),
        'connect_timeout_seconds' => max(1, (int) $env('COLLECTOR_HTTP_CONNECT_TIMEOUT', '5')),
        'max_redirects' => max(0, (int) $env('COLLECTOR_HTTP_MAX_REDIRECTS', '3')),
        'max_response_bytes' => max(1024, (int) $env('COLLECTOR_MAX_RESPONSE_BYTES', '1048576')),
        'user_agent' => $env('COLLECTOR_USER_AGENT', 'MiCentral-DiscountCollector/1.0'),
    ],
    'collectors' => [
        'banco_chile' => [
            'max_items' => max(0, (int) $env('COLLECTOR_BANCO_CHILE_MAX_ITEMS', '0')),
            'request_delay_ms' => max(0, (int) $env('COLLECTOR_BANCO_CHILE_REQUEST_DELAY_MS', '500')),
        ],
        'santander_chile' => [
            'max_items' => max(0, (int) $env('COLLECTOR_SANTANDER_MAX_ITEMS', '0')),
            'request_delay_ms' => max(0, (int) $env('COLLECTOR_SANTANDER_REQUEST_DELAY_MS', '500')),
        ],
        'bancoestado' => [
            'max_items' => max(0, (int) $env('COLLECTOR_BANCOESTADO_MAX_ITEMS', '0')),
            'request_delay_ms' => max(0, (int) $env('COLLECTOR_BANCOESTADO_REQUEST_DELAY_MS', '500')),
        ],
    ],
];
