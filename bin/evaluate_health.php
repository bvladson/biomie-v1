<?php

/**
 * Fase 11 — Observabilidade e Manutenção (bin/evaluate_health.php)
 *
 * Avalia as regras de saúde do pipeline, persiste alertas em biomie_health_alerts,
 * auto-resolve alertas que deixaram de disparar e rota o log de métricas de
 * endpoints quando ultrapassa 10 MB.
 *
 * Uso:
 *   php bin/evaluate_health.php [--dry-run]
 *
 *   --dry-run  Avalia regras e exibe resultado sem gravar no banco
 *
 * Recomendação cron:
 *   0 * * * * php /caminho/biomie/bin/evaluate_health.php >> /var/log/biomie/health.log 2>&1
 *
 * Ordem recomendada na cadeia diária de crons:
 *   1. sync
 *   2. enrich_clients_governance.php
 *   3. materialize_marts.php
 *   4. compute_opportunities.php
 *   5. compute_forecasts.php
 *   6. evaluate_health.php   ← este script (também roda a cada hora)
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Observability\AlertEngine;
use BIomie\Observability\CoverageAggregator;
use BIomie\Observability\EndpointMetricsCollector;

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

$dryRun = isset($opts['dry-run']);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/evaluate_health.log';
$logger  = new Logger($logPath);
$config  = new Config();

echo sprintf("[%s] evaluate_health%s\n", date('Y-m-d H:i:s'), $dryRun ? ' DRY-RUN' : '');

$logger->info('evaluate_health: iniciando.', ['dry_run' => $dryRun]);

if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita realizada. Remova --dry-run para executar.\n";
    exit(0);
}

// ── Banco de dados ────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado ({$connection}).\n";
    $logger->error('evaluate_health: banco não configurado.');
    exit(1);
}

// ── Execução ──────────────────────────────────────────────────────────────────

try {
    $pdo = Database::connect($config, $connection);

    // 1. Cobertura de dados
    $coverage = new CoverageAggregator($pdo);

    // 2. Avaliação de regras + persistência de alertas
    $alerts  = new AlertEngine($pdo);
    $t0      = microtime(true);
    $triggered = $alerts->evaluate($coverage);
    $elapsed   = round(microtime(true) - $t0, 3);

    // 3. Rotação do log de latência de endpoints
    $metricsLog = dirname(__DIR__) . '/storage/logs/endpoint_requests.jsonl';
    $collector  = new EndpointMetricsCollector($metricsLog);
    $collector->rotate();

    // 4. Resultado
    $result = [
        'ok'              => true,
        'alerts_triggered'=> count($triggered),
        'alerts'          => $triggered,
        'duration_seconds'=> $elapsed,
        'metrics_log'     => is_file($metricsLog) ? round(filesize($metricsLog) / 1024, 1) . ' KB' : 'não existe',
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $logger->info('evaluate_health: concluido.', $result);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[ERRO] {$msg}\n";
    $logger->error('evaluate_health: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
}
