<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Observability\RequestPerformanceContext;
use BIomie\Support\CaBundleResolver;
use PDO;
use RuntimeException;

final class Database
{
    /** @var array<string, PDO> Per-process PDO pool — one connection per named DB within a request/process. */
    private static array $pool = [];
    /** @var array<string,list<string>> */
    private static array $connectionPoolKeys = [];

    public static function isConfigured(Config $config, string $connection = 'db'): bool
    {
        return trim((string) $config->get($connection . '.host', '')) !== ''
            && trim((string) $config->get($connection . '.name', '')) !== ''
            && trim((string) $config->get($connection . '.user', '')) !== '';
    }

    public static function connect(Config $config, string $connection = 'db'): PDO
    {
        RequestPerformanceContext::increment('db_connect_calls');
        RequestPerformanceContext::setTag('db_last_connection', $connection);

        if (!self::isConfigured($config, $connection)) {
            throw new RuntimeException(sprintf('Conexão MySQL "%s" não configurada.', $connection));
        }

        $driver  = (string) $config->get($connection . '.driver', 'mysql');
        $host    = (string) $config->get($connection . '.host', 'localhost');
        $port    = (int)    $config->get($connection . '.port', 3306);
        $name    = (string) $config->get($connection . '.name');
        $charset = (string) $config->get($connection . '.charset', 'utf8mb4');
        $user    = (string) $config->get($connection . '.user');
        $pass    = (string) $config->get($connection . '.pass', '');
        $timeout = max(1, (int) $config->get($connection . '.timeout', 5));
        $sslEnabled = (bool) $config->get($connection . '.ssl_enabled', false);
        $sslVerify = (bool) $config->get($connection . '.ssl_verify_server_cert', true);
        $sslCa = trim((string) $config->get($connection . '.ssl_ca', ''));
        $persistent = (bool) $config->get($connection . '.persistent', false);
        $probeOnPoolReuse = (bool) $config->get($connection . '.probe_on_pool_reuse', false);

        $poolKey = $driver . ':' . $user . '@' . $host . ':' . $port . '/' . $name
            . ':charset=' . $charset . ':ssl=' . (int) $sslEnabled;
        self::registerPoolKey($connection, $poolKey);

        if (isset(self::$pool[$poolKey])) {
            RequestPerformanceContext::increment('db_pool_hits');
            if (!$probeOnPoolReuse) {
                return self::$pool[$poolKey];
            }
            try {
                RequestPerformanceContext::increment('db_connection_probes');
                @self::$pool[$poolKey]->query('SELECT 1');
                return self::$pool[$poolKey];
            } catch (\Throwable) {
                RequestPerformanceContext::increment('db_pool_reconnects');
                unset(self::$pool[$poolKey]);
            }
        }

        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $driver, $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => $timeout,
            PDO::ATTR_PERSISTENT         => $persistent,
        ];

        if ($sslEnabled) {
            $sslCa = $sslCa !== '' ? $sslCa : CaBundleResolver::resolve();
            if ($sslCa === null || !is_file($sslCa)) {
                throw new RuntimeException(sprintf('Conexão MySQL "%s" exige SSL, mas nenhum CA bundle confiável foi encontrado.', $connection));
            }
            $sslCaOption = defined('Pdo\\Mysql::ATTR_SSL_CA')
                ? constant('Pdo\\Mysql::ATTR_SSL_CA')
                : PDO::MYSQL_ATTR_SSL_CA;
            $sslVerifyOption = defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
                ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
                : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
            $options[$sslCaOption] = $sslCa;
            $options[$sslVerifyOption] = $sslVerify;
        }

        $pdo = @new PDO($dsn, $user, $pass, $options);
        RequestPerformanceContext::increment('db_connections_opened');

        if ($sslEnabled) {
            $status = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
            if (trim((string) ($status['Value'] ?? '')) === '') {
                throw new RuntimeException(sprintf('Conexão MySQL "%s" não negociou SSL.', $connection));
            }
        }

        // O servidor MySQL gerenciado usa wait_timeout=20s (muito baixo). Tanto jobs CLI
        // de sync quanto requests web que chamam a Omie intercalam chamadas externas
        // lentas (rate-limit/retry de 11-42s) com escritas no banco; durante a chamada a
        // conexao fica ociosa e o servidor a derruba ("2006 MySQL server has gone away").
        // Elevamos o wait_timeout da SESSAO ao conectar. Nao aumenta a contagem de
        // conexoes (CLI ja retem a conexao durante o run; web e limitado pelos workers
        // FPM). Default por contexto (CLI 300s, web 120s); sobrescreve via
        // db.session_wait_timeout_seconds (env DB_SESSION_WAIT_TIMEOUT).
        $configuredWaitTimeout = (int) $config->get('db.session_wait_timeout_seconds', 0);
        $sessionWaitTimeout = $configuredWaitTimeout > 0
            ? $configuredWaitTimeout
            : (PHP_SAPI === 'cli' ? 300 : 120);

        try {
            $pdo->exec('SET SESSION wait_timeout = ' . $sessionWaitTimeout);
        } catch (\Throwable) {
            // Best-effort: alguns hosts gerenciados bloqueiam SET SESSION.
        }

        self::$pool[$poolKey] = $pdo;
        return $pdo;
    }

    public static function reconnect(Config $config, string $connection = 'db'): PDO
    {
        self::disconnect($connection);
        RequestPerformanceContext::increment('db_explicit_reconnects');

        return self::connect($config, $connection);
    }

    public static function disconnect(string $connection = 'db'): void
    {
        foreach (self::$connectionPoolKeys[$connection] ?? [] as $poolKey) {
            unset(self::$pool[$poolKey]);
            foreach (self::$connectionPoolKeys as $logical => $keys) {
                self::$connectionPoolKeys[$logical] = array_values(array_filter(
                    $keys,
                    static fn (string $key): bool => $key !== $poolKey,
                ));
            }
        }
        unset(self::$connectionPoolKeys[$connection]);
        RequestPerformanceContext::increment('db_explicit_disconnects');
    }

    private static function registerPoolKey(string $connection, string $poolKey): void
    {
        self::$connectionPoolKeys[$connection] ??= [];
        if (!in_array($poolKey, self::$connectionPoolKeys[$connection], true)) {
            self::$connectionPoolKeys[$connection][] = $poolKey;
        }
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
