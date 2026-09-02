<?php
declare(strict_types=1);

use App\Database\Connection;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

try {
    $pdo = Connection::get();

    $statement = $pdo->prepare('SELECT 1 AS result');
    $statement->execute();
    $row = $statement->fetch();

    if (!is_array($row) || (int) $row['result'] !== 1) {
        fwrite(STDERR, "Database connection: FAILED\n");
        exit(1);
    }

    $metadata = $pdo->query(
        "SELECT DATABASE() AS database_name, VERSION() AS server_version, @@character_set_connection AS connection_charset"
    )->fetch(\PDO::FETCH_ASSOC);

    if (
        !is_array($metadata)
        || $metadata['database_name'] === null
        || $metadata['server_version'] === null
        || $metadata['connection_charset'] !== 'utf8mb4'
    ) {
        fwrite(STDERR, "Database connection: FAILED\n");
        exit(1);
    }

    echo "Database connection: OK\n";
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, "Database connection: FAILED\n");

    if (($config['app']['debug'] ?? false) === true) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    }

    exit(1);
}
