<?php
declare(strict_types=1);

use App\Database\Connection;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Migrations can only be run from CLI.\n");
    exit(1);
}

try {
    $pdo = Connection::get();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) NOT NULL PRIMARY KEY,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($migrationFiles);

    $appliedStatement = $pdo->query('SELECT migration FROM schema_migrations');
    $applied = array_fill_keys($appliedStatement->fetchAll(\PDO::FETCH_COLUMN), true);

    $ran = 0;

    foreach ($migrationFiles as $file) {
        $migration = basename($file);

        if (isset($applied[$migration])) {
            echo "Skipped: {$migration}\n";
            continue;
        }

        $callback = require $file;

        if (!is_callable($callback)) {
            throw new RuntimeException("Migration is not callable: {$migration}");
        }

        $callback($pdo);

        $statement = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $statement->execute(['migration' => $migration]);

        $ran++;

        echo "Applied: {$migration}\n";
    }

    echo $ran === 1 ? "Migrations complete: 1 applied\n" : "Migrations complete: {$ran} applied\n";
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, "Migrations failed.\n");

    if (($config['app']['debug'] ?? false) === true) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    }

    exit(1);
}
