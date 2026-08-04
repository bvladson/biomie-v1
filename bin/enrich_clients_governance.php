<?php

/**
 * Enriquecimento de governança dos clientes.
 *
 * Aplica classificação canônica de tipo_pessoa, segmento_cnae e recorrência
 * a todos os clientes encontrados nas NF-e canônicas e salva em biomie_clientes_dim.
 *
 * Uso:
 *   php bin/enrich_clients_governance.php [--batch=N] [--dry-run]
 *
 * Opções:
 *   --batch=N   Tamanho do lote por iteração (padrão: 300)
 *   --dry-run   Exibe quantos clientes seriam processados sem gravar
 *
 * Ordem recomendada de execução diária:
 *   1. sync (cron de integração Omie)
 *   2. php bin/enrich_clients_governance.php        ← este script
 *   3. php bin/materialize_marts.php
 *
 * Recomendação cron:
 *   30 1 * * * php /caminho/biomie/bin/enrich_clients_governance.php >> /var/log/biomie/governance.log 2>&1
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\DataGovernance\ClientGovernanceService;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Argumentos ────────────────────────────────────────────────────────────────

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z_-]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_-]+)$/', $arg, $m)) {
        $opts[$m[1]] = true;
    }
}

$batchSize = isset($opts['batch']) ? max(1, (int) $opts['batch']) : 300;
$dryRun    = isset($opts['dry-run']);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/governance_enrichment.log';
$logger  = new Logger($logPath);
$config  = new Config();

$logger->info('enrich_clients_governance: iniciando.', [
    'batch_size' => $batchSize,
    'dry_run'    => $dryRun,
]);

echo sprintf("[%s] enrich_clients_governance: batch=%d%s\n",
    date('Y-m-d H:i:s'), $batchSize, $dryRun ? ' DRY-RUN' : '');

if ($dryRun) {
    // Conta clientes únicos na fonte sem gravar
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo  = Database::connect($config, $connection);
            $stmt = $pdo->query('SELECT COUNT(DISTINCT codigo_cliente_omie) FROM biomie_notas_fiscais_canonicas WHERE codigo_cliente_omie IS NOT NULL');
            $count = (int) $stmt->fetchColumn();
            echo "[DRY-RUN] {$count} clientes seriam processados.\n";
        } catch (\Throwable $e) {
            echo "[DRY-RUN] Não foi possível contar: " . $e->getMessage() . "\n";
        }
    }
    exit(0);
}

// ── Execução ─────────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado ({$connection}).\n";
    $logger->error('enrich_clients_governance: banco não configurado.');
    exit(1);
}

$pipeline = null;
$jobId    = 0;

try {
    $pdo     = Database::connect($config, $connection);
    $service = new ClientGovernanceService($pdo, $logger);

    // Registra a execução em biomie_pipeline_jobs. Sem isto o AlertEngine
    // acusa "job nunca executou" para sempre, mesmo com o cron rodando todo dia.
    // Este job não é por competência: usa o dia corrente como período.
    $pipeline = new PipelineStatusRepository($pdo, $logger);
    $jobId    = $pipeline->startJob('enrich_clients_governance', date('Y-m-d'), date('Y-m-d'));

    $t0    = microtime(true);
    $total = $service->enrichAll($batchSize);
    $secs  = round(microtime(true) - $t0, 2);

    $pipeline->completeJob($jobId, ['clients_enriched' => $total], $secs);

    $result = [
        'ok'               => true,
        'clients_enriched' => $total,
        'duration_seconds' => $secs,
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $logger->info('enrich_clients_governance: concluido.', $result);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    $pipeline?->failJob($jobId, $msg);
    echo "[ERRO] {$msg}\n";
    $logger->error('enrich_clients_governance: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
}
