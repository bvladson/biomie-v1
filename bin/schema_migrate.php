<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$runner = new MigrationRunner(
    $config,
    $logger,
    dirname(__DIR__) . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
    (string) $config->get('migrations.table', 'biomie_schema_migrations'),
    (string) $config->get('app.version', (string) $config->get('migrations.app_version', 'dev'))
);

try {
    $argv = $_SERVER['argv'] ?? [];
    $command = strtolower((string) ($argv[1] ?? 'status'));
    $options = parseOptions(array_slice($argv, 2));

    $result = match ($command) {
        'status' => [
            'ok' => true,
            'command' => 'status',
            'result' => $runner->status(),
        ],
        'migrate', 'up' => [
            'ok' => true,
            'command' => 'migrate',
            'target' => $options['target'],
            'result' => $runner->migrate($options['target']),
        ],
        'rollback', 'down' => [
            'ok' => true,
            'command' => 'rollback',
            'version' => $options['version'],
            'result' => $runner->rollback($options['version']),
        ],
        'verify' => buildVerifyResponse($runner->verifyChecksums()),
        'help', '--help', '-h' => [
            'ok' => true,
            'command' => 'help',
            'usage' => [
                'php bin/schema_migrate.php status',
                'php bin/schema_migrate.php migrate',
                'php bin/schema_migrate.php migrate --target=20260413050300',
                'php bin/schema_migrate.php rollback',
                'php bin/schema_migrate.php rollback --version=20260413050500',
                'php bin/schema_migrate.php verify',
            ],
        ],
        default => throw new RuntimeException('Comando invalido. Use status, migrate, rollback ou verify.'),
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    if (($result['ok'] ?? true) !== true) {
        exit(2);
    }
} catch (Throwable $exception) {
    $logger->error('Falha ao executar runner de migrations.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    exit(1);
}

/**
 * @param list<string> $args
 * @return array{target:?string,version:?string}
 */
function parseOptions(array $args): array
{
    $options = [
        'target' => null,
        'version' => null,
    ];

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if ($key === 'target' && is_string($value) && $value !== '') {
            $options['target'] = $value;
            continue;
        }

        if ($key === 'version' && is_string($value) && $value !== '') {
            $options['version'] = $value;
        }
    }

    return $options;
}

/**
 * @param array{verified:list<array<string,mixed>>,mismatches:list<array<string,mixed>>} $result
 * @return array<string,mixed>
 */
function buildVerifyResponse(array $result): array
{
    return [
        'ok' => $result['mismatches'] === [],
        'command' => 'verify',
        'verified_count' => count($result['verified']),
        'mismatch_count' => count($result['mismatches']),
        'result' => $result,
    ];
}
