<?php
declare(strict_types=1);

use App\Database\Connection;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Seeds can only be run from CLI.\n");
    exit(1);
}

try {
    $pdo = Connection::get();
    $seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.php') ?: [];
    sort($seedFiles);

    $ran = 0;

    foreach ($seedFiles as $file) {
        $seed = basename($file);
        $callback = require $file;

        if (!is_callable($callback)) {
            throw new RuntimeException("Seed is not callable: {$seed}");
        }

        $callback($pdo);
        $ran++;

        echo "Seeded: {$seed}\n";
    }

    echo $ran === 1 ? "Seeds complete: 1 file\n" : "Seeds complete: {$ran} files\n";
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, "Seeds failed.\n");

    if (($config['app']['debug'] ?? false) === true) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    }

    exit(1);
}
