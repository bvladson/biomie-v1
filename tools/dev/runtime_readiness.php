<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

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
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    $checks[] = [
        'name' => 'dir:' . $directory,
        'ok' => is_dir($absolute) && is_writable($absolute),
        'details' => $absolute,
    ];
}

$composer = resolveComposerExecutable($root);
$hasComposerLock = is_file($root . '/composer.lock');
$hasVendorAutoload = is_file($root . '/vendor/autoload.php');
$checks[] = [
    'name' => 'dependencies_ready',
    'ok' => $composer !== null || ($hasComposerLock && $hasVendorAutoload),
    'details' => $composer !== null
        ? 'Composer disponivel: ' . $composer
        : 'Runtime pronto com composer.lock e vendor/autoload.php; Composer e necessario apenas para build/update.',
];

$checks[] = [
    'name' => 'composer_lock',
    'ok' => $hasComposerLock,
    'details' => $hasComposerLock ? 'composer.lock presente' : 'composer.lock ausente',
];

$checks[] = [
    'name' => 'vendor_autoload',
    'ok' => $hasVendorAutoload,
    'details' => $hasVendorAutoload ? 'vendor/autoload.php presente' : 'vendor/autoload.php ausente',
];

$checks[] = [
    'name' => 'phpstan_available',
    'ok' => is_file($root . '/vendor/bin/phpstan') || is_file($root . '/vendor/bin/phpstan.bat'),
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
            $message = classifyDatabaseError($exception);
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
        $root . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
        (string) $config->get('migrations.table', 'biomie_schema_migrations'),
        (string) $config->get('app.version', (string) $config->get('migrations.app_version', 'dev'))
    );
    $status = $runner->statusWithDiagnostics();
    $summary = is_array($status['summary'] ?? null) ? $status['summary'] : [];
    $connectionErrors = (int) ($summary['connection_error'] ?? 0);
    $pending = (int) ($summary['pending'] ?? 0);
    $checks[] = [
        'name' => 'migrations_status',
        'ok' => $connectionErrors === 0,
        'details' => [
            'summary' => $summary,
            'diagnostics' => $status['diagnostics'] ?? [],
        ],
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
        'details' => classifyDatabaseError($exception),
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

function resolveComposerExecutable(string $root): ?string
{
    $composer = resolveCommand('composer');
    if ($composer !== null) {
        return $composer;
    }

    $composerPhar = $root . DIRECTORY_SEPARATOR . 'composer.phar';
    if (is_file($composerPhar)) {
        return PHP_BINARY . ' ' . $composerPhar;
    }

    return null;
}

/**
 * @return array{code:string,message:string,hint:string}
 */
function classifyDatabaseError(Throwable $exception): array
{
    $message = $exception->getMessage();

    if (stripos($message, 'auth_gssapi_client') !== false) {
        return [
            'code' => 'auth_plugin_gssapi_client',
            'message' => $message,
            'hint' => 'O usuario do banco parece exigir plugin GSSAPI/Kerberos. Use um usuario MariaDB/MySQL com senha compativel com PDO MySQL.',
        ];
    }

    if (stripos($message, 'could not find driver') !== false || stripos($message, 'pdo_mysql') !== false) {
        return [
            'code' => 'missing_pdo_mysql',
            'message' => $message,
            'hint' => 'A extensao pdo_mysql precisa estar habilitada no PHP usado pelo BIomie.',
        ];
    }

    if (stripos($message, 'Access denied') !== false) {
        return [
            'code' => 'access_denied',
            'message' => $message,
            'hint' => 'Verifique DB_USER, DB_PASS e permissoes do usuario no banco.',
        ];
    }

    if (stripos($message, 'Unknown database') !== false) {
        return [
            'code' => 'unknown_database',
            'message' => $message,
            'hint' => 'Verifique DB_NAME ou crie o banco antes de rodar migrations.',
        ];
    }

    if (stripos($message, 'Connection refused') !== false) {
        return [
            'code' => 'connection_refused',
            'message' => $message,
            'hint' => 'Verifique se MariaDB/MySQL esta ativo, host, porta e firewall.',
        ];
    }

    if (stripos($message, 'No such file or directory') !== false || stripos($message, 'php_network_getaddresses') !== false) {
        return [
            'code' => 'host_unreachable',
            'message' => $message,
            'hint' => 'Verifique DB_HOST, socket local, DNS e se a porta configurada esta correta.',
        ];
    }

    return [
        'code' => 'database_error',
        'message' => $message,
        'hint' => 'Erro de banco nao classificado. Verifique a mensagem original e as variaveis DB_*.',
    ];
}
