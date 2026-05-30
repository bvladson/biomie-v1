<?php

declare(strict_types=1);

/**
 * tools/dev/commercial_period_consistency_audit.php
 *
 * Compare commercial metrics across different date-filter sources for a given
 * period. Detects inconsistencies between canonical NF table, mart tables and
 * what the APIs would return.
 *
 * Usage:
 *   php tools/dev/commercial_period_consistency_audit.php --start=2026-01-01 --end=2026-05-31
 *   php tools/dev/commercial_period_consistency_audit.php --start=2026-04-01 --end=2026-04-30 --json
 *
 * Exit codes:
 *   0 — all sources consistent
 *   1 — one or more gaps detected
 *   2 — configuration / DB error
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isJson    = isset($opts['json']);

// ── DB connection ────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    $msg = 'ERRO: Banco nao configurado.';
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

try {
    $pdo = Database::connect($config, $connection);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function cpcaTableExists(\PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function cpcaFmt(float $val): string
{
    return 'R$' . number_format($val, 2, ',', '.');
}

/**
 * Run a canonical-NF metrics query with arbitrary WHERE clause.
 * Returns source metrics array with timing.
 *
 * @param \PDO   $pdo
 * @param string $sourceKey
 * @param string $whereClause  — everything after FROM…nf WHERE (no leading AND)
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function cpcaRunSource(\PDO $pdo, string $sourceKey, string $whereClause, array $params): array
{
    $t0 = microtime(true);

    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                                      AS total_nfs,
                COALESCE(SUM(
                    COALESCE(nf.valor_base_comercial_nf,
                        nf.valor_mercadorias - COALESCE(nf.valor_descontos, 0)
                    )
                ), 0)                                                                         AS base_comercial,
                COUNT(DISTINCT COALESCE(NULLIF(nf.codigo_cliente_omie, 0), NULL))             AS clientes_distintos,
                COUNT(DISTINCT nf.nome_vendedor)                                              AS vendedores_distintos
            FROM biomie_notas_fiscais_canonicas nf
            WHERE {$whereClause}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Top 5 clientes
        $stmtC = $pdo->prepare("
            SELECT
                nf.nome_cliente,
                COALESCE(SUM(
                    COALESCE(nf.valor_base_comercial_nf,
                        nf.valor_mercadorias - COALESCE(nf.valor_descontos, 0)
                    )
                ), 0) AS total
            FROM biomie_notas_fiscais_canonicas nf
            WHERE {$whereClause}
            GROUP BY nf.nome_cliente
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmtC->execute($params);
        $topClientes = $stmtC->fetchAll(\PDO::FETCH_ASSOC);

        // Top 5 vendedores
        $stmtV = $pdo->prepare("
            SELECT
                nf.nome_vendedor,
                COALESCE(SUM(
                    COALESCE(nf.valor_base_comercial_nf,
                        nf.valor_mercadorias - COALESCE(nf.valor_descontos, 0)
                    )
                ), 0) AS total
            FROM biomie_notas_fiscais_canonicas nf
            WHERE {$whereClause}
            GROUP BY nf.nome_vendedor
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmtV->execute($params);
        $topVendedores = $stmtV->fetchAll(\PDO::FETCH_ASSOC);

        $ms = (int) round((microtime(true) - $t0) * 1000);

        return [
            'source'              => $sourceKey,
            'total_nfs'           => (int)   ($row['total_nfs']           ?? 0),
            'base_comercial'      => round((float) ($row['base_comercial']  ?? 0), 2),
            'clientes_distintos'  => (int)   ($row['clientes_distintos']   ?? 0),
            'vendedores_distintos'=> (int)   ($row['vendedores_distintos'] ?? 0),
            'top_clientes'        => array_map(
                fn($r) => ['nome' => $r['nome_cliente'], 'total' => round((float) $r['total'], 2)],
                $topClientes
            ),
            'top_vendedores'      => array_map(
                fn($r) => ['nome' => $r['nome_vendedor'], 'total' => round((float) $r['total'], 2)],
                $topVendedores
            ),
            'query_ms'            => $ms,
            'error'               => null,
        ];
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        return [
            'source'              => $sourceKey,
            'total_nfs'           => 0,
            'base_comercial'      => 0.0,
            'clientes_distintos'  => 0,
            'vendedores_distintos'=> 0,
            'top_clientes'        => [],
            'top_vendedores'      => [],
            'query_ms'            => $ms,
            'error'               => $e->getMessage(),
        ];
    }
}

// ── Build month range for mart ───────────────────────────────────────────────

$startMonth = substr($startDate, 0, 7);
$endMonth   = substr($endDate,   0, 7);

// ── Query each source ────────────────────────────────────────────────────────

// 1. canonical_nf_orc_pd — primary commercial source with visibility filter
$src1 = cpcaRunSource(
    $pdo,
    'canonical_nf_orc_pd',
    "nf.status_fiscal = 'autorizada'
     AND nf.operation_scope = 'commercial'
     AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1
     AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end",
    [':start' => $startDate, ':end' => $endDate]
);

// 2. canonical_nf_all_commercial — without visibility filter (licitação contamination check)
$src2 = cpcaRunSource(
    $pdo,
    'canonical_nf_all_commercial',
    "nf.status_fiscal = 'autorizada'
     AND nf.operation_scope = 'commercial'
     AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end",
    [':start' => $startDate, ':end' => $endDate]
);

// 3. canonical_nf_with_emissao_only — bare data_emissao (date filter diagnosis)
$src3 = cpcaRunSource(
    $pdo,
    'canonical_nf_with_emissao_only',
    "nf.status_fiscal = 'autorizada'
     AND nf.operation_scope = 'commercial'
     AND nf.data_emissao BETWEEN :start AND :end",
    [':start' => $startDate, ':end' => $endDate]
);

// 4. canonical_nf_with_competencia_only — bare data_competencia (diagnosis)
$src4 = cpcaRunSource(
    $pdo,
    'canonical_nf_with_competencia_only',
    "nf.status_fiscal = 'autorizada'
     AND nf.operation_scope = 'commercial'
     AND nf.data_competencia BETWEEN :start AND :end",
    [':start' => $startDate, ':end' => $endDate]
);

// 5. mart_cliente_periodo (optional)
$src5 = [
    'source'             => 'mart_cliente_periodo',
    'total_nfs'          => null,
    'base_comercial'     => null,
    'clientes_distintos' => null,
    'vendedores_distintos' => null,
    'top_clientes'       => [],
    'top_vendedores'     => [],
    'query_ms'           => 0,
    'error'              => 'tabela nao verificada',
    'available'          => false,
];

if (cpcaTableExists($pdo, 'mart_cliente_periodo')) {
    $t0 = microtime(true);
    try {
        $stmtM = $pdo->prepare("
            SELECT
                COALESCE(SUM(faturamento_orc_pd), 0) AS base_comercial,
                COUNT(*)                              AS clientes_distintos
            FROM mart_cliente_periodo
            WHERE competencia BETWEEN :sm AND :em
        ");
        $stmtM->execute([':sm' => $startMonth, ':em' => $endMonth]);
        $mRow = $stmtM->fetch(\PDO::FETCH_ASSOC);

        $src5 = [
            'source'              => 'mart_cliente_periodo',
            'total_nfs'           => null,
            'base_comercial'      => round((float) ($mRow['base_comercial']     ?? 0), 2),
            'clientes_distintos'  => (int) ($mRow['clientes_distintos'] ?? 0),
            'vendedores_distintos'=> null,
            'top_clientes'        => [],
            'top_vendedores'      => [],
            'query_ms'            => (int) round((microtime(true) - $t0) * 1000),
            'error'               => null,
            'available'           => true,
        ];
    } catch (\Throwable $e) {
        $src5['error']     = $e->getMessage();
        $src5['available'] = false;
        $src5['query_ms']  = (int) round((microtime(true) - $t0) * 1000);
    }
} else {
    $src5['error'] = 'mart_cliente_periodo nao existe';
}

$sources = [$src1, $src2, $src3, $src4, $src5];

// ── Comparison / gap detection ────────────────────────────────────────────────

$primaryNfs        = $src1['total_nfs']      ?? 0;
$primaryBase       = $src1['base_comercial'] ?? 0.0;
$primaryClientes   = $src1['clientes_distintos'] ?? 0;

$gaps = [];

// visibility_gap: licitação contamination
$allCommercialNfs = $src2['total_nfs'] ?? 0;
if ($allCommercialNfs !== $primaryNfs) {
    $gaps[] = [
        'flag'        => 'visibility_gap',
        'description' => 'canonical_nf_all_commercial diverge de canonical_nf_orc_pd — possivel contaminacao por Licitacao ou NFs excluidas pelo BiVisibilityScope',
        'primary_nfs' => $primaryNfs,
        'other_nfs'   => $allCommercialNfs,
        'diff_nfs'    => $allCommercialNfs - $primaryNfs,
        'severity'    => 'WARN',
    ];
}

// date_filter_gap: NFs without data_competencia
$competenciaOnlyNfs = $src4['total_nfs'] ?? 0;
if ($primaryNfs > 0 && abs($competenciaOnlyNfs - $primaryNfs) > 0) {
    $gaps[] = [
        'flag'        => 'date_filter_gap',
        'description' => 'canonical_nf_with_competencia_only retorna ' . $competenciaOnlyNfs . ' NFs vs ' . $primaryNfs . ' da fonte principal — ha NFs sem data_competencia que usam data_emissao como fallback',
        'primary_nfs' => $primaryNfs,
        'competencia_only_nfs' => $competenciaOnlyNfs,
        'nfs_sem_competencia'  => $primaryNfs - $competenciaOnlyNfs,
        'severity'    => ($primaryNfs - $competenciaOnlyNfs) > 0 ? 'WARN' : 'INFO',
    ];
}

// base_comercial_gap between primary and mart
if ($src5['available'] ?? false) {
    $martBase = $src5['base_comercial'] ?? 0.0;
    if (abs(($martBase ?? 0.0) - $primaryBase) > 0.01) {
        $gaps[] = [
            'flag'        => 'base_comercial_gap',
            'description' => 'mart_cliente_periodo.faturamento_orc_pd diverge do canonical NF base_comercial',
            'canonical'   => $primaryBase,
            'mart'        => $martBase,
            'diff'        => round(($martBase ?? 0.0) - $primaryBase, 2),
            'severity'    => 'WARN',
        ];
    }
}

// clientes_gap: any source with 0 clients while primary > 0
foreach ([$src2, $src3, $src4] as $src) {
    if ($primaryClientes > 0 && (int) ($src['clientes_distintos'] ?? -1) === 0 && $src['error'] === null) {
        $gaps[] = [
            'flag'        => 'clientes_gap',
            'description' => 'Fonte ' . $src['source'] . ' retorna 0 clientes enquanto fonte principal tem ' . $primaryClientes,
            'source'      => $src['source'],
            'severity'    => 'WARN',
        ];
    }
}

$hasGap = count($gaps) > 0;

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'generated_at' => date(DATE_ATOM),
    'period'       => ['start' => $startDate, 'end' => $endDate],
    'sources'      => $sources,
    'gaps'         => $gaps,
    'has_gap'      => $hasGap,
    'summary'      => [
        'primary_nfs'        => $primaryNfs,
        'primary_base'       => $primaryBase,
        'primary_clientes'   => $primaryClientes,
        'gap_count'          => count($gaps),
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($hasGap ? 1 : 0);
}

// ── Pretty text output ────────────────────────────────────────────────────────

echo "\n=== commercial_period_consistency_audit ===\n";
echo "Periodo: {$startDate} a {$endDate}\n\n";

foreach ($sources as $src) {
    if ($src['error'] !== null) {
        printf("[ERRO] %-42s %s\n", $src['source'] . ':', $src['error']);
        continue;
    }
    if ($src['total_nfs'] === null && $src['available'] === false) {
        printf("[SKIP] %-42s indisponivel\n", $src['source'] . ':');
        continue;
    }
    $nfsLabel = $src['total_nfs'] !== null ? $src['total_nfs'] . ' NFs' : '-';
    printf(
        "[OK  ] %-42s %s | %s | %d clientes | %s vendedores | %dms\n",
        $src['source'] . ':',
        $nfsLabel,
        cpcaFmt((float) ($src['base_comercial'] ?? 0)),
        (int) ($src['clientes_distintos']   ?? 0),
        $src['vendedores_distintos'] !== null ? (string) $src['vendedores_distintos'] : '-',
        $src['query_ms']
    );
}

echo "\n";

if ($gaps === []) {
    echo "[PASS] Nenhuma divergencia detectada entre as fontes.\n\n";
    exit(0);
}

foreach ($gaps as $gap) {
    $sev = $gap['severity'] ?? 'WARN';
    printf("[%s] %s: %s\n", $sev, $gap['flag'], $gap['description']);
}

echo "\nResultado: " . count($gaps) . " gap(s) detectado(s).\n\n";
exit(1);
