<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * tools/dev/reconciliation_finance_diagnosis.php — Fase Q.10
 *
 * Diagnóstico profundo da ponte NF ↔ Título Financeiro.
 * Testa 10 estratégias de vínculo e reporta cobertura por estratégia.
 *
 * Usage:
 *   php tools/dev/reconciliation_finance_diagnosis.php --start=2026-01-01 --end=2026-05-31 --pretty
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pretty   = in_array('--pretty', $argv, true);
$opts     = getopt('', ['start:', 'end:', 'pretty', 'json']);
$start    = $opts['start'] ?? '2026-01-01';
$end      = $opts['end']   ?? date('Y-m-d');
$asJson   = isset($opts['json']);

try {
    $config = new \BIomie\Config();
    $conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = \BIomie\Database::connect($config, $conn);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: DB — " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$visCondNf = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$baseSql   = FiscalValueBasis::preComputedBaseSql('nf');

$report = [];

// ── 0. Table structure ────────────────────────────────────────────────────────
$report['table_structure'] = [];
try {
    $cols = $pdo->query('DESCRIBE biomie_titulos_financeiros_canonicos')->fetchAll(\PDO::FETCH_COLUMN);
    $report['table_structure']['biomie_titulos_financeiros_canonicos'] = $cols;
} catch (\Throwable) {}
try {
    $cols2 = $pdo->query('DESCRIBE biomie_notas_fiscais_canonicas')->fetchAll(\PDO::FETCH_COLUMN);
    $report['table_structure']['biomie_notas_fiscais_canonicas'] = $cols2;
} catch (\Throwable) {}

// ── 1. Baseline counts ────────────────────────────────────────────────────────
$stmtNf = $pdo->prepare(
    "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCondNf}"
);
$stmtNf->execute([':s' => $start, ':e' => $end]);
$totalNfs = (int) $stmtNf->fetchColumn();

$totalTitulos = (int) $pdo->query('SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos')->fetchColumn();

$stmtFill = $pdo->query(
    'SELECT COUNT(*) as total,
            COUNT(nota_key) as has_nota_key,
            COUNT(chave_nfe) as has_chave_nfe,
            COUNT(pedido_id_omie) as has_pedido_id,
            COUNT(codigo_pedido_origem) as has_pedido_codigo,
            COUNT(documento_origem_ref) as has_doc_origem_ref
     FROM biomie_titulos_financeiros_canonicos'
);
$tFill = $stmtFill->fetch(\PDO::FETCH_ASSOC);

$report['baseline'] = [
    'nfs_comerciais_autorizadas_periodo' => $totalNfs,
    'titulos_total'                      => $totalTitulos,
    'titulos_com_nota_key'               => (int) $tFill['has_nota_key'],
    'titulos_com_chave_nfe'              => (int) $tFill['has_chave_nfe'],
    'titulos_com_pedido_id'              => (int) $tFill['has_pedido_id'],
    'titulos_com_pedido_codigo'          => (int) $tFill['has_pedido_codigo'],
    'titulos_com_doc_origem_ref'         => (int) $tFill['has_doc_origem_ref'],
];

// ── 2. nota_key format samples ────────────────────────────────────────────────
try {
    $sNfKey = $pdo->query(
        "SELECT nota_key, numero_nf, chave_nfe, pedido_id_omie
         FROM biomie_notas_fiscais_canonicas
         WHERE status_fiscal = 'autorizada' AND operation_scope = 'commercial'
         LIMIT 3"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $report['nota_key_format']['nf_sample'] = $sNfKey;
} catch (\Throwable) {}

try {
    $sTitKey = $pdo->query(
        "SELECT nota_key, chave_nfe, numero_documento, documento_origem_tipo,
                documento_origem_ref, pedido_id_omie, codigo_pedido_origem
         FROM biomie_titulos_financeiros_canonicos LIMIT 3"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $report['nota_key_format']['titulo_sample'] = $sTitKey;
} catch (\Throwable) {}

// ── 3. Join strategy coverage ─────────────────────────────────────────────────
$strategies = [];

$joinTests = [
    'nota_key_exato'          => "t.nota_key = nf.nota_key",
    'chave_nfe_exato'         => "t.chave_nfe = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''",
    'pedido_id_omie'          => "t.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0",
    'codigo_pedido_origem'    => "t.codigo_pedido_origem = nf.codigo_pedido_origem AND nf.codigo_pedido_origem IS NOT NULL AND nf.codigo_pedido_origem != ''",
    'numero_nf_numero_doc'    => "t.numero_documento = nf.numero_nf AND nf.numero_nf IS NOT NULL AND nf.numero_nf != ''",
    'doc_origem_ref_numero_nf' => "t.documento_origem_ref = nf.numero_nf AND nf.numero_nf IS NOT NULL AND nf.numero_nf != ''",
    'doc_origem_ref_chave'    => "t.documento_origem_ref = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''",
    'cliente_valor_data3d'    => "t.codigo_cliente_omie = nf.codigo_cliente_omie AND ABS(COALESCE(t.valor_liquido,0) - ({$baseSql})) <= 0.01 AND ABS(DATEDIFF(COALESCE(t.data_emissao,t.data_competencia), COALESCE(nf.data_competencia,nf.data_emissao))) <= 3",
    'cliente_valor_data7d'    => "t.codigo_cliente_omie = nf.codigo_cliente_omie AND ABS(COALESCE(t.valor_liquido,0) - ({$baseSql})) <= GREATEST(0.01, ({$baseSql}) * 0.02) AND ABS(DATEDIFF(COALESCE(t.data_emissao,t.data_competencia), COALESCE(nf.data_competencia,nf.data_emissao))) <= 7",
    'chave_nfe_titulo_nota_key' => "t.nota_key = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''",
];

foreach ($joinTests as $name => $joinCond) {
    try {
        $sql = "SELECT COUNT(DISTINCT nf.nota_key) AS matched_nfs,
                       COUNT(DISTINCT t.titulo_key) AS matched_titulos
                FROM biomie_notas_fiscais_canonicas nf
                JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
                WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                  AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
                  AND {$visCondNf}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':s' => $start, ':e' => $end]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        $matchedNfs = (int) $r['matched_nfs'];
        $pct = $totalNfs > 0 ? round($matchedNfs / $totalNfs * 100, 1) : 0;
        $strategies[$name] = [
            'matched_nfs'    => $matchedNfs,
            'matched_titulos' => (int) $r['matched_titulos'],
            'coverage_pct'   => $pct,
            'join_condition' => $joinCond,
        ];
    } catch (\Throwable $e) {
        $strategies[$name] = ['error' => $e->getMessage()];
    }
}
$report['join_strategy_coverage'] = $strategies;

// ── 4. Best strategy examples ─────────────────────────────────────────────────
$bestStrategy = '';
$bestCount    = 0;
foreach ($strategies as $name => $s) {
    if (($s['matched_nfs'] ?? 0) > $bestCount) {
        $bestCount    = $s['matched_nfs'];
        $bestStrategy = $name;
    }
}
$report['best_strategy'] = $bestStrategy;

if ($bestStrategy !== '' && isset($joinTests[$bestStrategy])) {
    try {
        $exSql = "SELECT nf.nota_key, nf.numero_nf, nf.chave_nfe, nf.pedido_id_omie,
                         t.titulo_key, t.numero_documento, t.chave_nfe AS t_chave_nfe,
                         t.codigo_cliente_omie, COALESCE(t.valor_liquido, 0) AS valor_titulo,
                         ({$baseSql}) AS base_comercial,
                         ABS(({$baseSql}) - COALESCE(t.valor_liquido, 0)) AS diferenca
                  FROM biomie_notas_fiscais_canonicas nf
                  JOIN biomie_titulos_financeiros_canonicos t ON {$joinTests[$bestStrategy]}
                  WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                    AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
                    AND {$visCondNf}
                  LIMIT 3";
        $stmt2 = $pdo->prepare($exSql);
        $stmt2->execute([':s' => $start, ':e' => $end]);
        $report['best_strategy_examples'] = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $report['best_strategy_examples'] = ['error' => $e->getMessage()];
    }
}

// ── 5. Unmatched NF sample (nf_sem_titulo after best strategy) ────────────────
if ($bestStrategy !== '' && isset($joinTests[$bestStrategy])) {
    try {
        $unmatchedSql = "SELECT nf.nota_key, nf.numero_nf, nf.chave_nfe, nf.pedido_id_omie,
                                nf.codigo_cliente_omie, ({$baseSql}) AS base_comercial,
                                COALESCE(nf.data_competencia, nf.data_emissao) AS ref_date
                         FROM biomie_notas_fiscais_canonicas nf
                         LEFT JOIN biomie_titulos_financeiros_canonicos t ON {$joinTests[$bestStrategy]}
                         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
                           AND {$visCondNf}
                           AND t.titulo_key IS NULL
                         LIMIT 3";
        $stmt3 = $pdo->prepare($unmatchedSql);
        $stmt3->execute([':s' => $start, ':e' => $end]);
        $report['unmatched_sample'] = $stmt3->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// ── 6. documento_origem_tipo distribution in titles ───────────────────────────
try {
    $docTipos = $pdo->query(
        "SELECT COALESCE(documento_origem_tipo,'(null)') AS tipo, COUNT(*) AS cnt
         FROM biomie_titulos_financeiros_canonicos
         GROUP BY documento_origem_tipo ORDER BY cnt DESC LIMIT 10"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $report['titulo_documento_origem_tipo'] = $docTipos;
} catch (\Throwable) {}

// ── 7. Confidence matrix recommendation ──────────────────────────────────────
$report['confidence_matrix'] = [
    ['method' => 'chave_nfe_exato',           'confidence' => 100, 'coverage_pct' => $strategies['chave_nfe_exato']['coverage_pct'] ?? 0],
    ['method' => 'chave_nfe_nota_key_titulo',  'confidence' => 100, 'coverage_pct' => $strategies['chave_nfe_titulo_nota_key']['coverage_pct'] ?? 0],
    ['method' => 'numero_nf_numero_doc',       'confidence' => 95,  'coverage_pct' => $strategies['numero_nf_numero_doc']['coverage_pct'] ?? 0],
    ['method' => 'doc_origem_ref_chave',       'confidence' => 95,  'coverage_pct' => $strategies['doc_origem_ref_chave']['coverage_pct'] ?? 0],
    ['method' => 'pedido_id_omie',             'confidence' => 90,  'coverage_pct' => $strategies['pedido_id_omie']['coverage_pct'] ?? 0],
    ['method' => 'codigo_pedido_origem',       'confidence' => 90,  'coverage_pct' => $strategies['codigo_pedido_origem']['coverage_pct'] ?? 0],
    ['method' => 'cliente_valor_data3d',       'confidence' => 80,  'coverage_pct' => $strategies['cliente_valor_data3d']['coverage_pct'] ?? 0],
    ['method' => 'cliente_valor_data7d',       'confidence' => 70,  'coverage_pct' => $strategies['cliente_valor_data7d']['coverage_pct'] ?? 0],
];

// ── Output ────────────────────────────────────────────────────────────────────
if ($asJson || !$pretty) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo "  Diagnóstico NF ↔ Título — Período {$start} a {$end}" . PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;

    $b = $report['baseline'];
    echo "  NFs comerciais autorizadas no período : {$b['nfs_comerciais_autorizadas_periodo']}" . PHP_EOL;
    echo "  Títulos financeiros (total)            : {$b['titulos_total']}" . PHP_EOL;
    echo "  Títulos com nota_key preenchido        : {$b['titulos_com_nota_key']}" . PHP_EOL;
    echo "  Títulos com chave_nfe preenchido       : {$b['titulos_com_chave_nfe']}" . PHP_EOL;
    echo "  Títulos com pedido_id_omie             : {$b['titulos_com_pedido_id']}" . PHP_EOL;
    echo "  Títulos com codigo_pedido_origem       : {$b['titulos_com_pedido_codigo']}" . PHP_EOL;
    echo PHP_EOL;

    echo "  ── Cobertura por estratégia de vínculo (NFs no período) ──────────" . PHP_EOL;
    printf("  %-35s %8s  %8s  %8s\n", 'Estratégia', 'NFs match', 'Títulos', 'Cobertura');
    echo "  " . str_repeat('─', 63) . PHP_EOL;
    foreach ($strategies as $name => $s) {
        if (isset($s['error'])) {
            printf("  %-35s %-30s\n", $name, 'ERROR: ' . substr($s['error'], 0, 30));
        } else {
            $bar = str_repeat('█', (int) min(20, $s['coverage_pct'] / 5));
            printf("  %-35s %8d  %8d  %6.1f%%  %s\n",
                $name,
                $s['matched_nfs'],
                $s['matched_titulos'],
                $s['coverage_pct'],
                $bar
            );
        }
    }
    echo PHP_EOL;
    echo "  Melhor estratégia : " . ($report['best_strategy'] ?: '(nenhuma)') . PHP_EOL;
    $afterBest = $totalNfs - ($strategies[$bestStrategy]['matched_nfs'] ?? 0);
    echo "  NFs ainda sem título após melhor estratégia : {$afterBest}" . PHP_EOL;
    echo PHP_EOL;

    if (!empty($report['best_strategy_examples'])) {
        echo "  ── Exemplos de match (melhor estratégia) ────────────────────────" . PHP_EOL;
        foreach ($report['best_strategy_examples'] as $ex) {
            printf("  NF %-12s | chave_nfe: %-44s | dif: R$ %.2f\n",
                $ex['numero_nf'] ?? '?',
                substr($ex['chave_nfe'] ?? '?', 0, 44),
                $ex['diferenca'] ?? 0
            );
        }
        echo PHP_EOL;
    }

    if (!empty($report['unmatched_sample'])) {
        echo "  ── Exemplos de NF sem título após melhor estratégia ─────────────" . PHP_EOL;
        foreach ($report['unmatched_sample'] as $ex) {
            printf("  NF %-12s | chave_nfe: %-44s | base R$ %.2f\n",
                $ex['numero_nf'] ?? '?',
                substr($ex['chave_nfe'] ?? '(vazio)', 0, 44),
                $ex['base_comercial'] ?? 0
            );
        }
        echo PHP_EOL;
    }

    echo "  ── Matriz de confiança recomendada ──────────────────────────────" . PHP_EOL;
    printf("  %-35s %8s  %8s\n", 'Método', 'Confiança', 'Cobertura');
    echo "  " . str_repeat('─', 55) . PHP_EOL;
    foreach ($report['confidence_matrix'] as $m) {
        printf("  %-35s %8d  %7.1f%%\n", $m['method'], $m['confidence'], $m['coverage_pct']);
    }
    echo PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;
}
