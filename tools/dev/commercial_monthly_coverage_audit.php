<?php

declare(strict_types=1);

/**
 * tools/dev/commercial_monthly_coverage_audit.php
 *
 * COM.R2 — Monthly coverage audit across all commercial data sources.
 *
 * For each month in the given date range, queries:
 *   - canonical NF (biomie_notas_fiscais_canonicas)
 *   - canonical pedidos (biomie_pedidos_canonicos)
 *   - mart_cliente_periodo (optional, graceful fallback)
 *   - mart_produto_periodo (optional, graceful fallback)
 *
 * Emits a recommendation per month: ok | backfill_needed | materialize_needed | no_data
 *
 * Usage:
 *   php tools/dev/commercial_monthly_coverage_audit.php --start=2026-01-01 --end=2026-05-29
 *   php tools/dev/commercial_monthly_coverage_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/commercial_monthly_coverage_audit.php --start=2026-01-01 --end=2026-05-29 --json
 *
 * Exit codes:
 *   0 — all months OK
 *   1 — one or more months with gaps
 *   2 — DB connection error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI params ────────────────────────────────────────────────────────────────

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

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

function cmcaTableExists(PDO $pdo, string $table): bool
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

function cmcaFmtBrl(float $val): string
{
    if ($val >= 1_000_000) {
        return 'R$' . number_format($val / 1_000_000, 1, ',', '.') . 'M';
    }
    if ($val >= 1_000) {
        return 'R$' . number_format($val / 1_000, 0, ',', '.') . 'k';
    }
    return 'R$' . number_format($val, 0, ',', '.');
}

/**
 * Build list of YYYY-MM strings covering [startDate .. endDate].
 *
 * @return list<string>
 */
function cmcaBuildMonths(string $startDate, string $endDate): array
{
    $months  = [];
    $cursor  = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $endDt   = new DateTimeImmutable(substr($endDate, 0, 7) . '-01');

    while ($cursor <= $endDt) {
        $months[] = $cursor->format('Y-m');
        $cursor   = $cursor->modify('+1 month');
    }
    return $months;
}

/**
 * Source 1 — canonical NF metrics for a given month.
 *
 * @return array<string,mixed>
 */
function cmcaQueryCanonicalNf(PDO $pdo, string $ym): array
{
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));

    try {
        $st = $pdo->prepare(
            "SELECT
                COUNT(*)                                                          AS nfs,
                COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_mercadorias)), 0)
                                                                                  AS base_comercial,
                COUNT(DISTINCT codigo_cliente_omie)                               AS clientes,
                COUNT(DISTINCT nome_vendedor)                                     AS vendedores
             FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND operation_scope = 'commercial'
               AND COALESCE(is_biomie_commercial_visible, 1) = 1
               AND COALESCE(data_competencia, data_emissao) BETWEEN :first AND :last"
        );
        $st->execute([':first' => $first, ':last' => $last]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'nfs'            => (int)   ($row['nfs']            ?? 0),
            'base_comercial' => (float) ($row['base_comercial'] ?? 0),
            'clientes'       => (int)   ($row['clientes']       ?? 0),
            'vendedores'     => (int)   ($row['vendedores']     ?? 0),
            'status'         => 'ok',
        ];
    } catch (\Throwable $e) {
        return [
            'nfs'            => 0,
            'base_comercial' => 0.0,
            'clientes'       => 0,
            'vendedores'     => 0,
            'status'         => 'error',
            'error'          => $e->getMessage(),
        ];
    }
}

/**
 * Source 2 — canonical pedidos metrics for a given month.
 *
 * @return array<string,mixed>
 */
function cmcaQueryCanonicalPedidos(PDO $pdo, string $ym): array
{
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));

    try {
        $st = $pdo->prepare(
            "SELECT
                COUNT(*)                                                          AS total_pedidos,
                SUM(CASE WHEN status_comercial = 'autorizado'  THEN 1 ELSE 0 END) AS autorizados,
                SUM(CASE WHEN status_comercial = 'faturado'    THEN 1 ELSE 0 END) AS faturados,
                SUM(CASE WHEN etapa_omie = '10'                THEN 1 ELSE 0 END) AS orcamentos
             FROM biomie_pedidos_canonicos
             WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :first AND :last"
        );
        $st->execute([':first' => $first, ':last' => $last]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'total_pedidos' => (int) ($row['total_pedidos'] ?? 0),
            'autorizados'   => (int) ($row['autorizados']   ?? 0),
            'faturados'     => (int) ($row['faturados']     ?? 0),
            'orcamentos'    => (int) ($row['orcamentos']    ?? 0),
            'status'        => 'ok',
        ];
    } catch (\Throwable $e) {
        return [
            'total_pedidos' => 0,
            'autorizados'   => 0,
            'faturados'     => 0,
            'orcamentos'    => 0,
            'status'        => 'error',
            'error'         => $e->getMessage(),
        ];
    }
}

/**
 * Source 3 — mart_cliente_periodo for a given month.
 *
 * @return array<string,mixed>
 */
function cmcaQueryMartCliente(PDO $pdo, string $ym, bool $exists): array
{
    if (!$exists) {
        return ['status' => 'not_found', 'clientes' => 0, 'faturamento' => 0.0];
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS clientes,
                    COALESCE(SUM(COALESCE(valor_nfe, valor_faturado, 0)), 0) AS faturamento
             FROM mart_cliente_periodo
             WHERE competencia = :ym'
        );
        $st->execute([':ym' => $ym]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'status'      => 'ok',
            'clientes'    => (int)   ($row['clientes']    ?? 0),
            'faturamento' => (float) ($row['faturamento'] ?? 0),
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'clientes' => 0, 'faturamento' => 0.0, 'error' => $e->getMessage()];
    }
}

/**
 * Source 4 — mart_produto_periodo for a given month.
 *
 * @return array<string,mixed>
 */
function cmcaQueryMartProduto(PDO $pdo, string $ym, bool $exists): array
{
    if (!$exists) {
        return ['status' => 'not_found', 'produtos' => 0, 'receita' => 0.0];
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS produtos,
                    COALESCE(SUM(valor_total), 0) AS receita
             FROM mart_produto_periodo
             WHERE competencia = :ym'
        );
        $st->execute([':ym' => $ym]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'status'   => 'ok',
            'produtos' => (int)   ($row['produtos'] ?? 0),
            'receita'  => (float) ($row['receita']  ?? 0),
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'produtos' => 0, 'receita' => 0.0, 'error' => $e->getMessage()];
    }
}

/**
 * Determine recommendation and divergence for one month.
 *
 * @param array<string,mixed> $nf
 * @param array<string,mixed> $mc
 * @return array{recommendation:string,divergence:string}
 */
function cmcaRecommendation(array $nf, array $mc): array
{
    $hasNf   = $nf['nfs'] > 0;
    $hasMart = $mc['faturamento'] > 0;

    if (!$hasNf && !$hasMart) {
        return ['recommendation' => 'no_data', 'divergence' => 'no_data'];
    }
    if ($hasNf && !$hasMart) {
        $div = $mc['status'] === 'not_found' ? 'mart_gap' : 'mart_gap';
        $rec = $mc['status'] === 'not_found' ? 'materialize_needed' : 'materialize_needed';
        return ['recommendation' => $rec, 'divergence' => $div];
    }
    if (!$hasNf && $hasMart) {
        return ['recommendation' => 'backfill_needed', 'divergence' => 'mart_only'];
    }
    return ['recommendation' => 'ok', 'divergence' => 'none'];
}

// ── Main ──────────────────────────────────────────────────────────────────────

$months = cmcaBuildMonths($startDate, $endDate);

$hasClientMart  = cmcaTableExists($pdo, 'mart_cliente_periodo');
$hasProdutoMart = cmcaTableExists($pdo, 'mart_produto_periodo');

$results  = [];
$exitCode = 0;

foreach ($months as $ym) {
    $nf      = cmcaQueryCanonicalNf($pdo, $ym);
    $pedidos = cmcaQueryCanonicalPedidos($pdo, $ym);
    $mc      = cmcaQueryMartCliente($pdo, $ym, $hasClientMart);
    $mp      = cmcaQueryMartProduto($pdo, $ym, $hasProdutoMart);

    ['recommendation' => $rec, 'divergence' => $div] = cmcaRecommendation($nf, $mc);

    if ($rec !== 'ok') {
        $exitCode = 1;
    }

    $results[] = [
        'month'                    => $ym,
        'canonical_nfs'            => $nf['nfs'],
        'canonical_base_comercial' => $nf['base_comercial'],
        'canonical_clientes'       => $nf['clientes'],
        'canonical_vendedores'     => $nf['vendedores'],
        'pedidos_total'            => $pedidos['total_pedidos'],
        'pedidos_autorizados'      => $pedidos['autorizados'],
        'pedidos_faturados'        => $pedidos['faturados'],
        'pedidos_orcamentos'       => $pedidos['orcamentos'],
        'mart_cliente_status'      => $mc['status'],
        'mart_cliente_faturamento' => $mc['faturamento'],
        'mart_produto_status'      => $mp['status'],
        'mart_produto_receita'     => $mp['receita'],
        'recommendation'           => $rec,
        'divergence'               => $div,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'tool'    => 'commercial_monthly_coverage_audit',
        'start'   => $startDate,
        'end'     => $endDate,
        'months'  => $results,
        'summary' => [
            'total_months'   => count($results),
            'ok'             => count(array_filter($results, fn($r) => $r['recommendation'] === 'ok')),
            'backfill'       => count(array_filter($results, fn($r) => $r['recommendation'] === 'backfill_needed')),
            'materialize'    => count(array_filter($results, fn($r) => $r['recommendation'] === 'materialize_needed')),
            'no_data'        => count(array_filter($results, fn($r) => $r['recommendation'] === 'no_data')),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

echo "=== commercial_monthly_coverage_audit ===\n";
echo "Period: {$startDate} to {$endDate}\n\n";

foreach ($results as $r) {
    $recTag = match ($r['recommendation']) {
        'ok'                  => '[OK]',
        'backfill_needed'     => '[backfill_needed]',
        'materialize_needed'  => '[materialize_needed]',
        default               => '[no_data]',
    };

    $mcStatus = strtoupper($r['mart_cliente_status']);  // OK / NOT_FOUND / ERROR
    $mpStatus = strtoupper($r['mart_produto_status']);

    $brl = cmcaFmtBrl($r['canonical_base_comercial']);

    $ped = sprintf(
        'Pedidos=%d(aut=%d,fat=%d)',
        $r['pedidos_total'],
        $r['pedidos_autorizados'],
        $r['pedidos_faturados']
    );

    printf(
        "%s | NFs=%-3d | %-8s | %2d cli | %d vend | %s | Mart cli=%s | Mart prod=%s | %s\n",
        $r['month'],
        $r['canonical_nfs'],
        $brl,
        $r['canonical_clientes'],
        $r['canonical_vendedores'],
        $ped,
        $mcStatus,
        $mpStatus,
        $recTag
    );
}

$ok   = count(array_filter($results, fn($r) => $r['recommendation'] === 'ok'));
$gaps = count($results) - $ok;
echo "\n";
echo "Total: " . count($results) . " meses | OK: {$ok} | Com gaps: {$gaps}\n";

exit($exitCode);
