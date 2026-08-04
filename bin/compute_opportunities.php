<?php

/**
 * Detecção de oportunidades comerciais (Fase 7).
 *
 * Executa todos os detectores e materializa resultados em mart_oportunidades.
 *
 * Uso:
 *   php bin/compute_opportunities.php --company=SLUG [--competencia=YYYY-MM] [--dry-run]
 *
 * Padrões:
 *   --competencia : mês corrente (YYYY-MM)
 *   --dry-run     : exibe contagem sem gravar
 *
 * Ordem recomendada de execução diária:
 *   1. sync (integração Omie)
 *   2. enrich_clients_governance.php
 *   3. materialize_marts.php
 *   4. compute_opportunities.php  ← este script
 *
 * Recomendação cron:
 *   Use cron/hostinger/compute_opportunities.sh para processar todas as empresas.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Intelligence\OpportunityDetector;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\Support\TenantCliContext;

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

$competencia = $opts['competencia'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
    echo "[ERRO] Formato de competência inválido. Use YYYY-MM.\n";
    exit(1);
}

$dryRun = isset($opts['dry-run']);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/compute_opportunities.log';
$logger  = new Logger($logPath);
$config  = new Config();

echo sprintf("[%s] compute_opportunities: competencia=%s%s\n",
    date('Y-m-d H:i:s'), $competencia, $dryRun ? ' DRY-RUN' : '');

$logger->info('compute_opportunities: iniciando.', [
    'competencia' => $competencia,
    'dry_run'     => $dryRun,
]);

if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita realizada. Remova --dry-run para executar.\n";
    exit(0);
}

// ── Execução ─────────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado ({$connection}).\n";
    $logger->error('compute_opportunities: banco não configurado.');
    exit(1);
}

$pipeline = null;
$jobId    = 0;

try {
    $pdo      = Database::connect($config, $connection);
    $tenant   = TenantCliContext::boot($pdo, $argv ?? []);
    $detector = new OpportunityDetector($pdo, $logger);

    // Registra a execução em biomie_pipeline_jobs. Sem isto o AlertEngine
    // acusa "job nunca executou" para sempre, mesmo com o cron rodando todo dia.
    $pipeline = new PipelineStatusRepository($pdo, $logger);
    $jobId    = $pipeline->startJob(
        'compute_opportunities',
        $competencia . '-01',
        date('Y-m-t', (int) strtotime($competencia . '-01'))
    );

    $t0     = microtime(true);
    $counts = $detector->refreshForCompetencia($competencia);
    $secs   = round(microtime(true) - $t0, 2);

    $pipeline->completeJob($jobId, $counts, $secs);

    $totalOportunidades = array_sum($counts);

    $result = [
        'ok'                 => true,
        'company_id'         => $tenant['company_id'],
        'competencia'        => $competencia,
        'total_oportunidades' => $totalOportunidades,
        'por_tipo'           => $counts,
        'duration_seconds'   => $secs,
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $logger->info('compute_opportunities: concluido.', $result);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    $pipeline?->failJob($jobId, $msg);
    echo "[ERRO] {$msg}\n";
    $logger->error('compute_opportunities: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
}
