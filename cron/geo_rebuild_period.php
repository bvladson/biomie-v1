<?php

/**
 * cron/geo_rebuild_period.php — Rematerialização geo para um período específico.
 *
 * Executa em sequência:
 *   1. CustomerPeriodMartBuilder  → mart_cliente_periodo   (por competência)
 *   2. GeoBuyerMartBuilder        → mart_compradores_por_geo (por data, ON DUPLICATE KEY UPDATE)
 *
 * Não exige competência fechada — funciona com o mês corrente (open month).
 * Não apaga dados de outros períodos.
 *
 * Uso:
 *   php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31
 *   php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --assert --json
 *
 * Opções:
 *   --start=YYYY-MM-DD   Data de início do período (obrigatório)
 *   --end=YYYY-MM-DD     Data de fim do período (obrigatório)
 *   --assert             Executa geo_sales_assert após rebuild e retorna exit 1 se falhar
 *   --json               Saída JSON em vez de texto
 *   --dry-run            Mostra o que seria feito sem alterar dados
 */

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\GeoBuyerMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

// ── Arg parsing ───────────────────────────────────────────────────────────────

$opts   = getopt('', ['start:', 'end:', 'assert', 'json', 'dry-run', 'help']);

if (isset($opts['help']) || !isset($opts['start'], $opts['end'])) {
    echo "Usage: php cron/geo_rebuild_period.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--assert] [--json] [--dry-run]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$startDate = trim((string) $opts['start']);
$endDate   = trim((string) $opts['end']);
$doAssert  = isset($opts['assert']);
$jsonMode  = isset($opts['json']);
$dryRun    = isset($opts['dry-run']);

// Validate dates
foreach (['start' => $startDate, 'end' => $endDate] as $name => $val) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        fwrite(STDERR, "Erro: --{$name} deve ter formato YYYY-MM-DD (recebido: {$val})\n");
        exit(1);
    }
}

if ($startDate > $endDate) {
    fwrite(STDERR, "Erro: --start deve ser anterior ou igual a --end.\n");
    exit(1);
}

// Derive competências from range
function competenciasInRange(string $start, string $end): array
{
    $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $start)->modify('first day of this month');
    $limit  = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    $result = [];
    while ($cursor <= $limit) {
        $result[] = $cursor->format('Y-m');
        $cursor   = $cursor->modify('+1 month');
    }
    return array_values(array_unique($result));
}

$competencias = competenciasInRange($startDate, $endDate);

// ── Connect ───────────────────────────────────────────────────────────────────

try {
    [$pdo, $connName] = Database::getGeoConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "Erro de conexão: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Capture before-counts ────────────────────────────────────────────────────

function countRows(PDO $pdo, string $table): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    } catch (\Throwable) {
        return -1;
    }
}

function countForPeriod(PDO $pdo, string $table, string $col, string $start, string $end): int
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` BETWEEN :s AND :e");
        $s->execute(['s' => $start, 'e' => $end]);
        return (int) $s->fetchColumn();
    } catch (\Throwable) {
        return -1;
    }
}

$startedAt   = microtime(true);
$startedAtTs = date('c');

$before = [
    'mart_cliente_periodo_total'          => countRows($pdo, 'mart_cliente_periodo'),
    'mart_cliente_periodo_period'         => countForPeriod($pdo, 'mart_cliente_periodo', 'competencia', substr($startDate, 0, 7), substr($endDate, 0, 7)),
    'mart_compradores_por_geo_total'      => countRows($pdo, 'mart_compradores_por_geo'),
    'mart_compradores_por_geo_period'     => countForPeriod($pdo, 'mart_compradores_por_geo', 'competencia', substr($startDate, 0, 7), substr($endDate, 0, 7)),
];

// Also snapshot canonical NF count for reference
$canonicalNfs = 0;
$canonicalMerc = 0.0;
try {
    $cs = $pdo->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(valor_mercadorias), 0) AS merc
         FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
           AND status_fiscal = 'autorizada'"
    );
    $cs->execute(['s' => $startDate, 'e' => $endDate]);
    $cr = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
    $canonicalNfs  = (int) ($cr['n'] ?? 0);
    $canonicalMerc = (float) ($cr['merc'] ?? 0);
} catch (\Throwable) {}

// ── Pipeline status job ───────────────────────────────────────────────────────

$pipelineRepo = null;
$jobId = 0;
try {
    $tableCheck = $pdo->query("SELECT 1 FROM biomie_pipeline_jobs LIMIT 1");
    $pipelineRepo = new PipelineStatusRepository($pdo, $logger);
    $jobId = $pipelineRepo->startJob('geo_rebuild_period', $startDate, $endDate);
} catch (\Throwable) {
    // Table may not exist — continue without tracking
}

// ── Step 1: CustomerPeriodMartBuilder ─────────────────────────────────────────

$step1Results = [];
$step1Error   = null;
$step1Rows    = 0;

if (!$dryRun) {
    $builder = new CustomerPeriodMartBuilder($pdo, $logger);
    foreach ($competencias as $comp) {
        $compStart = $comp . '-01';
        $compEnd   = date('Y-m-t', strtotime($compStart));
        // Clamp compEnd to endDate if it exceeds
        if ($compEnd > $endDate) {
            $compEnd = $endDate;
        }
        try {
            $rows = $builder->refresh($comp, $compStart, $compEnd);
            $step1Results[$comp] = ['rows' => $rows, 'start' => $compStart, 'end' => $compEnd];
            $step1Rows += $rows;
        } catch (\Throwable $e) {
            $step1Error = $e->getMessage();
            $step1Results[$comp] = ['error' => $e->getMessage()];
            $logger->error('geo_rebuild_period: CustomerPeriodMartBuilder falhou.', [
                'competencia' => $comp, 'message' => $e->getMessage(),
            ]);
        }
    }
}

// ── Step 2: GeoBuyerMartBuilder ───────────────────────────────────────────────

$step2Rows  = 0;
$step2Error = null;

if (!$dryRun && $step1Error === null) {
    try {
        $geoBuyer = new GeoBuyerMartBuilder($pdo, $logger);
        $step2Rows = $geoBuyer->refresh($startDate, $endDate);
    } catch (\Throwable $e) {
        $step2Error = $e->getMessage();
        $logger->error('geo_rebuild_period: GeoBuyerMartBuilder falhou.', ['message' => $e->getMessage()]);
    }
}

$finishedAt = microtime(true);
$durationMs = (int) round(($finishedAt - $startedAt) * 1000);

// ── After-counts ──────────────────────────────────────────────────────────────

$after = [
    'mart_cliente_periodo_total'          => countRows($pdo, 'mart_cliente_periodo'),
    'mart_cliente_periodo_period'         => countForPeriod($pdo, 'mart_cliente_periodo', 'competencia', substr($startDate, 0, 7), substr($endDate, 0, 7)),
    'mart_compradores_por_geo_total'      => countRows($pdo, 'mart_compradores_por_geo'),
    'mart_compradores_por_geo_period'     => countForPeriod($pdo, 'mart_compradores_por_geo', 'competencia', substr($startDate, 0, 7), substr($endDate, 0, 7)),
];

// NFs + faturamento in geo mart for period
$geoFat = 0.0;
$geoBairros = 0;
try {
    $gs = $pdo->prepare(
        "SELECT COALESCE(SUM(faturamento), 0) AS fat,
                COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' AND UPPER(bairro_norm) != 'NAO INFORMADO'
                                    THEN bairro_norm END) AS bairros
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :sc AND :ec",
    );
    $gs->execute(['sc' => substr($startDate, 0, 7), 'ec' => substr($endDate, 0, 7)]);
    $gr = $gs->fetch(PDO::FETCH_ASSOC) ?: [];
    $geoFat    = (float) ($gr['fat']    ?? 0);
    $geoBairros = (int)  ($gr['bairros'] ?? 0);
} catch (\Throwable) {}

// ── Pipeline job complete ─────────────────────────────────────────────────────

$hasError = $step1Error !== null || $step2Error !== null;
$counts = [
    'mart_cliente_periodo' => $step1Rows,
    'mart_compradores_por_geo' => $step2Rows,
];

if ($pipelineRepo && $jobId > 0) {
    if ($hasError) {
        $pipelineRepo->failJob($jobId, $step1Error ?? $step2Error ?? 'unknown');
    } else {
        $pipelineRepo->completeJob($jobId, $counts, $durationMs / 1000);
    }
}

// ── Build result ──────────────────────────────────────────────────────────────

$ok = !$hasError && !$dryRun;
$result = [
    'ok'          => $ok,
    'dry_run'     => $dryRun,
    'period'      => ['start' => $startDate, 'end' => $endDate, 'competencias' => $competencias],
    'started_at'  => $startedAtTs,
    'finished_at' => date('c'),
    'duration_ms' => $durationMs,
    'canonical' => [
        'nfs_autorizadas'      => $canonicalNfs,
        'valor_mercadorias'    => $canonicalMerc,
    ],
    'steps' => [
        'step1_mart_cliente_periodo' => [
            'ok'         => $step1Error === null,
            'rows_upserted' => $step1Rows,
            'per_comp'   => $step1Results,
            'error'      => $step1Error,
        ],
        'step2_mart_compradores_por_geo' => [
            'ok'         => $step2Error === null,
            'rows_upserted' => $step2Rows,
            'error'      => $step2Error,
        ],
    ],
    'before' => $before,
    'after'  => $after,
    'geo_result' => [
        'faturamento'    => $geoFat,
        'bairros_distintos' => $geoBairros,
    ],
    'log' => [
        'job_id'   => $jobId ?: null,
        'pipeline' => $hasError ? 'failed' : ($dryRun ? 'dry_run' : 'completed'),
    ],
];

// ── Assert (optional) ─────────────────────────────────────────────────────────

$assertResult = null;
if ($doAssert && !$dryRun && !$hasError) {
    $assertOut = [];
    $assertCode = null;
    exec(
        'php ' . escapeshellarg($root . '/tools/dev/geo_sales_assert_may2026.php')
        . ' --start=' . escapeshellarg($startDate)
        . ' --end=' . escapeshellarg($endDate)
        . ' --json',
        $assertOut,
        $assertCode
    );
    $assertJson = implode('', $assertOut);
    $assertData = json_decode($assertJson, true);
    $assertResult = [
        'passed'     => $assertCode === 0,
        'exit_code'  => $assertCode,
        'assertions' => $assertData,
    ];
    $result['assert'] = $assertResult;
    if ($assertCode !== 0) {
        $result['ok'] = false;
    }
}

$logger->info('geo_rebuild_period: concluido.', [
    'start' => $startDate, 'end' => $endDate,
    'step1_rows' => $step1Rows, 'step2_rows' => $step2Rows,
    'faturamento' => $geoFat, 'bairros' => $geoBairros,
    'duration_ms' => $durationMs, 'ok' => $result['ok'],
]);

// ── Output ────────────────────────────────────────────────────────────────────

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['ok'] ? 0 : 1);
}

echo "\n=== GEO REBUILD PERIOD — {$startDate} a {$endDate} ===\n";
if ($dryRun) echo "[DRY RUN — nenhuma alteração feita]\n";
echo "Competências: " . implode(', ', $competencias) . "\n";
echo "Tempo: {$durationMs}ms\n\n";

echo "── Canonical (fonte de verdade)\n";
echo "   NFs autorizadas: {$canonicalNfs}\n";
echo "   Valor mercadorias: R$" . number_format($canonicalMerc, 2, ',', '.') . "\n\n";

echo "── Step 1: mart_cliente_periodo\n";
echo "   Rows upserted: {$step1Rows}\n";
if ($step1Error) echo "   ERRO: {$step1Error}\n";
foreach ($step1Results as $comp => $r) {
    echo "   {$comp}: " . (isset($r['error']) ? 'ERRO — ' . $r['error'] : $r['rows'] . " rows") . "\n";
}
echo "   Antes (período): " . $before['mart_cliente_periodo_period'] . " → Depois: " . $after['mart_cliente_periodo_period'] . "\n\n";

echo "── Step 2: mart_compradores_por_geo\n";
echo "   Rows upserted: {$step2Rows}\n";
if ($step2Error) echo "   ERRO: {$step2Error}\n";
echo "   Antes (período): " . $before['mart_compradores_por_geo_period'] . " → Depois: " . $after['mart_compradores_por_geo_period'] . "\n";
echo "   Faturamento no período: R$" . number_format($geoFat, 2, ',', '.') . "\n";
echo "   Bairros distintos: {$geoBairros}\n\n";

if ($assertResult !== null) {
    echo "── Assert\n";
    echo "   " . ($assertResult['passed'] ? 'PASSOU' : 'FALHOU') . "\n";
}

echo "RESULTADO: " . ($result['ok'] ? 'OK' : 'FALHOU') . "\n\n";
exit($result['ok'] ? 0 : 1);
