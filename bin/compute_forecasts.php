<?php

/**
 * Camada preditiva — Fase 8 (bin/compute_forecasts.php).
 *
 * Executa o ForecastEngine e materializa projeções em mart_forecasts.
 *
 * Uso:
 *   php bin/compute_forecasts.php [--competencia=YYYY-MM] [--dry-run]
 *
 * Padrões:
 *   --competencia : mês corrente (YYYY-MM)
 *   --dry-run     : exibe configuração sem gravar
 *
 * Ordem recomendada de execução diária:
 *   1. sync (integração Omie)
 *   2. enrich_clients_governance.php
 *   3. materialize_marts.php
 *   4. compute_opportunities.php
 *   5. compute_forecasts.php  ← este script
 *
 * Recomendação cron:
 *   30 3 * * * php /caminho/biomie/bin/compute_forecasts.php >> /var/log/biomie/forecasts.log 2>&1
 *
 * Nota: projeções de baixa confiança (<45%) devem ser interpretadas como
 * indicativo, não como compromisso — especialmente nos primeiros 7 dias do mês.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Predictive\ForecastEngine;

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

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/compute_forecasts.log';
$logger  = new Logger($logPath);
$config  = new Config();

echo sprintf(
    "[%s] compute_forecasts: competencia=%s%s\n",
    date('Y-m-d H:i:s'),
    $competencia,
    $dryRun ? ' DRY-RUN' : ''
);

$logger->info('compute_forecasts: iniciando.', [
    'competencia' => $competencia,
    'dry_run'     => $dryRun,
]);

if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita realizada. Remova --dry-run para executar.\n";
    exit(0);
}

// ── Execução ──────────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado ({$connection}).\n";
    $logger->error('compute_forecasts: banco não configurado.');
    exit(1);
}

try {
    $pdo    = Database::connect($config, $connection);
    $engine = new ForecastEngine($pdo, $logger);

    $t0     = microtime(true);
    $counts = $engine->refreshForCompetencia($competencia);
    $secs   = round(microtime(true) - $t0, 2);

    $totalForecasts = array_sum($counts);

    $result = [
        'ok'               => true,
        'competencia'      => $competencia,
        'total_forecasts'  => $totalForecasts,
        'por_tipo'         => $counts,
        'duration_seconds' => $secs,
        'aviso'            => $counts['mes_corrente'] > 0
            ? 'Projeções calculadas. Confiança varia por dias com dados disponíveis.'
            : 'Nenhuma projeção gerada — verifique se mart_vendedor_dia está populado.',
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $logger->info('compute_forecasts: concluido.', $result);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[ERRO] {$msg}\n";
    $logger->error('compute_forecasts: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
}
