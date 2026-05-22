<?php

declare(strict_types=1);

/**
 * Fase I — Recalibração Comercial Pós-Escopo.
 *
 * Compara todas as fontes de KPI comercial contra os canonical NFs
 * (operation_scope = 'commercial') para detectar contaminação por
 * remessa/unknown ou divergências de valor entre marts.
 *
 * Uso:
 *   php tools/dev/commercial_recalibration_audit.php
 *   php tools/dev/commercial_recalibration_audit.php --start=2026-01-01 --end=2026-05-31
 *   php tools/dev/commercial_recalibration_audit.php --json
 *   php tools/dev/commercial_recalibration_audit.php --json --pretty
 *
 * Saída:
 *   0 — todos os marts batem com a fonte canônica (dentro da tolerância)
 *   1 — ao menos uma fonte diverge ou contém remessa/unknown
 *   2 — erro de configuração ou banco
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['json', 'pretty', 'start:', 'end:']);
$isJson    = isset($opts['json']);
$isPretty  = isset($opts['pretty']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : null;
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : null;

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(2);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Helpers ────────────────────────────────────────────────────────────────────

function rcaColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function rcaTableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function rcaFmt(float $val): string
{
    return 'R$ ' . number_format($val, 2, ',', '.');
}

const RCA_DELTA_TOLERANCE = 0.01;

// ── Migration guard ────────────────────────────────────────────────────────────

$nfHasScope  = rcaColExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');
$ordHasScope = rcaColExists($pdo, 'biomie_pedidos_canonicos', 'operation_scope');

// Fase O.1 fiscal basis columns
$nfHasFiscalBasis = rcaColExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_base_comercial_nf');
$nfHasFrete       = rcaColExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_frete');

if ($nfHasFiscalBasis) {
    $baseComercialExpr = 'COALESCE(nf.valor_base_comercial_nf, '
        . 'nf.valor_total - COALESCE(nf.valor_frete,0) - COALESCE(nf.valor_outras_despesas,0) - COALESCE(nf.valor_seguro,0))';
} elseif ($nfHasFrete) {
    $baseComercialExpr = 'nf.valor_total - COALESCE(nf.valor_frete,0) '
        . '- COALESCE(nf.valor_outras_despesas,0) - COALESCE(nf.valor_seguro,0)';
} else {
    $baseComercialExpr = 'nf.valor_total';
}

if (!$nfHasScope) {
    $msg = 'Migration H3 nao aplicada: operation_scope ausente em biomie_notas_fiscais_canonicas.';
    if ($isJson) {
        echo json_encode(['error' => $msg, 'migration_h3_applied' => false]) . "\n";
    } else {
        echo "ERRO: $msg\n";
    }
    exit(2);
}

// ── Date filter helpers ────────────────────────────────────────────────────────

// For canonical tables (use daily date column)
$nfDateFilter = '';
$nfDateParams = [];
if ($startDate !== null) {
    $nfDateFilter .= ' AND COALESCE(nf.data_competencia, nf.data_emissao) >= :start_date';
    $nfDateParams[':start_date'] = $startDate;
}
if ($endDate !== null) {
    $nfDateFilter .= ' AND COALESCE(nf.data_competencia, nf.data_emissao) <= :end_date';
    $nfDateParams[':end_date'] = $endDate;
}

// For mart tables (use competencia YYYY-MM)
$compStart = $startDate !== null ? substr($startDate, 0, 7) : null;
$compEnd   = $endDate   !== null ? substr($endDate, 0, 7)   : null;

$martDateFilter = '';
$martDateParams = [];
if ($compStart !== null) {
    $martDateFilter .= ' AND competencia >= :comp_start';
    $martDateParams[':comp_start'] = $compStart;
}
if ($compEnd !== null) {
    $martDateFilter .= ' AND competencia <= :comp_end';
    $martDateParams[':comp_end'] = $compEnd;
}

$dateLabel = [];
if ($startDate !== null) { $dateLabel[] = "start={$startDate}"; }
if ($endDate   !== null) { $dateLabel[] = "end={$endDate}"; }
$dateLabel = $dateLabel !== [] ? implode(', ', $dateLabel) : 'all-time';

// ── Source-of-truth: canonical commercial NFs ──────────────────────────────────

$stmtCanon = $pdo->prepare("
    SELECT
        COUNT(*)                                AS nf_count,
        COALESCE(SUM(nf.valor_total),       0)  AS revenue,
        COALESCE(SUM({$baseComercialExpr}), 0)  AS base_comercial,
        COALESCE(SUM(nf.valor_mercadorias), 0)  AS merchandise,
        COALESCE(SUM(nf.valor_descontos),   0)  AS discount,
        MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS date_min,
        MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS date_max
    FROM biomie_notas_fiscais_canonicas nf
    WHERE nf.status_fiscal = 'autorizada'
      AND nf.operation_scope = 'commercial'
      {$nfDateFilter}
");
$stmtCanon->execute($nfDateParams);
$canon = $stmtCanon->fetch(\PDO::FETCH_ASSOC);

$canonRevenue          = (float) ($canon['revenue']       ?? 0);
$canonBaseComercial    = (float) ($canon['base_comercial'] ?? $canonRevenue);
$canonNaoComissionavel = max(0.0, round($canonRevenue - $canonBaseComercial, 2));
$canonMerchandise      = (float) ($canon['merchandise']   ?? 0);
$canonNfCount          = (int)   ($canon['nf_count']      ?? 0);
$canonDiscount         = (float) ($canon['discount']      ?? 0);

// Scope breakdown (all authorized NFs)
$stmtScope = $pdo->query("
    SELECT operation_scope,
           COUNT(*) AS n,
           COALESCE(SUM(valor_total), 0) AS revenue
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
    GROUP BY operation_scope
");
$nfScopeBreakdown = [];
foreach ($stmtScope->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $nfScopeBreakdown[(string)$row['operation_scope']] = [
        'n'       => (int)   $row['n'],
        'revenue' => (float) $row['revenue'],
    ];
}

$remitRevenue  = (float) ($nfScopeBreakdown['remittance']['revenue'] ?? 0);
$remitCount    = (int)   ($nfScopeBreakdown['remittance']['n']       ?? 0);
$unknownCount  = (int)   ($nfScopeBreakdown['unknown']['n']          ?? 0);
$unknownRevenue = (float) ($nfScopeBreakdown['unknown']['revenue']   ?? 0);

$totalNfAuth   = array_sum(array_column($nfScopeBreakdown, 'n'));
$unknownPct    = $totalNfAuth > 0 ? round($unknownCount / $totalNfAuth * 100, 1) : 0.0;

// ── Checks registry ────────────────────────────────────────────────────────────

/** @var list<array<string,mixed>> */
$checks   = [];
$failures = 0;

$addCheck = function (
    string  $name,
    string  $description,
    float   $expected,
    ?float  $actual,
    string  $field,
    ?string $error       = null,
    ?string $note        = null,
    bool    $intentional = false,
) use (&$checks, &$failures): void {
    if ($error !== null) {
        $checks[] = compact('name', 'description', 'field', 'error', 'note') + [
            'status'   => 'ERROR',
            'expected' => $expected,
            'actual'   => null,
            'delta'    => null,
        ];
        $failures++;
        return;
    }

    $delta = round(($actual ?? 0.0) - $expected, 2);
    if ($intentional) {
        $status = 'OK_INTENTIONAL';
    } elseif (abs($delta) <= RCA_DELTA_TOLERANCE) {
        $status = 'OK';
    } else {
        $status = 'FAIL';
        $failures++;
    }

    $checks[] = compact('name', 'description', 'field', 'status', 'note') + [
        'expected' => round($expected, 2),
        'actual'   => round($actual ?? 0.0, 2),
        'delta'    => $delta,
        'error'    => null,
    ];
};

// ── Check: canonical NF — no revenue from unknown scope ───────────────────────

$addCheck(
    'canonical_nf.no_unknown_revenue',
    'Nenhuma NF autorizada com operation_scope=unknown deve gerar receita',
    0.0,
    $unknownRevenue,
    'operation_scope',
    null,
    $unknownRevenue > 0 ? 'Backfill incompleto: NFs unknown ainda na base. Execute backfill_operation_scope.php --apply.' : null,
);

// ── Check: canonical NF — no revenue from remittance in commercial marts ───────

// Remittance revenue should be > 0 only in mart_fiscal_nfe.
// We do not check it here — we check its absence from commercial marts below.

// ── Check: mart_fiscal_nfe ─────────────────────────────────────────────────────
// Bridge table — intentionally includes all scopes.
// Expected = commercial + remittance.

try {
    if (rcaTableExists($pdo, 'mart_fiscal_nfe')) {
        $mfFilter = '';
        $mfParams = [];
        if ($startDate !== null) {
            $mfFilter .= ' AND COALESCE(data_competencia, data_emissao) >= :s';
            $mfParams[':s'] = $startDate;
        }
        if ($endDate !== null) {
            $mfFilter .= ' AND COALESCE(data_competencia, data_emissao) <= :e';
            $mfParams[':e'] = $endDate;
        }
        $stmtMf = $pdo->prepare("
            SELECT COALESCE(SUM(valor_total), 0) AS revenue, COUNT(*) AS n
            FROM mart_fiscal_nfe
            WHERE status_fiscal = 'autorizada' {$mfFilter}
        ");
        $stmtMf->execute($mfParams);
        $mf = $stmtMf->fetch(\PDO::FETCH_ASSOC);

        $addCheck(
            'mart_fiscal_nfe.revenue',
            'mart_fiscal_nfe.valor_total = commercial + remittance (bridge, todos os escopos)',
            round($canonRevenue + $remitRevenue, 2),
            (float) $mf['revenue'],
            'valor_total',
            null,
            'Inclui remessa intencionalmente. Delta esperado = remittance revenue.',
            true,
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_fiscal_nfe.revenue', 'mart_fiscal_nfe', 0.0, null, 'valor_total', $e->getMessage());
}

// ── Check: mart_produto_periodo ────────────────────────────────────────────────
// valor_total (item-level) should match canonical NF merchandise.

try {
    if (rcaTableExists($pdo, 'mart_produto_periodo')) {
        $stmtMp = $pdo->prepare("
            SELECT COALESCE(SUM(valor_total), 0) AS revenue, COUNT(DISTINCT codigo_produto) AS n
            FROM mart_produto_periodo
            WHERE 1=1 {$martDateFilter}
        ");
        $stmtMp->execute($martDateParams);
        $mp = $stmtMp->fetch(\PDO::FETCH_ASSOC);

        $addCheck(
            'mart_produto_periodo.valor_total',
            'mart_produto_periodo.valor_total = canonical commercial valor_mercadoria',
            $canonMerchandise,
            (float) $mp['revenue'],
            'valor_total',
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_produto_periodo.valor_total', 'mart_produto_periodo', 0.0, null, 'valor_total', $e->getMessage());
}

// ── Check: mart_cliente_periodo ────────────────────────────────────────────────

try {
    if (rcaTableExists($pdo, 'mart_cliente_periodo')) {
        $stmtMc = $pdo->prepare("
            SELECT COALESCE(SUM(valor_nfe), 0) AS revenue, COUNT(*) AS n
            FROM mart_cliente_periodo
            WHERE 1=1 {$martDateFilter}
        ");
        $stmtMc->execute($martDateParams);
        $mc = $stmtMc->fetch(\PDO::FETCH_ASSOC);

        $addCheck(
            'mart_cliente_periodo.valor_nfe',
            'mart_cliente_periodo.valor_nfe = canonical commercial base comercial (excl. frete/outras/seguro)',
            $canonBaseComercial,
            (float) $mc['revenue'],
            'valor_nfe',
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_cliente_periodo.valor_nfe', 'mart_cliente_periodo', 0.0, null, 'valor_nfe', $e->getMessage());
}

// ── Check: mart_vendedor_dia ───────────────────────────────────────────────────
// valor_nfe should equal canonical commercial revenue.
// Known issue: fiscal SQL section in VendorDayMartBuilder may include the
// remittance NF (codigo_vendedor_omie = '') if scope filter was missing.

try {
    if (rcaTableExists($pdo, 'mart_vendedor_dia')) {
        $stmtMv = $pdo->prepare("
            SELECT COALESCE(SUM(valor_nfe), 0) AS revenue, COUNT(*) AS n
            FROM mart_vendedor_dia
            WHERE 1=1 {$martDateFilter}
        ");
        $stmtMv->execute($martDateParams);
        $mv = $stmtMv->fetch(\PDO::FETCH_ASSOC);

        $mvRevenue = (float) $mv['revenue'];
        $mvDelta   = round($mvRevenue - $canonBaseComercial, 2);

        // Detect if delta matches known remittance NF value
        $remittanceNote = null;
        if ($remitRevenue > 0 && abs($mvDelta - $remitRevenue) < 0.02) {
            $remittanceNote = sprintf(
                'Delta de %s = valor da NF de remessa (omie:8843854672). ' .
                'VendorDayMartBuilder secao fiscal incluiu remessa. ' .
                'Rematerialize mart_vendedor_dia.',
                rcaFmt($mvDelta)
            );
        }

        $addCheck(
            'mart_vendedor_dia.valor_nfe',
            'mart_vendedor_dia.valor_nfe = canonical commercial base comercial (deve excluir remessa)',
            $canonBaseComercial,
            $mvRevenue,
            'valor_nfe',
            null,
            $remittanceNote,
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_vendedor_dia.valor_nfe', 'mart_vendedor_dia', 0.0, null, 'valor_nfe', $e->getMessage());
}

// ── Check: mart_compradores_por_geo ────────────────────────────────────────────

try {
    if (rcaTableExists($pdo, 'mart_compradores_por_geo')) {
        $stmtMg = $pdo->prepare("
            SELECT COALESCE(SUM(faturamento), 0) AS revenue, COUNT(*) AS n
            FROM mart_compradores_por_geo
            WHERE 1=1 {$martDateFilter}
        ");
        $stmtMg->execute($martDateParams);
        $mg = $stmtMg->fetch(\PDO::FETCH_ASSOC);

        $addCheck(
            'mart_compradores_por_geo.faturamento',
            'mart_compradores_por_geo.faturamento = canonical commercial base comercial (excl. frete/outras/seguro)',
            $canonBaseComercial,
            (float) $mg['revenue'],
            'faturamento',
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_compradores_por_geo.faturamento', 'mart_compradores_por_geo', 0.0, null, 'faturamento', $e->getMessage());
}

// ── Check: mart_vendas_por_cnae_cnpj ───────────────────────────────────────────
// Agrega por documento × competencia × CNAE → revenue naturally higher than canonical.
// Only check: absence of remittance rows (via scope column if present, or via join).

try {
    if (rcaTableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        if (rcaColExists($pdo, 'mart_vendas_por_cnae_cnpj', 'operation_scope')) {
            $stmtCv = $pdo->prepare("
                SELECT operation_scope, COUNT(*) AS n, COALESCE(SUM(valor_nfe), 0) AS revenue
                FROM mart_vendas_por_cnae_cnpj
                WHERE operation_scope != 'commercial'
                  {$martDateFilter}
                GROUP BY operation_scope
            ");
            $stmtCv->execute($martDateParams);
            $cvRows = $stmtCv->fetchAll(\PDO::FETCH_ASSOC);

            $cvContamRevenue = array_sum(array_column($cvRows, 'revenue'));
            $addCheck(
                'mart_vendas_por_cnae_cnpj.no_non_commercial',
                'mart_vendas_por_cnae_cnpj deve conter apenas operation_scope=commercial',
                0.0,
                (float) $cvContamRevenue,
                'operation_scope',
                null,
                $cvContamRevenue > 0 ? 'Remessa/unknown encontrado no mart CNAE: ' . json_encode($cvRows) : null,
            );
        } else {
            // Scope column absent — check only clients who appear in mart but have ONLY
            // remittance NFs (no commercial NFs). Clients with both types are fine.
            $stmtCv = $pdo->prepare("
                SELECT COUNT(*) AS contam_count
                FROM mart_vendas_por_cnae_cnpj cv
                WHERE cv.codigo_cliente_omie IN (
                    SELECT codigo_cliente_omie FROM biomie_notas_fiscais_canonicas
                    WHERE operation_scope = 'remittance' AND status_fiscal = 'autorizada'
                )
                AND cv.codigo_cliente_omie NOT IN (
                    SELECT codigo_cliente_omie FROM biomie_notas_fiscais_canonicas
                    WHERE operation_scope = 'commercial' AND status_fiscal = 'autorizada'
                )
            ");
            $stmtCv->execute();
            $cvContam = (int) $stmtCv->fetchColumn();

            $addCheck(
                'mart_vendas_por_cnae_cnpj.remittance_only_clients',
                'mart_vendas_por_cnae_cnpj: clientes que possuem apenas NF remessa (sem NF commercial)',
                0.0,
                (float) $cvContam,
                'operation_scope_join',
                null,
                $cvContam > 0 ? "$cvContam linha(s) no mart CNAE pertencentes a clientes sem nenhuma NF commercial — remessa pode ter entrado no mart." : null,
            );
        }
    }
} catch (\Throwable $e) {
    $addCheck('mart_vendas_por_cnae_cnpj.scope', 'mart_vendas_por_cnae_cnpj', 0.0, null, 'operation_scope', $e->getMessage());
}

// ── Check: mart_vendas_por_cnae_cnpj aggregate revenue ────────────────────────
// Revenue in the mart must cover canonical commercial (undershoot = incomplete materialization).

try {
    if (rcaTableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        $stmtCvAgg = $pdo->prepare("
            SELECT COALESCE(SUM(faturamento), 0) AS revenue, COUNT(*) AS n
            FROM mart_vendas_por_cnae_cnpj
            WHERE 1=1 {$martDateFilter}
        ");
        $stmtCvAgg->execute($martDateParams);
        $cvAgg = $stmtCvAgg->fetch(\PDO::FETCH_ASSOC);

        $cvAggRevenue = (float) $cvAgg['revenue'];
        $cvAggDelta   = round($cvAggRevenue - $canonBaseComercial, 2);

        $cvAggNote = null;
        if ($cvAggDelta < -RCA_DELTA_TOLERANCE) {
            $cvAggNote = sprintf(
                'Deficit de %s no mart CNAE: NFs do inicio do mes podem ter sido deletadas sem reinsercao. '
                . 'Rematerialize com range cobrindo o primeiro dia do mes.',
                rcaFmt(abs($cvAggDelta))
            );
        } elseif ($cvAggDelta > RCA_DELTA_TOLERANCE) {
            $cvAggNote = sprintf(
                'Overshoot de %s no mart CNAE: possivel dupla insercao ou remessa contaminando o mart.',
                rcaFmt($cvAggDelta)
            );
        }

        $addCheck(
            'mart_vendas_por_cnae_cnpj.faturamento',
            'mart_vendas_por_cnae_cnpj.faturamento = canonical commercial base comercial (excl. frete/outras/seguro)',
            $canonBaseComercial,
            $cvAggRevenue,
            'faturamento',
            null,
            $cvAggNote,
        );
    }
} catch (\Throwable $e) {
    $addCheck('mart_vendas_por_cnae_cnpj.faturamento', 'mart_vendas_por_cnae_cnpj (agg)', 0.0, null, 'faturamento', $e->getMessage());
}

// ── Check: pedidos faturados — nenhum remittance ───────────────────────────────

if ($ordHasScope) {
    try {
        $stmtOrd = $pdo->query("
            SELECT operation_scope,
                   COUNT(*) AS n,
                   COALESCE(SUM(valor_total), 0) AS revenue
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'faturado'
            GROUP BY operation_scope
        ");
        $ordScopeBreakdown = [];
        foreach ($stmtOrd->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ordScopeBreakdown[(string)$row['operation_scope']] = [
                'n'       => (int)   $row['n'],
                'revenue' => (float) $row['revenue'],
            ];
        }

        $ordRemitRevenue = (float) ($ordScopeBreakdown['remittance']['revenue'] ?? 0);
        $addCheck(
            'canonical_pedidos.no_remittance_faturado',
            'Pedidos faturados nao devem ter operation_scope=remittance',
            0.0,
            $ordRemitRevenue,
            'operation_scope',
            null,
            $ordRemitRevenue > 0 ? 'Pedidos faturados classificados como remessa detectados — verificar backfill.' : null,
        );
    } catch (\Throwable $e) {
        $ordScopeBreakdown = [];
        $addCheck('canonical_pedidos.no_remittance_faturado', 'pedidos_canonicos', 0.0, null, 'operation_scope', $e->getMessage());
    }
} else {
    $ordScopeBreakdown = [];
}

// ── Enforce mode evaluation ────────────────────────────────────────────────────

$hardFails   = array_filter($checks, static fn($c) => $c['status'] === 'FAIL');
$errorChecks = array_filter($checks, static fn($c) => $c['status'] === 'ERROR');

// Find mart_vendedor_dia check result
$mvCheck = null;
foreach ($checks as $c) {
    if ($c['name'] === 'mart_vendedor_dia.valor_nfe') {
        $mvCheck = $c;
        break;
    }
}
$mvClean = $mvCheck !== null && in_array($mvCheck['status'], ['OK', 'OK_INTENTIONAL', 'SKIP'], true);

$critUnknownPct   = $unknownPct <= 5.0;
$critNoFail       = count($hardFails) === 0;
$critCommercial   = $canonRevenue > 0;
$critMvClean      = $mvClean;
$enforceReady     = $critUnknownPct && $critNoFail && $critCommercial && $critMvClean;

$enforceCriteria = [
    'nf_unknown_pct_ok'          => ['pass' => $critUnknownPct,  'value' => "{$unknownPct}% (limite 5%)"],
    'no_hard_failures'           => ['pass' => $critNoFail,       'value' => count($hardFails) . ' falhas'],
    'commercial_revenue_present' => ['pass' => $critCommercial,  'value' => rcaFmt($canonRevenue)],
    'mart_vendedor_dia_clean'    => ['pass' => $critMvClean,     'value' => $mvCheck ? $mvCheck['status'] : 'NOT_CHECKED'],
    'ready'                      => $enforceReady,
    'recommendation'             => $enforceReady
        ? 'ATIVAR: Defina BIOMIE_OPERATION_SCOPE_ENFORCED=1 no .env de producao.'
        : 'AGUARDAR: Corrija as falhas antes de ativar enforce mode.',
];

// ── Output ─────────────────────────────────────────────────────────────────────

$output = [
    'date_filter'           => $dateLabel,
    'migration_h3_applied'  => ['nf_scope' => $nfHasScope, 'order_scope' => $ordHasScope],
    'canonical_commercial'  => [
        'nf_count'              => $canonNfCount,
        'revenue'               => round($canonRevenue,          2),
        'base_comercial'        => round($canonBaseComercial,    2),
        'non_commissionable'    => round($canonNaoComissionavel, 2),
        'merchandise'           => round($canonMerchandise,      2),
        'discount'              => round($canonDiscount,         2),
        'fiscal_basis_columns'  => $nfHasFiscalBasis,
        'date_min'              => $canon['date_min'],
        'date_max'              => $canon['date_max'],
    ],
    'remittance_summary'    => ['nf_count' => $remitCount, 'revenue' => round($remitRevenue, 2)],
    'nf_scope_breakdown'    => $nfScopeBreakdown,
    'order_scope_faturado'  => $ordScopeBreakdown,
    'nf_unknown_pct'        => $unknownPct,
    'checks'                => $checks,
    'failures'              => count($hardFails),
    'errors'                => count($errorChecks),
    'clean'                 => count($hardFails) === 0 && count($errorChecks) === 0,
    'enforce_mode'          => $enforceCriteria,
];

if ($isJson) {
    $flags = JSON_UNESCAPED_UNICODE;
    if ($isPretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($output, $flags) . "\n";
    exit(count($hardFails) > 0 || count($errorChecks) > 0 ? 1 : 0);
}

// ── Text output ────────────────────────────────────────────────────────────────

echo "\n=== commercial_recalibration_audit (Fase I) ===\n";
echo "Filtro: {$dateLabel}\n\n";

echo "FONTE CANONICA (commercial NFs autorizadas):\n";
printf("  NFs:                   %d\n",   $canonNfCount);
printf("  Receita bruta:         %s  (valor_total)\n",  rcaFmt($canonRevenue));
printf("  Base comercial:        %s  (excl. frete/outras/seguro)\n", rcaFmt($canonBaseComercial));
if ($canonNaoComissionavel > 0.009) {
    printf("  Nao comissionavel:     %s  (frete+outras+seguro)\n", rcaFmt($canonNaoComissionavel));
}
printf("  Mercadoria:            %s\n",   rcaFmt($canonMerchandise));
printf("  Desconto:              %s\n",   rcaFmt($canonDiscount));
$basisLabel = $nfHasFiscalBasis ? 'colunas O.1 presentes' : ($nfHasFrete ? 'formula frete/outras/seguro' : 'fallback valor_total');
printf("  Basis fonte:           %s\n",   $basisLabel);
printf("  Periodo:               %s a %s\n\n", $canon['date_min'] ?? '—', $canon['date_max'] ?? '—');

echo "REMESSA (somente NF do Periodo / fiscal):\n";
printf("  NFs:   %d\n",   $remitCount);
printf("  Valor: %s\n\n", rcaFmt($remitRevenue));

echo "ESCOPO NF canonical (autorizadas):\n";
foreach ($nfScopeBreakdown as $scope => $data) {
    printf("  %-15s  n=%-5d  receita=%s\n", $scope, $data['n'], rcaFmt($data['revenue']));
}
printf("  unknown_pct: %.1f%%\n\n", $unknownPct);

if ($ordScopeBreakdown !== []) {
    echo "ESCOPO PEDIDOS faturados:\n";
    foreach ($ordScopeBreakdown as $scope => $data) {
        printf("  %-15s  n=%-5d  receita=%s\n", $scope, $data['n'], rcaFmt($data['revenue']));
    }
    echo "\n";
}

echo "VERIFICACOES:\n";
foreach ($checks as $c) {
    $icon = match ($c['status']) {
        'OK', 'OK_INTENTIONAL' => '[OK  ]',
        'FAIL'                 => '[FAIL]',
        'ERROR'                => '[ERRO]',
        'SKIP'                 => '[SKIP]',
        default                => '[?   ]',
    };
    $line = sprintf('%s %s', $icon, $c['name']);
    if (isset($c['expected'], $c['actual']) && $c['expected'] !== null) {
        $deltaSign = ($c['delta'] >= 0) ? '+' : '';
        $line .= sprintf(
            '  esperado=%s  real=%s  delta=%s%s',
            rcaFmt((float)$c['expected']),
            rcaFmt((float)$c['actual']),
            $deltaSign,
            rcaFmt(abs((float)$c['delta']))
        );
    }
    echo $line . "\n";
    if ($c['note'] !== null) {
        echo "       Nota: {$c['note']}\n";
    }
    if (!empty($c['error'])) {
        echo "       Erro: {$c['error']}\n";
    }
}

echo "\n";
echo "ENFORCE MODE:\n";
foreach ($enforceCriteria as $k => $v) {
    if ($k === 'recommendation' || $k === 'ready') {
        continue;
    }
    $pass = is_array($v) ? $v['pass'] : (bool) $v;
    $val  = is_array($v) ? $v['value'] : '';
    printf("  %s %-35s %s\n", $pass ? '[SIM]' : '[NAO]', $k, $val);
}
$enforceIcon = $enforceReady ? '[SIM]' : '[NAO]';
printf("  %s ready\n\n", $enforceIcon);
echo $enforceCriteria['recommendation'] . "\n\n";

if (count($hardFails) === 0 && count($errorChecks) === 0) {
    echo "Resultado: LIMPO — todos os marts em conformidade com a fonte canonica.\n\n";
    exit(0);
}

if (count($hardFails) > 0) {
    printf("ATENCAO: %d divergencia(s) — rematerialize os marts afetados.\n", count($hardFails));
}
if (count($errorChecks) > 0) {
    printf("ERROS: %d verificacao(es) falharam (ver detalhes acima).\n", count($errorChecks));
}
echo "\n";
exit(1);
