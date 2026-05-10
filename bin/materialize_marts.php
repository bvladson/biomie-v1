<?php

/**
 * Materialização diária da camada gold (mart_*).
 *
 * Uso:
 *   php bin/materialize_marts.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--months=N]
 *
 * Padrões:
 *   --start  : primeiro dia do mês corrente
 *   --end    : hoje
 *   --months : quantos meses retroativos incluir além do período explícito
 *              (ex.: --months=3 amplia start em 3 meses)
 *
 * Recomendação cron:
 *   0 2 * * * php /caminho/biomie/bin/materialize_marts.php >> /var/log/biomie/marts.log 2>&1
 *
 * Saída: JSON com contadores por mart + status do job.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Parse de argumentos ───────────────────────────────────────────────────────

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $arg, $m)) {
        $opts[$m[1]] = true;
    }
}

$today      = date('Y-m-d');
$defaultEnd = $today;
$defaultStart = date('Y-m-01');   // primeiro dia do mês corrente

$extraMonths = isset($opts['months']) ? max(0, (int) $opts['months']) : 0;
$endDate     = isset($opts['end'])   ? $opts['end']   : $defaultEnd;
$startDate   = isset($opts['start']) ? $opts['start'] : $defaultStart;

if ($extraMonths > 0) {
    $startDate = date('Y-m-01', strtotime($startDate . " -$extraMonths months"));
}

$dryRun = isset($opts['dry-run']);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/materialize_marts.log';
$logger  = new Logger($logPath);
$config  = new Config();

$logger->info('materialize_marts: iniciando.', [
    'start'        => $startDate,
    'end'          => $endDate,
    'extra_months' => $extraMonths,
    'dry_run'      => $dryRun,
]);

echo sprintf("[%s] materialize_marts: start=%s end=%s%s\n",
    date('Y-m-d H:i:s'), $startDate, $endDate, $dryRun ? ' DRY-RUN' : '');

if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita realizada.\n";
    exit(0);
}

// ── Execução ─────────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado. Verifique {$connection} em config.\n";
    $logger->error('materialize_marts: banco não configurado.', ['connection' => $connection]);
    exit(1);
}

try {
    $pdo = Database::connect($config, $connection);

    $pipelineStatus = new PipelineStatusRepository($pdo, $logger);
    $builder        = new GoldMartBuilder($pdo, $logger, $pipelineStatus);

    $result = $builder->refreshPeriod($startDate, $endDate);

    $output = [
        'ok'     => true,
        'start'  => $startDate,
        'end'    => $endDate,
        'counts' => $result,
        'status' => $pipelineStatus->pipelineStatus('gold_mart_refresh', $startDate, $endDate),
    ];

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $logger->info('materialize_marts: concluido.', $output);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[ERRO] {$msg}\n";
    $logger->error('materialize_marts: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
}
