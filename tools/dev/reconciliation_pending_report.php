<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * tools/dev/reconciliation_pending_report.php — Fase Q.10.4
 *
 * Relatório operacional das NFs comerciais sem título financeiro real.
 * Lista de ação para financeiro/comercial corrigir no Omie ou marcar revisão.
 * Somente leitura — não altera nenhum dado.
 *
 * Usage:
 *   php tools/dev/reconciliation_pending_report.php --start=2026-01-01 --end=2026-05-31 --pretty
 *   php tools/dev/reconciliation_pending_report.php --start=2026-01-01 --end=2026-05-31 --csv
 *   php tools/dev/reconciliation_pending_report.php --start=2026-01-01 --end=2026-05-31 --class=titulo_nao_lancado --pretty
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pretty  = in_array('--pretty', $argv, true);
$csvMode = in_array('--csv',    $argv, true);
$opts    = getopt('', ['start:', 'end:', 'pretty', 'json', 'csv', 'limit:', 'class:']);
$start   = $opts['start'] ?? '2026-01-01';
$end     = $opts['end']   ?? date('Y-m-d');
$asJson  = isset($opts['json']);
$limit   = max(10, min(500, (int) ($opts['limit'] ?? 100)));
$class   = trim($opts['class'] ?? 'all'); // 'all' | 'titulo_nao_lancado' | 'possivel_pagamento_avista'

try {
    $config = new \BIomie\Config();
    $conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = \BIomie\Database::connect($config, $conn);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: DB — " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$visCondNf = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$baseSql   = FiscalValueBasis::preComputedBaseSql('nf');

$itemsExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo_items'"
)->fetchColumn();

$semTituloFilter = $itemsExists
    ? "NOT EXISTS (SELECT 1 FROM biomie_bridge_nf_titulo_items bni WHERE bni.nota_key = nf.nota_key)"
    : "NOT EXISTS (
           SELECT 1 FROM biomie_titulos_financeiros_canonicos t
           WHERE t.chave_nfe = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
       )";

$classifSql = "CASE
    WHEN NOT EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc
        WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
    ) THEN 'possivel_pagamento_avista'
    ELSE 'titulo_nao_lancado'
END";

$motivoSql = "CASE
    WHEN NOT EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc
        WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
    ) THEN 'Sem histórico de títulos financeiros para este cliente'
    WHEN EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc
        WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
          AND (_tc.chave_nfe IS NULL OR _tc.chave_nfe = '')
    ) THEN 'Cliente tem título sem chave_nfe/origem — vínculo não pôde ser estabelecido'
    ELSE 'Título não encontrado para este pedido/NF — possível não lançamento no Omie'
END";

$acaoSql = "CASE
    WHEN NOT EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc
        WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
    ) THEN 'confirmar_pagamento_avista'
    WHEN EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc
        WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
          AND (_tc.chave_nfe IS NULL OR _tc.chave_nfe = '')
    ) THEN 'preencher_chave_nfe_origem_no_omie'
    ELSE 'lancar_titulo_no_omie'
END";

$baseWhere = "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
              AND {$visCondNf}
              AND {$semTituloFilter}";

if ($class === 'titulo_nao_lancado') {
    $baseWhere .= " AND EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tf WHERE _tf.codigo_cliente_omie = nf.codigo_cliente_omie)";
} elseif ($class === 'possivel_pagamento_avista') {
    $baseWhere .= " AND NOT EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tf WHERE _tf.codigo_cliente_omie = nf.codigo_cliente_omie)";
}

$params = [':start' => $start, ':end' => $end];

$report = [
    'periodo'           => compact('start', 'end'),
    'class_filter'      => $class,
    'sem_titulo_filter' => $itemsExists ? 'items' : 'chave_nfe_direto',
];

// ── Seção 1: Resumo por classificação ─────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT ({$classifSql}) AS classificacao,
                COUNT(*)        AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2) AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY classificacao
         ORDER BY qtd_nfs DESC"
    );
    $stmt->execute($params);
    $report['resumo_por_classe'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['resumo_por_classe'] = ['error' => $e->getMessage()];
}

// ── Seção 2: Resumo por ação recomendada ──────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT ({$acaoSql}) AS acao_recomendada,
                COUNT(*)     AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2) AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY acao_recomendada
         ORDER BY qtd_nfs DESC"
    );
    $stmt->execute($params);
    $report['resumo_por_acao'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['resumo_por_acao'] = ['error' => $e->getMessage()];
}

// ── Seção 3: Por vendedor ──────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(nf.nome_vendedor, '(sem vendedor)') AS nome_vendedor,
                COUNT(*)                                      AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2)                    AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY nf.nome_vendedor
         ORDER BY qtd_nfs DESC, soma_base_comercial DESC
         LIMIT 15"
    );
    $stmt->execute($params);
    $report['por_vendedor'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['por_vendedor'] = ['error' => $e->getMessage()];
}

// ── Seção 4: Por cliente (top por valor) ──────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(nf.nome_cliente, '(sem cliente)') AS nome_cliente,
                COUNT(*)                                    AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2)                  AS soma_base_comercial,
                ({$acaoSql})                                AS acao_predominante
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY nf.nome_cliente, acao_predominante
         ORDER BY soma_base_comercial DESC, qtd_nfs DESC
         LIMIT 15"
    );
    $stmt->execute($params);
    $report['por_cliente'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['por_cliente'] = ['error' => $e->getMessage()];
}

// ── Seção 5: Por mês ───────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m') AS mes,
                COUNT(*)                                                               AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2)                                             AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY mes
         ORDER BY mes"
    );
    $stmt->execute($params);
    $report['por_mes'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['por_mes'] = ['error' => $e->getMessage()];
}

// ── Seção 6: Por faixa de valor ────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             SUM(CASE WHEN ({$baseSql}) <= 500                                   THEN 1 ELSE 0 END) AS ate_500,
             SUM(CASE WHEN ({$baseSql}) > 500  AND ({$baseSql}) <=  2000         THEN 1 ELSE 0 END) AS de_500_2k,
             SUM(CASE WHEN ({$baseSql}) > 2000 AND ({$baseSql}) <= 10000         THEN 1 ELSE 0 END) AS de_2k_10k,
             SUM(CASE WHEN ({$baseSql}) > 10000 AND ({$baseSql}) <= 50000        THEN 1 ELSE 0 END) AS de_10k_50k,
             SUM(CASE WHEN ({$baseSql}) > 50000                                  THEN 1 ELSE 0 END) AS acima_50k,
             ROUND(SUM(CASE WHEN ({$baseSql}) <= 500  THEN ({$baseSql}) ELSE 0 END), 2)          AS soma_ate_500,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 500  AND ({$baseSql}) <= 2000  THEN ({$baseSql}) ELSE 0 END), 2) AS soma_500_2k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 2000 AND ({$baseSql}) <= 10000 THEN ({$baseSql}) ELSE 0 END), 2) AS soma_2k_10k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 10000 AND ({$baseSql}) <= 50000 THEN ({$baseSql}) ELSE 0 END), 2) AS soma_10k_50k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 50000 THEN ({$baseSql}) ELSE 0 END), 2)         AS soma_acima_50k
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}"
    );
    $stmt->execute($params);
    $report['por_faixa_valor'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $report['por_faixa_valor'] = ['error' => $e->getMessage()];
}

// ── Seção 7: Lista de pendências com ação recomendada ─────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             nf.nota_key,
             nf.numero_nf,
             DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m-%d') AS data_emissao,
             COALESCE(nf.pedido_id_omie, 0)                                           AS pedido_id_omie,
             COALESCE(nf.nome_cliente, '')                                            AS nome_cliente,
             COALESCE(nf.codigo_cliente_omie, 0)                                     AS codigo_cliente_omie,
             COALESCE(nf.nome_vendedor, '')                                           AS nome_vendedor,
             ROUND(({$baseSql}), 2)                                                   AS base_comercial,
             ({$classifSql})                                                           AS classificacao,
             ({$motivoSql})                                                            AS motivo_provavel,
             ({$acaoSql})                                                              AS acao_recomendada,
             (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos _tc
              WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie)                 AS titulos_cliente_total,
             (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos _tc
              WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie
                AND (_tc.chave_nfe IS NULL OR _tc.chave_nfe = ''))                   AS titulos_sem_chave
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         ORDER BY ({$baseSql}) DESC, COALESCE(nf.data_competencia, nf.data_emissao) DESC
         LIMIT :lim"
    );
    $stmt->execute(array_merge($params, [':lim' => $limit]));
    $report['lista_pendencias'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['lista_pendencias'] = ['error' => $e->getMessage()];
}

// Totais
$totalNfs = 0; $totalValor = 0.0;
if (is_array($report['resumo_por_classe'])) {
    foreach ($report['resumo_por_classe'] as $r) {
        $totalNfs   += (int)   ($r['qtd_nfs']            ?? 0);
        $totalValor += (float) ($r['soma_base_comercial'] ?? 0);
    }
}
$report['totais'] = ['total_nfs' => $totalNfs, 'total_base_comercial' => round($totalValor, 2)];

// ── CSV mode ───────────────────────────────────────────────────────────────────
if ($csvMode) {
    $cols = ['nota_key','numero_nf','data_emissao','pedido_id_omie','nome_cliente',
             'nome_vendedor','base_comercial','classificacao','motivo_provavel',
             'acao_recomendada','titulos_cliente_total','titulos_sem_chave'];
    echo implode(',', $cols) . PHP_EOL;
    foreach ((array) ($report['lista_pendencias'] ?? []) as $row) {
        $line = [];
        foreach ($cols as $c) {
            $val    = (string) ($row[$c] ?? '');
            $line[] = '"' . str_replace('"', '""', $val) . '"';
        }
        echo implode(',', $line) . PHP_EOL;
    }
    exit(0);
}

// ── JSON / pretty ──────────────────────────────────────────────────────────────
if ($asJson || !$pretty) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$fmt = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$acaoLabel = [
    'confirmar_pagamento_avista'         => 'Confirmar pag. à vista',
    'preencher_chave_nfe_origem_no_omie' => 'Preencher chave NF/origem no Omie',
    'lancar_titulo_no_omie'              => 'Lançar título no Omie',
    'marcar_revisao_manual'              => 'Marcar revisão manual',
];

echo PHP_EOL;
echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
echo "  Relatório de Pendências Reais — Conciliação NF × Título" . PHP_EOL;
echo "  Período  : {$start} a {$end}" . PHP_EOL;
if ($class !== 'all') {
    echo "  Filtro   : {$class}" . PHP_EOL;
}
echo "  Filter DB: " . ($itemsExists ? 'biomie_bridge_nf_titulo_items' : 'chave_nfe direto') . PHP_EOL;
echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
echo PHP_EOL;
printf("  %-30s : %d NFs   %s\n", 'Total pendências', $totalNfs, $fmt($totalValor));
echo PHP_EOL;

// Resumo por classe
echo "  ── Por Classificação ───────────────────────────────────────────────" . PHP_EOL;
printf("  %-32s  %6s  %s\n", 'Classificação', 'NFs', 'Base Comercial');
echo "  " . str_repeat('─', 62) . PHP_EOL;
foreach ((array) $report['resumo_por_classe'] as $r) {
    printf("  %-32s  %6d  %s\n", $r['classificacao'], (int) $r['qtd_nfs'], $fmt((float) $r['soma_base_comercial']));
}
echo PHP_EOL;

// Resumo por ação
echo "  ── Ações Recomendadas ──────────────────────────────────────────────" . PHP_EOL;
printf("  %-38s  %6s  %s\n", 'Ação', 'NFs', 'Base Comercial');
echo "  " . str_repeat('─', 65) . PHP_EOL;
foreach ((array) $report['resumo_por_acao'] as $r) {
    $label = $acaoLabel[$r['acao_recomendada']] ?? $r['acao_recomendada'];
    printf("  %-38s  %6d  %s\n", $label, (int) $r['qtd_nfs'], $fmt((float) $r['soma_base_comercial']));
}
echo PHP_EOL;

// Por vendedor
if (!empty($report['por_vendedor'])) {
    echo "  ── Por Vendedor (top 15) ───────────────────────────────────────────" . PHP_EOL;
    printf("  %-28s  %6s  %s\n", 'Vendedor', 'NFs', 'Base Com.');
    echo "  " . str_repeat('─', 55) . PHP_EOL;
    foreach ($report['por_vendedor'] as $v) {
        printf("  %-28s  %6d  %s\n",
            substr($v['nome_vendedor'], 0, 28),
            (int) $v['qtd_nfs'],
            $fmt((float) $v['soma_base_comercial'])
        );
    }
    echo PHP_EOL;
}

// Por cliente
if (!empty($report['por_cliente'])) {
    echo "  ── Por Cliente (top 15, por valor) ─────────────────────────────────" . PHP_EOL;
    printf("  %-36s  %6s  %-28s  %s\n", 'Cliente', 'NFs', 'Ação', 'Base Com.');
    echo "  " . str_repeat('─', 90) . PHP_EOL;
    foreach ($report['por_cliente'] as $c) {
        $acao = $acaoLabel[$c['acao_predominante']] ?? $c['acao_predominante'];
        printf("  %-36s  %6d  %-28s  %s\n",
            substr($c['nome_cliente'], 0, 36),
            (int) $c['qtd_nfs'],
            substr($acao, 0, 28),
            $fmt((float) $c['soma_base_comercial'])
        );
    }
    echo PHP_EOL;
}

// Por mês
if (!empty($report['por_mes'])) {
    echo "  ── Por Mês ─────────────────────────────────────────────────────────" . PHP_EOL;
    printf("  %-8s  %6s  %s\n", 'Mês', 'NFs', 'Base Com.');
    echo "  " . str_repeat('─', 40) . PHP_EOL;
    foreach ($report['por_mes'] as $m) {
        printf("  %-8s  %6d  %s\n", $m['mes'], (int) $m['qtd_nfs'], $fmt((float) $m['soma_base_comercial']));
    }
    echo PHP_EOL;
}

// Por faixa
$fv = $report['por_faixa_valor'];
if (is_array($fv) && !isset($fv['error'])) {
    echo "  ── Por Faixa de Valor ──────────────────────────────────────────────" . PHP_EOL;
    printf("  %-20s  %6s  %s\n", 'Faixa', 'NFs', 'Soma');
    echo "  " . str_repeat('─', 48) . PHP_EOL;
    foreach ([
        'Até R$500'      => ['ate_500',    'soma_ate_500'],
        'R$500 – R$2k'   => ['de_500_2k',  'soma_500_2k'],
        'R$2k – R$10k'   => ['de_2k_10k',  'soma_2k_10k'],
        'R$10k – R$50k'  => ['de_10k_50k', 'soma_10k_50k'],
        'Acima de R$50k' => ['acima_50k',  'soma_acima_50k'],
    ] as $label => [$cntKey, $somaKey]) {
        printf("  %-20s  %6d  %s\n", $label, (int) ($fv[$cntKey] ?? 0), $fmt((float) ($fv[$somaKey] ?? 0)));
    }
    echo PHP_EOL;
}

// Lista de pendências
if (!empty($report['lista_pendencias']) && is_array($report['lista_pendencias'])) {
    echo "  ── Lista de Pendências (por valor decrescente) ──────────────────────" . PHP_EOL;
    printf("  %-8s  %-10s  %-28s  %-12s  %-28s\n", 'NF', 'Data', 'Cliente', 'Base Com.', 'Ação');
    echo "  " . str_repeat('─', 93) . PHP_EOL;
    foreach ($report['lista_pendencias'] as $r) {
        $acao = $acaoLabel[$r['acao_recomendada']] ?? $r['acao_recomendada'];
        printf("  %-8s  %-10s  %-28s  %-12s  %-28s\n",
            substr($r['numero_nf'] ?? '?', 0, 8),
            $r['data_emissao'] ?? '?',
            substr($r['nome_cliente'], 0, 28),
            $fmt((float) $r['base_comercial']),
            substr($acao, 0, 28)
        );
        if (!empty($r['motivo_provavel'])) {
            printf("           ↳ %s\n", $r['motivo_provavel']);
        }
    }
    echo PHP_EOL;
}

echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
echo PHP_EOL;
exit(0);
