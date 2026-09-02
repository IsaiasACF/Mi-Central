<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = require dirname(__DIR__, 2) . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'],
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => (bool) ($config['emulate_prepares'] ?? false),
        ];
        $sslOptions = self::sslOptions(is_array($config['ssl'] ?? null) ? $config['ssl'] : []);

        if ($sslOptions !== []) {
            $options += $sslOptions;
        }

        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Database connection failed. Check the DB_* environment variables and MariaDB service status.',
                0,
                $exception,
            );
        }

        return self::$connection;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, mixed>
     */
    private static function sslOptions(array $config): array
    {
        if (($config['enabled'] ?? false) !== true) {
            return [];
        }

        $options = [];
        foreach ([
            'ca' => 'Pdo\Mysql::ATTR_SSL_CA',
            'cert' => 'Pdo\Mysql::ATTR_SSL_CERT',
            'key' => 'Pdo\Mysql::ATTR_SSL_KEY',
        ] as $key => $constant) {
            $path = $config[$key] ?? '';

            if (is_string($path) && $path !== '') {
                $attribute = self::pdoConstant($constant);

                if ($attribute !== null) {
                    $options[$attribute] = $path;
                }
            }
        }

        $verifyAttribute = self::pdoConstant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT');

        if ($verifyAttribute !== null) {
            $options[$verifyAttribute] = (bool) ($config['verify_server_cert'] ?? true);
        }

        return $options;
    }

    private static function pdoConstant(string $name): ?int
    {
        if (!defined($name)) {
            return null;
        }

        $value = constant($name);

        return is_int($value) ? $value : null;
    }
}
