<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CustomerOmieEnrichmentService;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Customers\Infrastructure\CustomerDimensionRepository;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseCnaeEnrichArgs(array_slice($_SERVER['argv'] ?? [], 1));

try {
    $config = new Config();
    [$pdo, $connection] = Database::getGeoConnection($config);
    $logger = new Logger(Bootstrap::rootPath('storage/logs/cnae_enrich_clients.log'));

    $before = cnaeEnrichCoverage($pdo);
    $repo = new CustomerDimensionRepository($pdo, $logger);
    $eligible = $repo->countLocalClientsForEnrichment($args['only_missing'], 'cnae', $args['source']);

    if ($args['no_network']) {
        $sample = $repo->fetchLocalClientsForEnrichmentCursor(
            min($args['limit'], 10),
            0,
            $args['only_missing'],
            'cnae',
            $args['source']
        );
        $result = [
            'ok' => true,
            'status' => 'dry_run_no_network',
            'mode' => 'cnae_enrich_clients',
            'connection' => $connection,
            'dry_run' => true,
            'no_network' => true,
            'target' => 'cnae',
            'source' => $args['source'],
            'eligible_before' => $eligible,
            'sample' => array_map('maskCnaeCandidate', $sample),
            'coverage_before' => $before,
            'coverage_after' => $before,
            'warnings' => [
                'Nenhuma chamada Omie foi executada porque --no-network esta ativo.',
            ],
        ];
    } else {
        if (!$config->hasOmieCredentials()) {
            throw new RuntimeException('Credenciais Omie ausentes. Use --no-network para diagnosticar sem consultar a API.');
        }

        $cache = new Cache(Bootstrap::rootPath('storage/cache'));
        $omieClient = new OmieClient(
            (string) $config->get('omie.app_key'),
            (string) $config->get('omie.app_secret'),
            30,
            $logger,
            new OmieMethodPolicyRegistry($config),
            $cache,
            'cli'
        );

        $service = new CustomerOmieEnrichmentService($pdo, $omieClient, $logger, $config);
        $run = $service->runAll([
            'dry_run' => !$args['apply'],
            'limit' => $args['limit'],
            'max_batches' => $args['max_batches'],
            'only_missing' => $args['only_missing'],
            'sleep_ms' => $args['sleep_ms'],
            'max_runtime_seconds' => $args['max_runtime_seconds'],
            'target' => 'cnae',
            'source' => $args['source'],
        ]);

        $after = cnaeEnrichCoverage($pdo);
        $result = [
            'ok' => (bool) ($run['ok'] ?? false),
            'status' => (string) ($run['status'] ?? 'unknown'),
            'mode' => 'cnae_enrich_clients',
            'connection' => $connection,
            'dry_run' => !$args['apply'],
            'no_network' => false,
            'target' => 'cnae',
            'source' => $args['source'],
            'eligible_before' => $eligible,
            'run' => $run,
            'coverage_before' => $before,
            'coverage_after' => $after,
            'delta' => cnaeCoverageDelta($before, $after),
            'suggested_next_commands' => [
                'php tools/dev/cnae_coverage_probe.php --json',
                'php bin/geo_rebuild_period.php --start=2026-01-01 --end=' . date('Y-m-d') . ' --allow-open-month --assert --apply --format=json',
            ],
        ];
    }

    $result['artifacts'] = writeCnaeEnrichArtifacts($result);

    if ($args['format'] === 'json') {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        printCnaeEnrichHuman($result);
    }

    exit(0);
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'status' => 'error',
        'error' => $exception->getMessage(),
        'dry_run' => !$args['apply'],
        'no_network' => $args['no_network'],
    ];

    if ($args['format'] === 'json') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        fwrite(STDERR, 'ERRO CNAE enrich: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}

/**
 * @param list<string> $argv
 * @return array{apply:bool,no_network:bool,only_missing:bool,limit:int,max_batches:int,sleep_ms:int,max_runtime_seconds:int,source:string,format:string}
 */
function parseCnaeEnrichArgs(array $argv): array
{
    $args = [
        'apply' => false,
        'no_network' => false,
        'only_missing' => true,
        'limit' => 10,
        'max_batches' => 1,
        'sleep_ms' => 250,
        'max_runtime_seconds' => 0,
        'source' => 'sales_buyers',
        'format' => 'human',
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
        if ($arg === '--no-network') {
            $args['no_network'] = true;
            continue;
        }
        if ($arg === '--json' || $arg === '--format=json') {
            $args['format'] = 'json';
            continue;
        }
        if ($arg === '--all' || $arg === '--only-missing=0') {
            $args['only_missing'] = false;
            continue;
        }
        if (str_starts_with($arg, '--limit=')) {
            $args['limit'] = max(1, min(50, (int) substr($arg, 8)));
            continue;
        }
        if (str_starts_with($arg, '--batches=')) {
            $args['max_batches'] = max(1, min(200, (int) substr($arg, 10)));
            continue;
        }
        if (str_starts_with($arg, '--max-batches=')) {
            $args['max_batches'] = max(1, min(200, (int) substr($arg, 14)));
            continue;
        }
        if (str_starts_with($arg, '--sleep-ms=')) {
            $args['sleep_ms'] = max(0, (int) substr($arg, 11));
            continue;
        }
        if (str_starts_with($arg, '--sleep=')) {
            $args['sleep_ms'] = max(0, (int) substr($arg, 8));
            continue;
        }
        if (str_starts_with($arg, '--max-runtime-seconds=')) {
            $args['max_runtime_seconds'] = max(0, (int) substr($arg, 22));
            continue;
        }
        if (str_starts_with($arg, '--source=')) {
            $source = substr($arg, 9);
            if (in_array($source, ['sales_buyers', 'clientes_dim', 'all'], true)) {
                $args['source'] = $source;
            }
        }
    }

    if (!$args['apply'] && $args['limit'] > 10 && !$args['no_network']) {
        $args['limit'] = 10;
    }

    return $args;
}

/**
 * @return array<string,int>
 */
function cnaeEnrichCoverage(PDO $pdo): array
{
    $coverage = [
        'clientes_total' => 0,
        'clientes_com_cnae' => 0,
        'clientes_sem_cnae' => 0,
        'clientes_omie_sem_cnae' => 0,
        'auditoria_total' => 0,
        'auditoria_hoje' => 0,
        'mart_linhas_com_cnae' => 0,
        'mart_linhas_sem_cnae' => 0,
    ];

    if (cnaeTableExists($pdo, 'biomie_clientes_dim')) {
        $row = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cnae_codigo IS NOT NULL AND cnae_codigo != '' THEN 1 ELSE 0 END) AS com_cnae,
                SUM(CASE WHEN cnae_codigo IS NULL OR cnae_codigo = '' THEN 1 ELSE 0 END) AS sem_cnae
             FROM biomie_clientes_dim"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $coverage['clientes_total'] = (int) ($row['total'] ?? 0);
        $coverage['clientes_com_cnae'] = (int) ($row['com_cnae'] ?? 0);
        $coverage['clientes_sem_cnae'] = (int) ($row['sem_cnae'] ?? 0);

        if (cnaeColumnExists($pdo, 'biomie_clientes_dim', 'cnae_unknown_reason')) {
            $coverage['clientes_omie_sem_cnae'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim
                 WHERE (cnae_codigo IS NULL OR cnae_codigo = '')
                   AND cnae_unknown_reason IS NOT NULL
                   AND cnae_unknown_reason != ''"
            )->fetchColumn();
        }
    }

    if (cnaeTableExists($pdo, 'biomie_cliente_cnae_audit')) {
        $coverage['auditoria_total'] = (int) $pdo->query('SELECT COUNT(*) FROM biomie_cliente_cnae_audit')->fetchColumn();
        $coverage['auditoria_hoje'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM biomie_cliente_cnae_audit WHERE DATE(changed_at) = CURRENT_DATE'
        )->fetchColumn();
    }

    if (cnaeTableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        $row = $pdo->query(
            "SELECT
                SUM(CASE WHEN cnae_codigo IS NOT NULL AND cnae_codigo != '' AND cnae_codigo != 'SEM_CNAE' THEN 1 ELSE 0 END) AS com_cnae,
                SUM(CASE WHEN cnae_codigo IS NULL OR cnae_codigo = '' OR cnae_codigo = 'SEM_CNAE' THEN 1 ELSE 0 END) AS sem_cnae
             FROM mart_vendas_por_cnae_cnpj"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $coverage['mart_linhas_com_cnae'] = (int) ($row['com_cnae'] ?? 0);
        $coverage['mart_linhas_sem_cnae'] = (int) ($row['sem_cnae'] ?? 0);
    }

    return $coverage;
}

/**
 * @param array<string,int> $before
 * @param array<string,int> $after
 * @return array<string,int>
 */
function cnaeCoverageDelta(array $before, array $after): array
{
    $delta = [];
    foreach ($after as $key => $value) {
        $delta[$key] = $value - (int) ($before[$key] ?? 0);
    }
    return $delta;
}

function cnaeTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function cnaeColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function maskCnaeCandidate(array $row): array
{
    $doc = preg_replace('/\D+/', '', (string) ($row['seed_documento'] ?? '')) ?? '';
    if ($doc !== '') {
        $row['seed_documento'] = strlen($doc) === 14
            ? substr($doc, 0, 2) . '.***.***/****-' . substr($doc, -2)
            : str_repeat('*', max(0, strlen($doc) - 4)) . substr($doc, -4);
    }
    return $row;
}

/**
 * @param array<string,mixed> $result
 * @return array{json:string,markdown:string}
 */
function writeCnaeEnrichArtifacts(array $result): array
{
    $dayDir = Bootstrap::rootPath('storage/diagnostics/' . date('Ymd'));
    if (!is_dir($dayDir)) {
        mkdir($dayDir, 0775, true);
    }

    $stamp = date('Ymd_His');
    $jsonPath = $dayDir . '/cnae_enrich_clients_' . $stamp . '.json';
    $mdPath = $dayDir . '/cnae_enrich_clients_' . $stamp . '.md';

    file_put_contents(
        $jsonPath,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL
    );

    $run = is_array($result['run'] ?? null) ? $result['run'] : [];
    $before = is_array($result['coverage_before'] ?? null) ? $result['coverage_before'] : [];
    $after = is_array($result['coverage_after'] ?? null) ? $result['coverage_after'] : [];
    $lines = [
        '# CNAE.R2.3 Enrichment',
        '',
        '- Status: ' . (string) ($result['status'] ?? 'unknown'),
        '- Modo: ' . (!empty($result['dry_run']) ? 'dry-run' : 'apply'),
        '- Sem rede: ' . (!empty($result['no_network']) ? 'sim' : 'nao'),
        '- Fonte: ' . (string) ($result['source'] ?? ''),
        '- Elegiveis antes: ' . (string) ($result['eligible_before'] ?? 0),
        '- Processados: ' . (string) ($run['processed'] ?? 0),
        '- CNAEs vindos da Omie: ' . (string) ($run['with_cnae_from_omie'] ?? 0),
        '- CNAEs gravados: ' . (string) ($run['cnae_updated'] ?? 0),
        '- Restantes: ' . (string) ($run['total_remaining_after'] ?? ($result['eligible_before'] ?? 0)),
        '',
        '## Cobertura',
        '',
        '- Clientes com CNAE antes: ' . (string) ($before['clientes_com_cnae'] ?? 0),
        '- Clientes com CNAE depois: ' . (string) ($after['clientes_com_cnae'] ?? 0),
        '- Clientes sem CNAE antes: ' . (string) ($before['clientes_sem_cnae'] ?? 0),
        '- Clientes sem CNAE depois: ' . (string) ($after['clientes_sem_cnae'] ?? 0),
        '- Auditoria total: ' . (string) ($after['auditoria_total'] ?? 0),
        '',
    ];

    file_put_contents($mdPath, implode(PHP_EOL, $lines));

    return ['json' => $jsonPath, 'markdown' => $mdPath];
}

/**
 * @param array<string,mixed> $result
 */
function printCnaeEnrichHuman(array $result): void
{
    $run = is_array($result['run'] ?? null) ? $result['run'] : [];
    $before = is_array($result['coverage_before'] ?? null) ? $result['coverage_before'] : [];
    $after = is_array($result['coverage_after'] ?? null) ? $result['coverage_after'] : [];

    echo 'CNAE.R2.3 enrich clients - ' . strtoupper((string) ($result['status'] ?? 'unknown')) . PHP_EOL;
    echo 'Modo: ' . (!empty($result['dry_run']) ? 'dry-run' : 'apply') . PHP_EOL;
    echo 'Fonte: ' . (string) ($result['source'] ?? '') . PHP_EOL;
    echo 'Elegiveis antes: ' . (int) ($result['eligible_before'] ?? 0) . PHP_EOL;
    echo PHP_EOL;

    if ($run !== []) {
        echo 'Processados: ' . (int) ($run['processed'] ?? 0) . PHP_EOL;
        echo 'Com CNAE da Omie: ' . (int) ($run['with_cnae_from_omie'] ?? 0) . PHP_EOL;
        echo 'CNAEs gravados: ' . (int) ($run['cnae_updated'] ?? 0) . PHP_EOL;
        echo 'Restantes: ' . (int) ($run['total_remaining_after'] ?? 0) . PHP_EOL;
        echo 'Parada: ' . (string) ($run['stopped_reason'] ?? '') . PHP_EOL;
        echo PHP_EOL;
    }

    echo 'Cobertura clientes com CNAE: ' . (int) ($before['clientes_com_cnae'] ?? 0)
        . ' -> ' . (int) ($after['clientes_com_cnae'] ?? 0) . PHP_EOL;
    echo 'Cobertura clientes sem CNAE: ' . (int) ($before['clientes_sem_cnae'] ?? 0)
        . ' -> ' . (int) ($after['clientes_sem_cnae'] ?? 0) . PHP_EOL;
    echo 'Auditoria total: ' . (int) ($after['auditoria_total'] ?? 0) . PHP_EOL;

    $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
    if ($artifacts !== []) {
        echo PHP_EOL . 'Artefatos:' . PHP_EOL;
        foreach ($artifacts as $type => $path) {
            echo '  ' . $type . ': ' . $path . PHP_EOL;
        }
    }

    $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
    if ($warnings !== []) {
        echo PHP_EOL . 'Avisos:' . PHP_EOL;
        foreach ($warnings as $warning) {
            echo '  - ' . (string) $warning . PHP_EOL;
        }
    }
}
