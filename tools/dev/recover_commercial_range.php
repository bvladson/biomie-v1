<?php

declare(strict_types=1);

/**
 * tools/dev/recover_commercial_range.php
 *
 * Diagnóstico + dry-run para recuperação de dados comerciais em um intervalo de datas.
 *
 * Modos:
 *   --dry-run (PADRÃO) — mostra o que SERIA feito, sem executar nada
 *   --apply            — executa as ações sequencialmente via proc_open (CUIDADO em produção)
 *
 * Usage:
 *   php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-05-29
 *   php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-05-29 --dry-run --pretty
 *   php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-05-29 --apply --domains=commercial,gold
 *   php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-05-29 --json
 *
 * Exit codes:
 *   0 — nothing to do or all actions succeeded
 *   1 — gaps found (dry-run) or some actions failed (apply)
 *   2 — DB connection error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI params ────────────────────────────────────────────────────────────────

$opts       = getopt('', ['start:', 'end:', 'domains:', 'dry-run', 'apply', 'pretty', 'json']);
$startDate  = isset($opts['start'])   ? trim((string) $opts['start'])   : date('Y-01-01');
$endDate    = isset($opts['end'])     ? trim((string) $opts['end'])     : date('Y-m-d');
$domainsRaw = isset($opts['domains']) ? trim((string) $opts['domains']) : 'commercial,fiscal,items,gold';
$applyMode  = isset($opts['apply']);
$isJson     = isset($opts['json']);
$isPretty   = isset($opts['pretty']);
$isDryRun   = !$applyMode;   // dry-run is the default

$domains = array_map('trim', explode(',', $domainsRaw));

$root = dirname(__DIR__, 2);
$php  = PHP_BINARY;

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function rcrTableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute([':t' => $table]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Build list of YYYY-MM strings covering [startDate .. endDate].
 *
 * @return list<string>
 */
function rcrBuildMonths(string $startDate, string $endDate): array
{
    $months = [];
    $cursor = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $endDt  = new DateTimeImmutable(substr($endDate, 0, 7) . '-01');
    while ($cursor <= $endDt) {
        $months[] = $cursor->format('Y-m');
        $cursor   = $cursor->modify('+1 month');
    }
    return $months;
}

/**
 * Coverage check for one month — same logic as commercial_monthly_coverage_audit.
 *
 * @return array<string,mixed>
 */
function rcrMonthCoverage(PDO $pdo, string $ym, bool $hasClientMart, bool $hasProdMart): array
{
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));

    // NF count
    $nfCount = 0;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND operation_scope = 'commercial'
               AND COALESCE(is_biomie_commercial_visible, 1) = 1
               AND COALESCE(data_competencia, data_emissao) BETWEEN :first AND :last"
        );
        $st->execute([':first' => $first, ':last' => $last]);
        $nfCount = (int) $st->fetchColumn();
    } catch (\Throwable) {
        $nfCount = -1;
    }

    // Mart cliente status
    $martClienteStatus = 'not_checked';
    $martClienteRows   = 0;
    if ($hasClientMart) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM mart_cliente_periodo WHERE competencia = :ym'
            );
            $st->execute([':ym' => $ym]);
            $martClienteRows   = (int) $st->fetchColumn();
            $martClienteStatus = $martClienteRows > 0 ? 'ok' : 'empty';
        } catch (\Throwable) {
            $martClienteStatus = 'error';
        }
    } else {
        $martClienteStatus = 'not_found';
    }

    // Mart produto status
    $martProdutoStatus = 'not_checked';
    if ($hasProdMart) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM mart_produto_periodo WHERE competencia = :ym'
            );
            $st->execute([':ym' => $ym]);
            $martProdRows      = (int) $st->fetchColumn();
            $martProdutoStatus = $martProdRows > 0 ? 'ok' : 'empty';
        } catch (\Throwable) {
            $martProdutoStatus = 'error';
        }
    } else {
        $martProdutoStatus = 'not_found';
    }

    // Determine status
    $hasNf   = $nfCount > 0;
    $hasMart = in_array($martClienteStatus, ['ok'], true);

    $recommendation = 'ok';
    if (!$hasNf && !$hasMart) {
        $recommendation = 'no_data';
    } elseif ($hasNf && !$hasMart) {
        $recommendation = 'materialize_needed';
    } elseif (!$hasNf && $hasMart) {
        $recommendation = 'backfill_needed';
    }

    return [
        'month'               => $ym,
        'canonical_nfs'       => $nfCount,
        'mart_cliente_status' => $martClienteStatus,
        'mart_produto_status' => $martProdutoStatus,
        'recommendation'      => $recommendation,
    ];
}

/**
 * Build list of recovery actions for a month based on its coverage status.
 *
 * @param  array<string,mixed> $cov
 * @param  list<string>        $domains
 * @return list<array<string,mixed>>
 */
function rcrBuildActions(array $cov, array $domains, string $root, string $php): array
{
    $ym  = $cov['month'];
    $rec = $cov['recommendation'];
    $acts = [];

    // First day / last day of month
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));

    if ($rec === 'no_data' || $rec === 'backfill_needed') {
        // Need to backfill commercial data from Omie
        $domainsArg = implode(',', array_filter($domains, fn($d) => $d !== 'gold'));
        $acts[] = [
            'type'        => 'backfill',
            'description' => "Backfill commercial data for {$ym}",
            'cmd'         => sprintf(
                '%s %s --domains=%s --mode=backfill --start=%s --end=%s --json',
                escapeshellarg($php),
                escapeshellarg($root . '/cron/sync_realtime_pipeline.php'),
                escapeshellarg($domainsArg),
                escapeshellarg($first),
                escapeshellarg($last)
            ),
        ];
    }

    if ($rec === 'materialize_needed' || $rec === 'backfill_needed' || $rec === 'no_data') {
        // Materialize Gold marts
        if (in_array('gold', $domains, true) || in_array('commercial', $domains, true)) {
            $acts[] = [
                'type'        => 'materialize',
                'description' => "Materialize Gold marts for {$ym}",
                'cmd'         => sprintf(
                    '%s %s --months=%s --force --json',
                    escapeshellarg($php),
                    escapeshellarg($root . '/bin/materialize_marts.php'),
                    escapeshellarg($ym)
                ),
            ];
        }
    }

    if ($rec !== 'ok' && $rec !== 'no_data') {
        // Re-run daily integrity for the last day of the month
        $acts[] = [
            'type'        => 'integrity',
            'description' => "Re-run daily integrity check for {$last}",
            'cmd'         => sprintf(
                '%s %s --date=%s --json',
                escapeshellarg($php),
                escapeshellarg($root . '/tools/dev/daily_sales_integrity_check.php'),
                escapeshellarg($last)
            ),
        ];
    }

    return $acts;
}

/**
 * Execute a single command via proc_open and return result.
 *
 * @return array{exit:int,stdout:string,stderr:string,duration_ms:int}
 */
function rcrRunCommand(string $cmd): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $t0      = microtime(true);
    $process = proc_open($cmd, $desc, $pipes);

    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'proc_open failed', 'duration_ms' => 0];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit'        => $exit,
        'stdout'      => trim($stdout),
        'stderr'      => trim($stderr),
        'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
    ];
}

// ── Coverage analysis ─────────────────────────────────────────────────────────

$months       = rcrBuildMonths($startDate, $endDate);
$hasClientMart = rcrTableExists($pdo, 'mart_cliente_periodo');
$hasProdMart   = rcrTableExists($pdo, 'mart_produto_periodo');

$monthResults = [];
$allActions   = [];

foreach ($months as $ym) {
    $cov     = rcrMonthCoverage($pdo, $ym, $hasClientMart, $hasProdMart);
    $actions = rcrBuildActions($cov, $domains, $root, $php);

    $monthResults[] = array_merge($cov, ['actions' => $actions]);
    foreach ($actions as $act) {
        $allActions[] = array_merge($act, ['month' => $ym]);
    }
}

$totalActions = count($allActions);
$exitCode     = $totalActions > 0 ? 1 : 0;

// ── Apply mode — execute ──────────────────────────────────────────────────────

$applyLog    = null;
$applyErrors = 0;

if ($applyMode) {
    $logDir  = $root . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/recover_commercial_range_' . date('Ymd_His') . '.json';

    $applyLog = [
        'tool'        => 'recover_commercial_range',
        'mode'        => 'apply',
        'started_at'  => date('c'),
        'start'       => $startDate,
        'end'         => $endDate,
        'domains'     => $domains,
        'steps'       => [],
    ];

    foreach ($allActions as $idx => $act) {
        $stepNum = $idx + 1;
        $result  = rcrRunCommand($act['cmd']);

        $step = [
            'step'        => $stepNum,
            'month'       => $act['month'],
            'type'        => $act['type'],
            'description' => $act['description'],
            'cmd'         => $act['cmd'],
            'exit_code'   => $result['exit'],
            'stdout'      => mb_substr($result['stdout'], 0, 4000),
            'stderr'      => mb_substr($result['stderr'], 0, 2000),
            'duration_ms' => $result['duration_ms'],
            'status'      => $result['exit'] === 0 ? 'success' : 'failed',
        ];

        $applyLog['steps'][] = $step;

        if ($result['exit'] !== 0) {
            $applyErrors++;
        }
    }

    $applyLog['finished_at'] = date('c');
    $applyLog['errors']      = $applyErrors;
    $applyLog['all_ok']      = $applyErrors === 0;

    file_put_contents($logFile, json_encode($applyLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $exitCode = $applyErrors > 0 ? 1 : 0;
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    $out = [
        'tool'          => 'recover_commercial_range',
        'mode'          => $isDryRun ? 'dry_run' : 'apply',
        'start'         => $startDate,
        'end'           => $endDate,
        'domains'       => $domains,
        'months'        => $monthResults,
        'total_actions' => $totalActions,
    ];

    if ($applyMode && $applyLog !== null) {
        $out['apply_log'] = $applyLog;
        $out['errors']    = $applyErrors;
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

// Plain / pretty
$modeLabel = $isDryRun ? '(DRY-RUN)' : '(APPLY)';
echo "=== recover_commercial_range {$modeLabel} ===\n";
echo "Range: {$startDate} to {$endDate}\n";
echo "Domains: " . implode(', ', $domains) . "\n\n";

if ($applyMode) {
    echo str_repeat('!', 80) . "\n";
    echo "!! WARNING: APPLY MODE — actions are being executed on the database      !!\n";
    echo "!! Only use in production with explicit authorization                    !!\n";
    echo str_repeat('!', 80) . "\n\n";
}

foreach ($monthResults as $mr) {
    $ym    = $mr['month'];
    $nfs   = $mr['canonical_nfs'];
    $rec   = $mr['recommendation'];
    $mcs   = strtoupper($mr['mart_cliente_status']);
    $acts  = $mr['actions'];

    $recLabel = match ($rec) {
        'ok'                 => '[OK]',
        'backfill_needed'    => '[backfill_needed]',
        'materialize_needed' => '[materialize_needed]',
        default              => '[no_data]',
    };

    $nfsLabel = $nfs >= 0 ? (string) $nfs : '?';

    printf(
        "Month %s:\n  NFs canônicas: %-5s | Mart cliente: %s | Status: %s\n",
        $ym,
        $nfsLabel,
        $mcs,
        $rec
    );

    if (empty($acts)) {
        echo "  Nenhuma ação necessária.\n";
    } else {
        foreach ($acts as $act) {
            if ($applyMode && $applyLog !== null) {
                // Find result in log
                $stepResult = null;
                foreach ($applyLog['steps'] as $step) {
                    if ($step['month'] === $ym && $step['type'] === $act['type']) {
                        $stepResult = $step;
                        break;
                    }
                }
                if ($stepResult !== null) {
                    $statusIcon = $stepResult['exit_code'] === 0 ? '[OK]' : '[FAILED]';
                    printf(
                        "  RAN (%s): %s\n       Duration: %dms\n",
                        $statusIcon,
                        $act['description'],
                        $stepResult['duration_ms']
                    );
                    if ($stepResult['exit_code'] !== 0 && $stepResult['stderr'] !== '') {
                        printf("       STDERR: %s\n", mb_substr($stepResult['stderr'], 0, 200));
                    }
                } else {
                    printf("  RAN: %s\n", $act['description']);
                }
            } else {
                printf(
                    "  WOULD RUN: %s\n",
                    $act['cmd']
                );
            }
        }
    }

    echo "\n";
}

if ($isDryRun) {
    echo "Total actions needed: {$totalActions}\n";
    if ($totalActions > 0) {
        echo "Run with --apply to execute (use with caution in production).\n";
    } else {
        echo "All months are up-to-date. No recovery actions needed.\n";
    }
} else {
    echo "Apply complete.\n";
    echo "Total actions: {$totalActions} | Errors: {$applyErrors}\n";
    if ($applyLog !== null) {
        $logDir  = $root . '/storage/logs';
        echo "Log saved to storage/logs/ (recover_commercial_range_*.json)\n";
    }
}

exit($exitCode);
