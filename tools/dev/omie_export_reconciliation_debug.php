<?php

declare(strict_types=1);

/**
 * Reconciliação BIomie vs planilha Omie exportada.
 *
 * Compara os totais reais do banco (canonical NFs) com os valores esperados
 * importados manualmente da planilha Omie. Detecta se o faturamento exibido
 * corresponde ao bruto (todas as NFs, incluindo remessa) ou ao Orçamento-PD
 * (commercial limpo).
 *
 * Uso:
 *   php tools/dev/omie_export_reconciliation_debug.php \
 *     --start=2026-05-01 --end=2026-05-31 \
 *     --gross-nf=174 --gross-rev=484369.01 --gross-merch=476483.42 \
 *     --remit-nf=9   --remit-rev=76081.71  --remit-merch=73817.01 \
 *     --com-nf=165   --com-rev=408287.30   --com-merch=402666.41
 *
 *   # Somente bruto (sem comparar remessa/commercial separado):
 *   php tools/dev/omie_export_reconciliation_debug.php \
 *     --start=2026-05-01 --end=2026-05-31 \
 *     --gross-nf=174 --gross-rev=484369.01
 *
 *   # JSON:
 *   php tools/dev/omie_export_reconciliation_debug.php ... --json
 *
 * Saída de status:
 *   0 — OK (todos os valores dentro da tolerância)
 *   1 — WARNING/CRITICAL (divergência detectada)
 *   2 — Erro de configuração
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI args ───────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'start:', 'end:',
    'gross-nf:', 'gross-rev:', 'gross-merch:',
    'remit-nf:', 'remit-rev:', 'remit-merch:',
    'com-nf:',   'com-rev:',   'com-merch:',
    'json', 'pretty',
]);

$start     = isset($opts['start'])      ? trim((string) $opts['start'])      : null;
$end       = isset($opts['end'])        ? trim((string) $opts['end'])        : null;
$isJson    = isset($opts['json']);
$isPretty  = isset($opts['pretty']);

$grossNf    = isset($opts['gross-nf'])    ? (int)   $opts['gross-nf']    : null;
$grossRev   = isset($opts['gross-rev'])   ? (float) $opts['gross-rev']   : null;
$grossMerch = isset($opts['gross-merch']) ? (float) $opts['gross-merch'] : null;

$remitNf    = isset($opts['remit-nf'])    ? (int)   $opts['remit-nf']    : null;
$remitRev   = isset($opts['remit-rev'])   ? (float) $opts['remit-rev']   : null;
$remitMerch = isset($opts['remit-merch']) ? (float) $opts['remit-merch'] : null;

$comNf      = isset($opts['com-nf'])      ? (int)   $opts['com-nf']      : null;
$comRev     = isset($opts['com-rev'])     ? (float) $opts['com-rev']     : null;
$comMerch   = isset($opts['com-merch'])   ? (float) $opts['com-merch']   : null;

if ($start === null || $end === null) {
    fwrite(STDERR, "ERRO: --start e --end são obrigatórios.\n");
    fwrite(STDERR, "Exemplo: php " . basename(__FILE__) . " --start=2026-05-01 --end=2026-05-31 --gross-rev=484369.01\n");
    exit(2);
}

// ── DB ─────────────────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco não configurado.\n");
    exit(2);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Helpers ────────────────────────────────────────────────────────────────────

const RECON_TOLERANCE_VALUE = 1.00;    // R$ 1,00 para valores
const RECON_TOLERANCE_COUNT = 0;       // 0 NFs de tolerância

function recon_fmt(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function recon_check(
    string $name,
    ?float $expected,
    float  $actual,
    float  $tolerance = RECON_TOLERANCE_VALUE,
    bool   $isCount   = false
): array {
    if ($expected === null) {
        return ['name' => $name, 'status' => 'SKIP', 'expected' => null, 'actual' => $actual, 'delta' => null, 'note' => 'não informado via parâmetro'];
    }

    $delta     = round($actual - $expected, 2);
    $absDelta  = abs($delta);
    $threshold = $isCount ? RECON_TOLERANCE_COUNT : $tolerance;

    if ($absDelta <= $threshold) {
        $status = 'OK';
    } else {
        // CRITICAL: BIomie mostra valor correspondente ao bruto quando deveria ser commercial
        $status = 'CRITICAL';
    }

    return [
        'name'     => $name,
        'status'   => $status,
        'expected' => $expected,
        'actual'   => $actual,
        'delta'    => $delta,
        'note'     => null,
    ];
}

// ── Query canônica por escopo ──────────────────────────────────────────────────

$scopeStmt = $pdo->prepare("
    SELECT
        operation_scope,
        COUNT(*)                                AS nf_count,
        COALESCE(SUM(valor_total),        0)    AS revenue,
        COALESCE(SUM(valor_mercadorias),  0)    AS merchandise,
        COALESCE(SUM(valor_descontos),    0)    AS discount
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
    GROUP BY operation_scope
");
$scopeStmt->execute(['start' => $start, 'end' => $end]);
$scopeRows = $scopeStmt->fetchAll(\PDO::FETCH_ASSOC);

$byScope = [];
foreach ($scopeRows as $row) {
    $scope           = (string) $row['operation_scope'];
    $byScope[$scope] = [
        'nf_count'    => (int)   $row['nf_count'],
        'revenue'     => (float) $row['revenue'],
        'merchandise' => (float) $row['merchandise'],
        'discount'    => (float) $row['discount'],
    ];
}

$dbCommercial = $byScope['commercial'] ?? ['nf_count' => 0, 'revenue' => 0.0, 'merchandise' => 0.0, 'discount' => 0.0];
$dbRemittance = $byScope['remittance'] ?? ['nf_count' => 0, 'revenue' => 0.0, 'merchandise' => 0.0, 'discount' => 0.0];
$dbUnknown    = $byScope['unknown']    ?? ['nf_count' => 0, 'revenue' => 0.0, 'merchandise' => 0.0, 'discount' => 0.0];

$dbGrossNf    = array_sum(array_column($byScope, 'nf_count'));
$dbGrossRev   = array_sum(array_column($byScope, 'revenue'));
$dbGrossMerch = array_sum(array_column($byScope, 'merchandise'));

// ── Checks ─────────────────────────────────────────────────────────────────────

$checks = [];

// 1. Bruto (todos os escopos somados)
$checks[] = recon_check('gross.nf_count',    $grossNf,    (float) $dbGrossNf,    0, true);
$checks[] = recon_check('gross.revenue',     $grossRev,   $dbGrossRev);
$checks[] = recon_check('gross.merchandise', $grossMerch, $dbGrossMerch);

// 2. Remessa
$checks[] = recon_check('remittance.nf_count',    $remitNf,    (float) $dbRemittance['nf_count'], 0, true);
$checks[] = recon_check('remittance.revenue',     $remitRev,   $dbRemittance['revenue']);
$checks[] = recon_check('remittance.merchandise', $remitMerch, $dbRemittance['merchandise']);

// 3. Orçamento-PD (commercial)
$checks[] = recon_check('commercial.nf_count',    $comNf,    (float) $dbCommercial['nf_count'], 0, true);
$checks[] = recon_check('commercial.revenue',     $comRev,   $dbCommercial['revenue']);
$checks[] = recon_check('commercial.merchandise', $comMerch, $dbCommercial['merchandise']);

// 4. Guard: detecta se BIomie mostraria bruto como commercial
$dashboardDeltaRev   = round($dbCommercial['revenue']     - ($grossRev   ?? 0.0), 2);
$dashboardDeltaMerch = round($dbCommercial['merchandise'] - ($grossMerch ?? 0.0), 2);
$grossInCommercial   = $grossRev !== null && abs($dashboardDeltaRev) < 1.00;

$checks[] = [
    'name'     => 'guard.commercial_not_showing_gross',
    'status'   => $grossInCommercial ? 'CRITICAL' : 'OK',
    'expected' => $comRev,
    'actual'   => $dbCommercial['revenue'],
    'delta'    => $comRev !== null ? round($dbCommercial['revenue'] - $comRev, 2) : null,
    'note'     => $grossInCommercial
        ? 'ATENÇÃO: receita commercial bate com bruto — remessa NÃO está sendo excluída do Dashboard!'
        : null,
];

// ── Summary ────────────────────────────────────────────────────────────────────

$criticals = array_filter($checks, static fn($c) => $c['status'] === 'CRITICAL');
$skipped   = array_filter($checks, static fn($c) => $c['status'] === 'SKIP');
$clean     = count($criticals) === 0;

$output = [
    'date_filter'  => "start={$start} end={$end}",
    'db_actuals'   => [
        'gross'      => ['nf_count' => $dbGrossNf,    'revenue' => round($dbGrossRev, 2),   'merchandise' => round($dbGrossMerch, 2)],
        'commercial' => ['nf_count' => $dbCommercial['nf_count'], 'revenue' => round($dbCommercial['revenue'], 2), 'merchandise' => round($dbCommercial['merchandise'], 2)],
        'remittance' => ['nf_count' => $dbRemittance['nf_count'], 'revenue' => round($dbRemittance['revenue'], 2), 'merchandise' => round($dbRemittance['merchandise'], 2)],
        'unknown'    => ['nf_count' => $dbUnknown['nf_count'],    'revenue' => round($dbUnknown['revenue'],    2)],
    ],
    'scope_breakdown' => $byScope,
    'checks'       => $checks,
    'criticals'    => count($criticals),
    'skipped'      => count($skipped),
    'clean'        => $clean,
];

if ($isJson) {
    $flags = JSON_UNESCAPED_UNICODE;
    if ($isPretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($output, $flags) . "\n";
    exit($clean ? 0 : 1);
}

// ── Text output ────────────────────────────────────────────────────────────────

echo "\n=== omie_export_reconciliation_debug ===\n";
echo "Período: {$start} → {$end}\n\n";

echo "BANCO (canonical NFs, by scope):\n";
printf("  Bruto (todas escopos)  : %3d NFs  %s  merch %s\n",
    $dbGrossNf, recon_fmt($dbGrossRev), recon_fmt($dbGrossMerch));
printf("  Commercial (Orç-PD)    : %3d NFs  %s  merch %s\n",
    $dbCommercial['nf_count'], recon_fmt($dbCommercial['revenue']), recon_fmt($dbCommercial['merchandise']));
printf("  Remittance             : %3d NFs  %s  merch %s\n",
    $dbRemittance['nf_count'], recon_fmt($dbRemittance['revenue']), recon_fmt($dbRemittance['merchandise']));
printf("  Unknown                : %3d NFs  %s\n",
    $dbUnknown['nf_count'], recon_fmt($dbUnknown['revenue']));

echo "\nCOMPARAÇÃO vs PLANILHA OMIE:\n";
foreach ($checks as $c) {
    if ($c['status'] === 'SKIP') {
        continue;
    }
    $icon = match ($c['status']) {
        'OK'       => '[OK  ]',
        'CRITICAL' => '[CRIT]',
        default    => '[?   ]',
    };
    $line = sprintf('%s %s', $icon, str_pad($c['name'], 40));
    if ($c['expected'] !== null && $c['actual'] !== null) {
        $isCountField = str_ends_with($c['name'], 'nf_count');
        if ($isCountField) {
            $line .= sprintf('  esperado=%d  real=%d  delta=%+d',
                (int) $c['expected'], (int) $c['actual'], (int) ($c['delta'] ?? 0));
        } else {
            $line .= sprintf('  esperado=%s  real=%s  delta=%s%s',
                recon_fmt((float) $c['expected']),
                recon_fmt((float) $c['actual']),
                ($c['delta'] ?? 0) >= 0 ? '+' : '',
                recon_fmt(abs((float) ($c['delta'] ?? 0))));
        }
    }
    echo $line . "\n";
    if ($c['note'] !== null) {
        echo "       >>> {$c['note']}\n";
    }
}

echo "\n";
if ($clean) {
    echo "STATUS: OK — BIomie bate com a planilha Omie exportada.\n\n";
    exit(0);
} else {
    printf("STATUS: CRITICAL — %d divergência(s) detectada(s). Veja detalhe acima.\n\n", count($criticals));
    exit(1);
}
