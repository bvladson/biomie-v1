<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use RuntimeException;

final class Database
{
    public static function isConfigured(Config $config, string $connection = 'db'): bool
    {
        return trim((string) $config->get($connection . '.host', '')) !== ''
            && trim((string) $config->get($connection . '.name', '')) !== ''
            && trim((string) $config->get($connection . '.user', '')) !== '';
    }

    public static function connect(Config $config, string $connection = 'db'): PDO
    {
        if (!self::isConfigured($config, $connection)) {
            throw new RuntimeException(sprintf('Conexão MySQL "%s" não configurada.', $connection));
        }

        $driver = (string) $config->get($connection . '.driver', 'mysql');
        $host = (string) $config->get($connection . '.host', 'localhost');
        $port = (int) $config->get($connection . '.port', 3306);
        $name = (string) $config->get($connection . '.name');
        $charset = (string) $config->get($connection . '.charset', 'utf8mb4');
        $user = (string) $config->get($connection . '.user');
        $pass = (string) $config->get($connection . '.pass', '');
        $timeout = max(1, (int) $config->get($connection . '.timeout', 5));

        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $driver, $host, $port, $name, $charset);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $timeout,
        ]);
    }

    /**
     * @return array{0: \PDO, 1: string}
     */
    public static function getGeoConnection(Config $config): array
    {
        try {
            if (self::isConfigured($config, 'flight_plan_db')) {
                return [self::connect($config, 'flight_plan_db'), 'flight_plan_db'];
            }
        } catch (\Throwable $e) {}

        return [self::connect($config, 'db'), 'db'];
    }
}
