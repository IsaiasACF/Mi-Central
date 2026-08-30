<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefixes = [
        'App\\' => $basePath . '/app/',
        'Modules\\' => $basePath . '/modules/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = $directory . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($path)) {
            require $path;
        }

        return;
    }
});

$config = [
    'app' => require $basePath . '/config/app.php',
    'database' => require $basePath . '/config/database.php',
    'collectors' => require $basePath . '/config/collectors.php',
    'video' => require $basePath . '/config/video.php',
];

date_default_timezone_set($config['app']['timezone']);

if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

return $config;
