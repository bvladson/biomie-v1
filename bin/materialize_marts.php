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
use BIomie\CronLock;
use BIomie\Database;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\Support\SecretMask;
use BIomie\SyncPipelineRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Parse de argumentos ───────────────────────────────────────────────────────

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9_-]+)$/', $arg, $m)) {
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
$force = isset($opts['force']);

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

$lock    = null;
$runId   = 0;
$startMs = (int) round(microtime(true) * 1000);

try {
    $pdo = Database::connect($config, $connection);

    $pipeline = new SyncPipelineRepository($pdo, $logger);
    $pipeline->ensureSchema();

    $lock = CronLock::acquire($pdo, 'biomie:materialize_marts', $logger);
    if ($lock === null) {
        $skipped = ['ok' => true, 'status' => 'skipped', 'reason' => 'lock_held',
            'message' => 'Outra instancia de materialize_marts ja esta em execucao.'];
        echo json_encode($skipped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    $runId = $pipeline->startRun('materialize', 'gold', 'all', ['trigger_source' => 'cron']);

    $source = canonicalFreshnessForMaterialization($pdo);
    if (($source['status'] ?? 'critical') === 'critical' && !$force) {
        $pipeline->finishRun($runId, 'failed_retryable', [], 'Bloqueado: origem canonica critica. Use --force em manutencao.');
        $output = [
            'ok' => false,
            'status' => 'blocked_stale_source',
            'message' => 'Materializacao Gold bloqueada: origem canonica/Omie critica ou sem sync recente. Use --force apenas em manutencao.',
            'source_freshness' => $source,
        ];
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $logger->warning('materialize_marts: bloqueado por fonte stale.', $output);
        exit(1);
    }

    $pipelineStatus = new PipelineStatusRepository($pdo, $logger);
    $builder        = new GoldMartBuilder($pdo, $logger, $pipelineStatus);

    $result = $builder->refreshPeriod($startDate, $endDate);

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    $totalWritten = (int) array_sum(array_map('intval', array_values($result)));

    $pipeline->finishRun($runId, 'completed', [
        'items_processed' => $totalWritten,
        'records_inserted' => $totalWritten,
        'duration_ms' => $durationMs,
        'start' => $startDate,
        'end' => $endDate,
    ]);

    $output = [
        'ok'     => true,
        'start'  => $startDate,
        'end'    => $endDate,
        'counts' => $result,
        'source_freshness' => $source,
        'origin_used' => $force ? 'forced' : (($source['status'] ?? '') === 'ok' ? 'canonical_fresh' : 'canonical_stale'),
        'status' => $pipelineStatus->pipelineStatus('gold_mart_refresh', $startDate, $endDate),
        'duration_ms' => $durationMs,
    ];

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $logger->info('materialize_marts: concluido.', $output);
    exit(0);
} catch (\Throwable $e) {
    $msg = SecretMask::sanitizeErrorMessage($e->getMessage(), 500);
    if ($runId > 0 && isset($pipeline)) {
        try { $pipeline->finishRun($runId, 'failed_terminal', [], $msg); } catch (\Throwable) {}
    }
    echo "[ERRO] {$msg}\n";
    $logger->error('materialize_marts: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
} finally {
    $lock?->release();
}

/**
 * @return array<string,mixed>
 */
function canonicalFreshnessForMaterialization(\PDO $pdo): array
{
    $dates = [];
    foreach ([
        // Only true sync timestamp columns — never data_competencia/data_emissao (business dates, not sync timestamps)
        'biomie_notas_fiscais_canonicas' => ['sincronizado_em', 'updated_at'],
        'biomie_pedidos_canonicos'       => ['sincronizado_em', 'updated_at'],
    ] as $table => $columns) {
        $date = latestTableDate($pdo, $table, $columns);
        if ($date !== null) {
            $dates[$table] = $date;
        }
    }
    if ($dates === []) {
        return ['status' => 'critical', 'last_canonical_at' => null, 'lag_hours' => null];
    }
    rsort($dates);
    $last = $dates[0];
    $lag = round(max(0, time() - (strtotime($last) ?: time())) / 3600, 2);
    return [
        'status' => $lag <= 24 ? 'ok' : ($lag <= 72 ? 'warning' : 'critical'),
        'last_canonical_at' => $last,
        'lag_hours' => $lag,
    ];
}

/** @param list<string> $columns */
function latestTableDate(\PDO $pdo, string $table, array $columns): ?string
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->query(sprintf('SELECT MAX(`%s`) FROM `%s`', $column, $table));
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        if ($value !== false && $value !== null && (string) $value !== '') {
            return (string) $value;
        }
    }
    return null;
}

function tableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}
