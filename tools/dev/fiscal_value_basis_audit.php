<?php

declare(strict_types=1);

/**
 * Fase O.1 — Diagnóstico de base de cálculo fiscal (fiscal_value_basis_audit.php)
 *
 * Relatório completo por escopo de operação:
 *   - Inventário de colunas e índices (schema status)
 *   - Progresso do backfill (fiscal_basis_checked_at)
 *   - Por escopo: gross_total_nf, base_comercial_nf, valor_mercadoria, valor_ipi,
 *                 valor_frete, valor_outras, valor_seguro, contagens de NFs com cada deducao
 *   - Consolidado com validação de critério de aceite
 *
 * Não altera nenhum dado.
 *
 * Uso:
 *   php tools/dev/fiscal_value_basis_audit.php
 *   php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/fiscal_value_basis_audit.php --scope=commercial
 *   php tools/dev/fiscal_value_basis_audit.php --verbose
 *   php tools/dev/fiscal_value_basis_audit.php --json --pretty
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['start:', 'end:', 'all', 'scope:', 'verbose', 'json', 'pretty']);
$isVerbose = isset($opts['verbose']);
$isJson    = isset($opts['json']);
$isPretty  = isset($opts['pretty']);
$allTime   = isset($opts['all']) || (!isset($opts['start']) && !isset($opts['end']));
$start     = $opts['start'] ?? '2000-01-01';
$end       = $opts['end']   ?? '2099-12-31';
$scopeArg  = $opts['scope'] ?? null;

$config = Config::fromEnvironment();
$conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = \BIomie\Database::connect($config, $conn);

// ── Helpers ──────────────────────────────────────────────────────────────────

function auditFmt(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function auditFmtDiff(float $a, float $b): string
{
    $diff = $a - $b;
    $pct  = $b > 0.009 ? ($diff / $b * 100) : 0.0;
    $sign = $diff >= 0 ? '+' : '';
    return sprintf('%s%s (%s%.2f%%)', $sign, auditFmt($diff), $sign, $pct);
}

function auditColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function auditIdxExists(\PDO $pdo, string $table, string $idx): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute(['t' => $table, 'i' => $idx]);
    return (int) $stmt->fetchColumn() > 0;
}

// ── 1. Schema inventory ───────────────────────────────────────────────────────

$table = 'biomie_notas_fiscais_canonicas';

$allCols = [
    // Fase O
    'valor_ipi', 'valor_frete', 'valor_outras_despesas', 'valor_seguro',
    // Fase O.1
    'valor_base_comercial_nf', 'has_frete', 'has_outras_despesas', 'has_seguro',
    'has_componentes_nao_comissionaveis', 'fiscal_basis_source', 'fiscal_basis_checked_at',
    // Scope (Fase H)
    'operation_scope',
];

$allIdxs = [
    'idx_nf_operation_scope_competencia',
    'idx_nf_fiscal_basis_checked',
    'idx_nf_has_frete',
    'idx_nf_has_non_commissionable',
];

$colStatus = [];
foreach ($allCols as $col) {
    $colStatus[$col] = auditColExists($pdo, $table, $col);
}

$idxStatus = [];
foreach ($allIdxs as $idx) {
    $idxStatus[$idx] = auditIdxExists($pdo, $table, $idx);
}

$missingCols  = array_keys(array_filter($colStatus, fn(bool $v): bool => !$v));
$missingO1    = array_filter($missingCols, fn(string $c): bool => $c !== 'operation_scope');

// ── 2. Backfill progress ──────────────────────────────────────────────────────

$bfProgress = null;
if ($colStatus['fiscal_basis_checked_at']) {
    $stmtBf = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN fiscal_basis_checked_at IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN fiscal_basis_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS done
         FROM $table WHERE status_fiscal NOT IN ('cancelada','denegada')"
    );
    $bfRow = $stmtBf->fetch(\PDO::FETCH_ASSOC);
    $bfProgress = [
        'total'   => (int) $bfRow['total'],
        'pending' => (int) $bfRow['pending'],
        'done'    => (int) $bfRow['done'],
        'pct'     => $bfRow['total'] > 0 ? round((int) $bfRow['done'] / (int) $bfRow['total'] * 100, 1) : 0.0,
    ];
}

// ── 3. Scope-based aggregation ────────────────────────────────────────────────

$scopeWhere  = '';
$scopeParams = [':start' => $start, ':end' => $end];

if ($scopeArg !== null && in_array($scopeArg, ['commercial', 'remittance', 'unknown'], true)) {
    if ($colStatus['operation_scope']) {
        $scopeWhere           = ' AND operation_scope = :scope';
        $scopeParams[':scope'] = $scopeArg;
    }
}

// Build expressions based on available columns
$ipiExpr   = $colStatus['valor_ipi']             ? 'COALESCE(SUM(valor_ipi), 0)'             : '0';
$freteExpr = $colStatus['valor_frete']            ? 'COALESCE(SUM(valor_frete), 0)'            : '0';
$outrasExpr= $colStatus['valor_outras_despesas']  ? 'COALESCE(SUM(valor_outras_despesas), 0)'  : '0';
$seguroExpr= $colStatus['valor_seguro']           ? 'COALESCE(SUM(valor_seguro), 0)'           : '0';

// Base comercial: prefer pre-computed, then formula, then valor_total
if ($colStatus['valor_base_comercial_nf']) {
    $baseExpr = 'COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_total - COALESCE(valor_frete,0) - COALESCE(valor_outras_despesas,0) - COALESCE(valor_seguro,0))), 0)';
} elseif ($colStatus['valor_frete']) {
    $baseExpr = 'COALESCE(SUM(valor_total - COALESCE(valor_frete,0) - COALESCE(valor_outras_despesas,0) - COALESCE(valor_seguro,0)), 0)';
} else {
    $baseExpr = 'COALESCE(SUM(valor_total), 0)';
}

$freteCountExpr  = $colStatus['has_frete']             ? 'SUM(has_frete)'             : ($colStatus['valor_frete'] ? 'SUM(CASE WHEN valor_frete > 0 THEN 1 ELSE 0 END)' : '0');
$outrasCountExpr = $colStatus['has_outras_despesas']    ? 'SUM(has_outras_despesas)'   : ($colStatus['valor_outras_despesas'] ? 'SUM(CASE WHEN valor_outras_despesas > 0 THEN 1 ELSE 0 END)' : '0');
$seguroCountExpr = $colStatus['has_seguro']             ? 'SUM(has_seguro)'            : ($colStatus['valor_seguro'] ? 'SUM(CASE WHEN valor_seguro > 0 THEN 1 ELSE 0 END)' : '0');
$nonCommExpr     = $colStatus['has_componentes_nao_comissionaveis']
    ? 'SUM(has_componentes_nao_comissionaveis)'
    : '(' . $freteCountExpr . ' + ' . $outrasCountExpr . ' + ' . $seguroCountExpr . ')';

$scopeCol = $colStatus['operation_scope']
    ? "COALESCE(operation_scope, 'sem_escopo')"
    : "'sem_escopo_coluna'";

$sql = <<<SQL
    SELECT
        $scopeCol AS escopo,
        COUNT(*) AS nf_count,
        COALESCE(SUM(valor_total), 0)       AS gross_total_nf,
        COALESCE(SUM(valor_mercadorias), 0) AS valor_mercadoria,
        $ipiExpr    AS valor_ipi,
        $freteExpr  AS valor_frete,
        $outrasExpr AS valor_outras_despesas,
        $seguroExpr AS valor_seguro,
        $baseExpr   AS commercial_base_nf,
        $freteCountExpr  AS nf_with_freight_count,
        $outrasCountExpr AS nf_with_other_expenses_count,
        $seguroCountExpr AS nf_with_insurance_count
    FROM $table
    WHERE status_fiscal NOT IN ('cancelada', 'denegada')
      AND data_emissao BETWEEN :start AND :end{$scopeWhere}
    GROUP BY $scopeCol
    ORDER BY nf_count DESC
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute($scopeParams);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// ── 4. Consolidate ───────────────────────────────────────────────────────────

$grand = [
    'nf_count'                    => 0,
    'gross_total_nf'              => 0.0,
    'valor_mercadoria'            => 0.0,
    'valor_ipi'                   => 0.0,
    'valor_frete'                 => 0.0,
    'valor_outras_despesas'       => 0.0,
    'valor_seguro'                => 0.0,
    'commercial_base_nf'          => 0.0,
    'nf_with_freight_count'       => 0,
    'nf_with_other_expenses_count'=> 0,
    'nf_with_insurance_count'     => 0,
];

$scopeData = [];
foreach ($rows as $row) {
    $entry = [
        'escopo'                       => $row['escopo'],
        'nf_count'                     => (int)   $row['nf_count'],
        'gross_total_nf'               => (float) $row['gross_total_nf'],
        'valor_mercadoria'             => (float) $row['valor_mercadoria'],
        'valor_ipi'                    => (float) $row['valor_ipi'],
        'valor_frete'                  => (float) $row['valor_frete'],
        'valor_outras_despesas'        => (float) $row['valor_outras_despesas'],
        'valor_seguro'                 => (float) $row['valor_seguro'],
        'commercial_base_nf'           => (float) $row['commercial_base_nf'],
        'nf_with_freight_count'        => (int)   $row['nf_with_freight_count'],
        'nf_with_other_expenses_count' => (int)   $row['nf_with_other_expenses_count'],
        'nf_with_insurance_count'      => (int)   $row['nf_with_insurance_count'],
        'non_commissionable_total'     => (float) $row['valor_frete'] + (float) $row['valor_outras_despesas'] + (float) $row['valor_seguro'],
    ];
    $scopeData[] = $entry;

    foreach (['nf_count', 'gross_total_nf', 'valor_mercadoria', 'valor_ipi', 'valor_frete',
              'valor_outras_despesas', 'valor_seguro', 'commercial_base_nf',
              'nf_with_freight_count', 'nf_with_other_expenses_count', 'nf_with_insurance_count'] as $k) {
        $grand[$k] += $entry[$k];
    }
}

$grand['non_commissionable_total'] = $grand['valor_frete'] + $grand['valor_outras_despesas'] + $grand['valor_seguro'];

// ── 5. JSON output ────────────────────────────────────────────────────────────

if ($isJson) {
    $out = [
        'period'        => ['start' => $start, 'end' => $end],
        'schema'        => [
            'columns' => $colStatus,
            'indices'  => $idxStatus,
            'missing_o1_columns' => array_values($missingO1),
        ],
        'backfill'      => $bfProgress,
        'by_scope'      => $scopeData,
        'consolidated'  => $grand,
    ];
    $flags = $isPretty ? JSON_PRETTY_PRINT : 0;
    echo json_encode($out, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// ── 6. Text output ────────────────────────────────────────────────────────────

echo "\n\033[1mFiscal Value Basis Audit — Fase O.1\033[0m\n";
echo "Tabela : $table\n";
echo "Período: " . ($allTime ? "histórico completo" : "$start → $end") . "\n";
if ($scopeArg !== null) { echo "Escopo : $scopeArg\n"; }
echo "\n";

// Schema
echo "--- Schema ---\n";
foreach ($colStatus as $col => $exists) {
    $icon  = $exists ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m";
    $label = $exists ? 'presente' : 'AUSENTE';
    $hint  = !$exists && $col !== 'operation_scope' ? ' (executar migrate_fiscal_value_basis.php --apply)' : '';
    echo "  $icon $col → $label$hint\n";
}
echo "\n--- Índices ---\n";
foreach ($idxStatus as $idx => $exists) {
    $icon = $exists ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m";
    echo "  $icon $idx\n";
}

// Backfill
if ($bfProgress !== null) {
    echo "\n--- Backfill ---\n";
    echo "  Total   : " . $bfProgress['total']   . "\n";
    echo "  Done    : " . $bfProgress['done']     . " (" . $bfProgress['pct'] . "%)\n";
    echo "  Pending : " . $bfProgress['pending']  . "\n";
}

// Per scope
echo "\n--- Totais por escopo ---\n";
foreach ($scopeData as $s) {
    $deducoes = $s['non_commissionable_total'];
    echo "\n  \033[1m[{$s['escopo']}]\033[0m — {$s['nf_count']} NFs\n";
    echo "    gross_total_nf           : " . auditFmt($s['gross_total_nf'])         . "\n";
    echo "    valor_mercadoria         : " . auditFmt($s['valor_mercadoria'])        . "\n";
    echo "    valor_ipi                : " . auditFmt($s['valor_ipi'])              . "  (IPI permanece na base)\n";
    echo "    valor_frete              : " . auditFmt($s['valor_frete'])             . "  ({$s['nf_with_freight_count']} NFs)\n";
    echo "    valor_outras_despesas    : " . auditFmt($s['valor_outras_despesas'])   . "  ({$s['nf_with_other_expenses_count']} NFs)\n";
    echo "    valor_seguro             : " . auditFmt($s['valor_seguro'])            . "  ({$s['nf_with_insurance_count']} NFs)\n";
    echo "    non_commissionable_total : " . auditFmt($deducoes)                    . "\n";
    echo "    commercial_base_nf       : " . auditFmt($s['commercial_base_nf'])     . "\n";
    if ($deducoes > 0.009) {
        echo "      ↳ impacto vs gross   : " . auditFmtDiff($s['commercial_base_nf'], $s['gross_total_nf']) . "\n";
    }
}

// Consolidated
if (count($scopeData) > 1) {
    echo "\n--- Consolidado ---\n";
    echo "  gross_total_nf           : " . auditFmt($grand['gross_total_nf'])        . "\n";
    echo "  valor_ipi                : " . auditFmt($grand['valor_ipi'])             . "\n";
    echo "  valor_frete              : " . auditFmt($grand['valor_frete'])            . "  ({$grand['nf_with_freight_count']} NFs)\n";
    echo "  valor_outras_despesas    : " . auditFmt($grand['valor_outras_despesas'])  . "  ({$grand['nf_with_other_expenses_count']} NFs)\n";
    echo "  valor_seguro             : " . auditFmt($grand['valor_seguro'])           . "  ({$grand['nf_with_insurance_count']} NFs)\n";
    echo "  non_commissionable_total : " . auditFmt($grand['non_commissionable_total']) . "\n";
    echo "  commercial_base_nf       : " . auditFmt($grand['commercial_base_nf'])    . "\n";
}

// NFs com frete (verbose)
if ($colStatus['valor_frete'] && $isVerbose) {
    $stmtSample = $pdo->prepare(
        "SELECT nota_key, numero_nf, data_competencia,
                valor_total, valor_frete, valor_outras_despesas, valor_seguro,
                COALESCE(fiscal_basis_source, 'N/A') AS src
         FROM $table
         WHERE valor_frete > 0
           AND status_fiscal NOT IN ('cancelada','denegada')
           AND data_emissao BETWEEN :start AND :end
         LIMIT 20"
    );
    $stmtSample->execute([':start' => $start, ':end' => $end]);
    $samples = $stmtSample->fetchAll(\PDO::FETCH_ASSOC);

    echo "\n--- Amostra: NFs com frete > 0 ---\n";
    foreach ($samples as $s) {
        $base = (float) $s['valor_total'] - (float) $s['valor_frete'] - (float) $s['valor_outras_despesas'] - (float) $s['valor_seguro'];
        echo "  NF " . $s['numero_nf'] . " (" . $s['data_competencia'] . ")";
        echo " total=" . auditFmt((float) $s['valor_total']);
        echo " frete=" . auditFmt((float) $s['valor_frete']);
        if ((float) $s['valor_outras_despesas'] > 0) { echo " outras=" . auditFmt((float) $s['valor_outras_despesas']); }
        if ((float) $s['valor_seguro'] > 0)          { echo " seguro=" . auditFmt((float) $s['valor_seguro']); }
        echo " base=" . auditFmt($base);
        echo " [" . $s['src'] . "]\n";
    }
}

// Acceptance criteria
echo "\n--- Critério de aceite ---\n";
$ok = true;

$schemaOk = $missingO1 === [];
echo "  " . ($schemaOk ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m") . " Schema Fase O.1 completo\n";
if (!$schemaOk) { $ok = false; }

$bfOk = $bfProgress === null || $bfProgress['pending'] === 0;
echo "  " . ($bfOk ? "\033[32m✔\033[0m" : "\033[33m⚠\033[0m") . " Backfill completo"
    . ($bfProgress !== null ? " ({$bfProgress['pct']}%)" : " (coluna ausente)") . "\n";

$commercialBase = null;
foreach ($scopeData as $s) {
    if ($s['escopo'] === 'commercial') { $commercialBase = $s['commercial_base_nf']; break; }
}

if ($commercialBase !== null) {
    $baseOk = $commercialBase > 0;
    echo "  " . ($baseOk ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m") . " Base comercial (commercial scope): " . auditFmt($commercialBase) . "\n";
    if (!$baseOk) $ok = false;
}

if (!$ok) {
    echo "\n\033[31m✘ Critérios não atendidos. Corrija antes de rematerializar.\033[0m\n";
} else {
    echo "\n\033[32m✔ Pronto para rematerializar marts.\033[0m\n";
}

echo "\n";
