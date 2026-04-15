<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');

$checks = [];

$checks[] = [
    'name' => 'php_version',
    'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'details' => PHP_VERSION,
];

$requiredExtensions = ['pdo_mysql', 'curl', 'mbstring', 'json'];
$missingExtensions = [];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $missingExtensions[] = $extension;
    }
}
$checks[] = [
    'name' => 'required_extensions',
    'ok' => $missingExtensions === [],
    'details' => $missingExtensions === [] ? 'ok' : ('faltando: ' . implode(', ', $missingExtensions)),
];

$directories = [
    'storage/cache',
    'storage/cache/jobs',
    'storage/logs',
    'storage/releases',
];
foreach ($directories as $directory) {
    $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    $checks[] = [
        'name' => 'dir:' . $directory,
        'ok' => is_dir($absolute) && is_writable($absolute),
        'details' => $absolute,
    ];
}

$checks[] = [
    'name' => 'composer_available',
    'ok' => resolveCommand('composer') !== null,
    'details' => resolveCommand('composer') ?? 'composer nao encontrado no PATH',
];

$checks[] = [
    'name' => 'phpstan_available',
    'ok' => is_file(dirname(__DIR__, 2) . '/vendor/bin/phpstan') || is_file(dirname(__DIR__, 2) . '/vendor/bin/phpstan.bat'),
    'details' => 'vendor/bin/phpstan(.bat)',
];

foreach (['db', 'flight_plan_db'] as $connection) {
    $configured = Database::isConfigured($config, $connection);
    $message = $configured ? 'configurado' : 'nao configurado';
    $ok = $configured;

    if ($configured) {
        try {
            Database::connect($config, $connection);
            $message = 'conexao ok';
        } catch (Throwable $exception) {
            $ok = false;
            $message = $exception->getMessage();
        }
    }

    $checks[] = [
        'name' => 'database:' . $connection,
        'ok' => $ok,
        'details' => $message,
    ];
}

try {
    $runner = new MigrationRunner(
        $config,
        $logger,
        dirname(__DIR__, 2) . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
        (string) $config->get('migrations.table', 'biomie_schema_migrations'),
        (string) $config->get('app.version', (string) $config->get('migrations.app_version', 'dev'))
    );
    $status = $runner->status();
    $summary = is_array($status['summary'] ?? null) ? $status['summary'] : [];
    $connectionErrors = (int) ($summary['connection_error'] ?? 0);
    $pending = (int) ($summary['pending'] ?? 0);
    $checks[] = [
        'name' => 'migrations_status',
        'ok' => $connectionErrors === 0,
        'details' => $summary,
    ];
    $checks[] = [
        'name' => 'migrations_pending',
        'ok' => $pending === 0,
        'details' => $summary,
    ];
} catch (Throwable $exception) {
    $checks[] = [
        'name' => 'migrations_status',
        'ok' => false,
        'details' => $exception->getMessage(),
    ];
}

$result = [
    'ok' => count(array_filter($checks, static fn (array $check): bool => $check['ok'] !== true)) === 0,
    'generated_at' => date(DATE_ATOM),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function resolveCommand(string $command): ?string
{
    $path = getenv('PATH');
    if ($path === false || trim($path) === '') {
        return null;
    }

    $extensions = [''];
    if (DIRECTORY_SEPARATOR === '\\') {
        $pathext = getenv('PATHEXT');
        $extensions = $pathext !== false && trim($pathext) !== ''
            ? array_map('strtolower', array_filter(explode(';', $pathext)))
            : ['.exe', '.bat', '.cmd'];
    }

    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        $dir = trim($dir);
        if ($dir === '') {
            continue;
        }

        foreach ($extensions as $extension) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $command . $extension;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}
