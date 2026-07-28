<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CnaeOfficialImportService;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseCnaeSyncArgs(array_slice($_SERVER['argv'] ?? [], 1));

try {
    $config = new Config();
    [$pdo, $connection] = Database::getGeoConnection($config);
    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));

    $service = new CnaeOfficialImportService($pdo, $logger);
    $source = $args['source'];
    $url = $args['url'];
    if ($source === '' && $args['download']) {
        $url = $url !== '' ? $url : CnaeOfficialImportService::DEFAULT_OFFICIAL_URL;
    }

    $result = $service->sync([
        'source' => $source !== '' ? $source : null,
        'url' => $url !== '' ? $url : null,
        'apply' => $args['apply'],
        'version' => $args['version'],
        'source_label' => $args['source_label'],
    ]);
    $result['connection'] = $connection;
    $result['artifacts'] = writeCnaeSyncArtifacts($result);

    if ($args['format'] === 'json') {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        printCnaeSyncHuman($result);
    }
    exit(0);
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'error' => $exception->getMessage(),
        'mode' => $args['apply'] ? 'apply' : 'dry_run',
    ];
    if ($args['format'] === 'json') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        fwrite(STDERR, 'ERRO CNAE sync: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}

/**
 * @param list<string> $argv
 * @return array{apply:bool,download:bool,format:string,source:string,url:string,version:string,source_label:string}
 */
function parseCnaeSyncArgs(array $argv): array
{
    $args = [
        'apply' => false,
        'download' => false,
        'format' => 'human',
        'source' => '',
        'url' => '',
        'version' => CnaeOfficialImportService::DEFAULT_VERSION,
        'source_label' => 'CONCLA/IBGE',
    ];

    foreach ($argv as $arg) {
        if ($arg === '--apply') {
            $args['apply'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $args['apply'] = false;
            continue;
        }
        if ($arg === '--download') {
            $args['download'] = true;
            continue;
        }
        if ($arg === '--format=json' || $arg === '--json') {
            $args['format'] = 'json';
            continue;
        }
        if (str_starts_with($arg, '--source=')) {
            $args['source'] = substr($arg, 9);
            continue;
        }
        if (str_starts_with($arg, '--url=')) {
            $args['url'] = substr($arg, 6);
            continue;
        }
        if (str_starts_with($arg, '--version=')) {
            $args['version'] = substr($arg, 10);
            continue;
        }
        if (str_starts_with($arg, '--source-label=')) {
            $args['source_label'] = substr($arg, 15);
        }
    }

    return $args;
}

/**
 * @param array<string,mixed> $result
 * @return array{json:string,markdown:string}
 */
function writeCnaeSyncArtifacts(array $result): array
{
    $dayDir = Bootstrap::rootPath('storage/diagnostics/' . date('Ymd'));
    if (!is_dir($dayDir)) {
        mkdir($dayDir, 0775, true);
    }

    $stamp = date('Ymd_His');
    $jsonPath = $dayDir . '/cnae_sync_' . $stamp . '.json';
    $mdPath = $dayDir . '/cnae_sync_' . $stamp . '.md';

    file_put_contents(
        $jsonPath,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL
    );

    $summary = $result['summary'] ?? [];
    $lines = [
        '# CNAE.R2.3 Sync',
        '',
        '- Modo: ' . (string) ($result['mode'] ?? 'unknown'),
        '- Fonte: ' . (string) (($result['source']['path'] ?? '') ?: ($result['source']['official_url'] ?? '')),
        '- Hash: ' . (string) ($result['source']['hash'] ?? ''),
        '- Linhas lidas: ' . (string) ($summary['rows_read'] ?? 0),
        '- CNAEs validos: ' . (string) ($summary['rows_valid'] ?? 0),
        '- CNAEs invalidos: ' . (string) ($summary['rows_invalid'] ?? 0),
        '- Inseridos: ' . (string) ($summary['inserted'] ?? 0),
        '- Atualizados: ' . (string) ($summary['updated'] ?? 0),
        '- Inseririam: ' . (string) ($summary['would_insert'] ?? 0),
        '- Atualizariam: ' . (string) ($summary['would_update'] ?? 0),
        '- Clientes com descricao reparada: ' . (string) ($summary['client_descriptions_repaired'] ?? 0),
        '- Mart com descricao reparada: ' . (string) ($summary['mart_descriptions_repaired'] ?? 0),
        '',
    ];
    $warnings = $result['warnings'] ?? [];
    if (is_array($warnings) && $warnings !== []) {
        $lines[] = '## Avisos';
        foreach ($warnings as $warning) {
            $lines[] = '- ' . (string) $warning;
        }
        $lines[] = '';
    }
    file_put_contents($mdPath, implode(PHP_EOL, $lines));

    return ['json' => $jsonPath, 'markdown' => $mdPath];
}

/**
 * @param array<string,mixed> $result
 */
function printCnaeSyncHuman(array $result): void
{
    $summary = $result['summary'] ?? [];
    echo 'CNAE.R2.3 sync - ' . strtoupper((string) ($result['mode'] ?? 'dry_run')) . PHP_EOL;
    echo 'Fonte: ' . (string) ($result['source']['path'] ?? '') . PHP_EOL;
    echo 'Hash: ' . (string) ($result['source']['hash'] ?? '') . PHP_EOL;
    echo PHP_EOL;
    echo 'Linhas lidas       : ' . (int) ($summary['rows_read'] ?? 0) . PHP_EOL;
    echo 'CNAEs validos      : ' . (int) ($summary['rows_valid'] ?? 0) . PHP_EOL;
    echo 'CNAEs invalidos    : ' . (int) ($summary['rows_invalid'] ?? 0) . PHP_EOL;
    echo 'Inseririam         : ' . (int) ($summary['would_insert'] ?? 0) . PHP_EOL;
    echo 'Atualizariam       : ' . (int) ($summary['would_update'] ?? 0) . PHP_EOL;
    echo 'Inseridos          : ' . (int) ($summary['inserted'] ?? 0) . PHP_EOL;
    echo 'Atualizados        : ' . (int) ($summary['updated'] ?? 0) . PHP_EOL;
    echo 'Clientes reparados : ' . (int) ($summary['client_descriptions_repaired'] ?? 0) . PHP_EOL;
    echo 'Mart reparado      : ' . (int) ($summary['mart_descriptions_repaired'] ?? 0) . PHP_EOL;

    $artifacts = $result['artifacts'] ?? [];
    if (is_array($artifacts) && $artifacts !== []) {
        echo PHP_EOL;
        echo 'Artefatos:' . PHP_EOL;
        foreach ($artifacts as $type => $path) {
            echo '  ' . $type . ': ' . $path . PHP_EOL;
        }
    }

    $warnings = $result['warnings'] ?? [];
    if (is_array($warnings) && $warnings !== []) {
        echo PHP_EOL . 'Avisos:' . PHP_EOL;
        foreach ($warnings as $warning) {
            echo '  - ' . $warning . PHP_EOL;
        }
    }
}
