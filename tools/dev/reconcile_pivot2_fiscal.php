<?php

/**
 * Reconciliação Pivot2 x Canônico.
 *
 * Compara omie_pivot2_faturamento_staging com biomie_notas_fiscais_canonicas
 * e reporta divergências: NFs faltantes, valores divergentes, vendedores e datas.
 *
 * Uso:
 *   php tools/dev/reconcile_pivot2_fiscal.php --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/reconcile_pivot2_fiscal.php --start=2026-05-01 --end=2026-05-31 --json
 *   php tools/dev/reconcile_pivot2_fiscal.php --start=2026-05-01 --end=2026-05-31 --dry-run
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new \BIomie\Config();

function argValue(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
    }
    return $default;
}
function hasFlag(string $name): bool {
    global $argv;
    return in_array('--' . $name, $argv, true);
}

$start  = argValue('start', date('Y-m-01'));
$end    = argValue('end',   date('Y-m-t'));
$asJson = hasFlag('json');

$pdo = \BIomie\Database::connect($config, \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');

// Check if staging table exists
$stagingExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='omie_pivot2_faturamento_staging'"
)->fetchColumn();

// ── Pivot2 totals ──────────────────────────────────────────────────────────────
$pivot2 = ['nfs' => 0, 'total_nf' => 0.0, 'total_mercadoria' => 0.0, 'vendedores' => []];
if ($stagingExists) {
    $p2q = $pdo->prepare(
        "SELECT COUNT(*) AS nfs,
                ROUND(SUM(total_nota_fiscal),2) AS total_nf,
                ROUND(SUM(total_mercadoria),2) AS total_mercadoria
         FROM omie_pivot2_faturamento_staging
         WHERE data_emissao BETWEEN ? AND ?"
    );
    $p2q->execute([$start, $end]);
    $p2row = $p2q->fetch(\PDO::FETCH_ASSOC);
    $pivot2['nfs']            = (int) $p2row['nfs'];
    $pivot2['total_nf']       = (float) $p2row['total_nf'];
    $pivot2['total_mercadoria'] = (float) $p2row['total_mercadoria'];

    $p2v = $pdo->prepare(
        "SELECT COALESCE(vendedor_nome,'N/D') AS vendedor,
                COUNT(*) AS nfs,
                ROUND(SUM(total_nota_fiscal),2) AS total
         FROM omie_pivot2_faturamento_staging
         WHERE data_emissao BETWEEN ? AND ?
         GROUP BY vendedor ORDER BY total DESC"
    );
    $p2v->execute([$start, $end]);
    $pivot2['vendedores'] = $p2v->fetchAll(\PDO::FETCH_ASSOC);
}

// ── Canonical totals ───────────────────────────────────────────────────────────
$canq = $pdo->prepare(
    "SELECT COUNT(*) AS nfs,
            ROUND(SUM(valor_total),2)       AS total_nf,
            ROUND(SUM(valor_mercadorias),2) AS total_mercadoria,
            MIN(data_competencia) AS min_comp,
            MAX(data_competencia) AS max_comp
     FROM biomie_notas_fiscais_canonicas
     WHERE LOWER(status_fiscal) IN ('autorizada','autorizado')
       AND data_competencia BETWEEN ? AND ?"
);
$canq->execute([$start, $end]);
$canRow = $canq->fetch(\PDO::FETCH_ASSOC);
$canonical = [
    'nfs'             => (int)   $canRow['nfs'],
    'total_nf'        => (float) $canRow['total_nf'],
    'total_mercadoria'=> (float) $canRow['total_mercadoria'],
    'min_comp'        => $canRow['min_comp'],
    'max_comp'        => $canRow['max_comp'],
];

$canv = $pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(nome_vendedor),''),'N/D') AS vendedor,
            COUNT(DISTINCT nota_key) AS nfs,
            ROUND(SUM(valor_total),2) AS total
     FROM biomie_notas_fiscais_canonicas
     WHERE LOWER(status_fiscal) IN ('autorizada','autorizado')
       AND data_competencia BETWEEN ? AND ?
     GROUP BY nome_vendedor ORDER BY total DESC"
);
$canv->execute([$start, $end]);
$canonical['vendedores'] = $canv->fetchAll(\PDO::FETCH_ASSOC);

// ── NF-level diff (requires staging) ──────────────────────────────────────────
$missingInCanonical = [];
$missingInPivot2    = [];
$valueDivergences   = [];

if ($stagingExists && $pivot2['nfs'] > 0) {
    // NFs in pivot2 but not in canonical (by numero_nf)
    $miss = $pdo->prepare(
        "SELECT s.numero_nf, s.cliente_nome, s.vendedor_nome,
                s.total_nota_fiscal AS pivot2_total,
                s.data_emissao
         FROM omie_pivot2_faturamento_staging s
         WHERE s.data_emissao BETWEEN ? AND ?
           AND NOT EXISTS (
               SELECT 1 FROM biomie_notas_fiscais_canonicas c
               WHERE (c.numero_nf = s.numero_nf OR c.nota_key LIKE CONCAT('%', s.numero_nf, '%'))
                 AND c.data_competencia BETWEEN ? AND ?
           )
         ORDER BY s.total_nota_fiscal DESC
         LIMIT 50"
    );
    $miss->execute([$start, $end, $start, $end]);
    $missingInCanonical = $miss->fetchAll(\PDO::FETCH_ASSOC);

    // NFs in canonical but not in pivot2
    $missC = $pdo->prepare(
        "SELECT c.numero_nf, c.nome_cliente, c.nome_vendedor,
                c.valor_total AS canonical_total,
                c.data_competencia
         FROM biomie_notas_fiscais_canonicas c
         WHERE LOWER(c.status_fiscal) IN ('autorizada','autorizado')
           AND c.data_competencia BETWEEN ? AND ?
           AND NOT EXISTS (
               SELECT 1 FROM omie_pivot2_faturamento_staging s
               WHERE s.numero_nf = c.numero_nf
                 AND s.data_emissao BETWEEN ? AND ?
           )
         ORDER BY c.valor_total DESC
         LIMIT 50"
    );
    $missC->execute([$start, $end, $start, $end]);
    $missingInPivot2 = $missC->fetchAll(\PDO::FETCH_ASSOC);

    // Value divergences (NF exists on both sides with different totals)
    $divq = $pdo->prepare(
        "SELECT s.numero_nf,
                s.total_nota_fiscal AS pivot2_total,
                c.valor_total       AS canonical_total,
                ROUND(ABS(c.valor_total - s.total_nota_fiscal), 2) AS diff,
                s.vendedor_nome     AS pivot2_vendedor,
                c.nome_vendedor     AS canonical_vendedor
         FROM omie_pivot2_faturamento_staging s
         INNER JOIN biomie_notas_fiscais_canonicas c
             ON (c.numero_nf = s.numero_nf OR c.nota_key LIKE CONCAT('%', s.numero_nf, '%'))
            AND LOWER(c.status_fiscal) IN ('autorizada','autorizado')
            AND c.data_competencia BETWEEN ? AND ?
         WHERE s.data_emissao BETWEEN ? AND ?
           AND ABS(c.valor_total - s.total_nota_fiscal) > 0.05
         ORDER BY diff DESC
         LIMIT 30"
    );
    $divq->execute([$start, $end, $start, $end]);
    $valueDivergences = $divq->fetchAll(\PDO::FETCH_ASSOC);
}

// ── Status ─────────────────────────────────────────────────────────────────────
$nfDiff   = abs($pivot2['nfs']   - $canonical['nfs']);
$totalDiff = abs($pivot2['total_nf'] - $canonical['total_nf']);
$status = match(true) {
    !$stagingExists              => 'no_staging',
    $pivot2['nfs'] === 0         => 'staging_empty',
    $nfDiff === 0 && $totalDiff < 1 => 'ok',
    $nfDiff <= 5 && $totalDiff < 1000 => 'minor_diff',
    default                      => 'critical',
};

$report = [
    'period'           => ['start' => $start, 'end' => $end],
    'generated_at'     => date('c'),
    'status'           => $status,
    'staging_exists'   => $stagingExists,
    'pivot2'           => $pivot2,
    'canonical'        => $canonical,
    'diff' => [
        'nfs_diff'            => $canonical['nfs'] - $pivot2['nfs'],
        'total_diff'          => round($canonical['total_nf'] - $pivot2['total_nf'], 2),
        'mercadoria_diff'     => round($canonical['total_mercadoria'] - $pivot2['total_mercadoria'], 2),
        'missing_in_canonical'=> count($missingInCanonical),
        'missing_in_pivot2'   => count($missingInPivot2),
        'value_divergences'   => count($valueDivergences),
    ],
    'missing_in_canonical' => $missingInCanonical,
    'missing_in_pivot2'    => $missingInPivot2,
    'value_divergences'    => $valueDivergences,
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($status === 'ok' ? 0 : 1);
}

// Human-readable output
echo "=== RECONCILIAÇÃO PIVOT2 × CANÔNICO ===\n";
echo "Período: $start a $end | Status: " . strtoupper($status) . "\n\n";

echo "PIVOT2 (staging):\n";
echo "  NFs:        {$pivot2['nfs']}\n";
echo "  Total NF:   R\$" . number_format($pivot2['total_nf'], 2, ',', '.') . "\n";
echo "  Mercadoria: R\$" . number_format($pivot2['total_mercadoria'], 2, ',', '.') . "\n\n";

echo "CANÔNICO:\n";
echo "  NFs:        {$canonical['nfs']}\n";
echo "  Total NF:   R\$" . number_format($canonical['total_nf'], 2, ',', '.') . "\n";
echo "  Mercadoria: R\$" . number_format($canonical['total_mercadoria'], 2, ',', '.') . "\n";
echo "  Cobertura:  {$canonical['min_comp']} a {$canonical['max_comp']}\n\n";

echo "DIVERGÊNCIAS:\n";
echo "  NFs diff:              {$report['diff']['nfs_diff']}\n";
echo "  Total diff:            R\$" . number_format($report['diff']['total_diff'], 2, ',', '.') . "\n";
echo "  Faltam no canônico:    {$report['diff']['missing_in_canonical']}\n";
echo "  Faltam no pivot2:      {$report['diff']['missing_in_pivot2']}\n";
echo "  Divergência de valor:  {$report['diff']['value_divergences']}\n\n";

if ($missingInCanonical) {
    echo "NFs NO PIVOT2 MAS FALTANDO NO CANÔNICO (primeiras " . count($missingInCanonical) . "):\n";
    foreach (array_slice($missingInCanonical, 0, 10) as $r) {
        echo "  NF {$r['numero_nf']} | {$r['data_emissao']} | {$r['vendedor_nome']} | R\$" . number_format((float)$r['pivot2_total'], 2, ',', '.') . "\n";
    }
    echo "\n";
}

if ($valueDivergences) {
    echo "DIVERGÊNCIAS DE VALOR:\n";
    foreach (array_slice($valueDivergences, 0, 10) as $r) {
        echo "  NF {$r['numero_nf']} | pivot2=R\$" . number_format((float)$r['pivot2_total'], 2, ',', '.') . " | canonical=R\$" . number_format((float)$r['canonical_total'], 2, ',', '.') . " | diff=R\$" . number_format((float)$r['diff'], 2, ',', '.') . "\n";
    }
    echo "\n";
}

echo "VENDEDORES CANONICAL:\n";
foreach ($canonical['vendedores'] as $v) {
    echo "  " . str_pad($v['vendedor'], 25) . " | {$v['nfs']} NFs | R\$" . number_format((float)$v['total'], 2, ',', '.') . "\n";
}

exit($status === 'ok' ? 0 : 1);
