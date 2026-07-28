<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * tools/dev/parcelas_diagnosis.php — Fase Q.10.1 Tarefa 1
 *
 * Diagnóstico de parcelas NF ↔ Títulos Financeiros.
 * Mostra distribuição (1 título, 2 títulos, 3+ títulos), divergências reais após
 * agregação, exemplos de NFs parceladas e detalhe de vencimentos.
 *
 * Usage:
 *   php tools/dev/parcelas_diagnosis.php --start=2026-01-01 --end=2026-05-31 --pretty
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pretty = in_array('--pretty', $argv, true);
$opts   = getopt('', ['start:', 'end:', 'pretty', 'json']);
$start  = $opts['start'] ?? '2026-01-01';
$end    = $opts['end']   ?? date('Y-m-d');
$asJson = isset($opts['json']);

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
$report    = ['periodo' => compact('start', 'end')];

// ── 1. Distribuição por quantidade de títulos por NF ──────────────────────────
$distStmt = $pdo->prepare(
    "SELECT qtd_titulos, COUNT(*) AS qtd_nfs
     FROM (
         SELECT nf.nota_key, COUNT(t.titulo_key) AS qtd_titulos
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_titulos_financeiros_canonicos t
                ON t.chave_nfe = nf.chave_nfe
               AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCondNf}
         GROUP BY nf.nota_key
     ) _dist
     GROUP BY qtd_titulos
     ORDER BY qtd_titulos"
);
$distStmt->execute([':s' => $start, ':e' => $end]);
$report['distribuicao'] = $distStmt->fetchAll(\PDO::FETCH_ASSOC);

// ── 2. Agregação: base_comercial vs soma_titulos (divergências reais) ─────────
$aggStmt = $pdo->prepare(
    "SELECT
         COUNT(*) AS total_nfs_com_titulo,
         SUM(CASE WHEN ABS(base_comercial - soma_titulos) <= 0.01 THEN 1 ELSE 0 END) AS conciliadas_ok,
         SUM(CASE WHEN ABS(base_comercial - soma_titulos)  > 0.01 THEN 1 ELSE 0 END) AS com_divergencia,
         ROUND(AVG(ABS(base_comercial - soma_titulos)), 2)   AS diferenca_media,
         ROUND(MAX(ABS(base_comercial - soma_titulos)), 2)   AS diferenca_max,
         ROUND(SUM(ABS(base_comercial - soma_titulos)), 2)   AS diferenca_total_acumulada
     FROM (
         SELECT
             ({$baseSql}) AS base_comercial,
             COALESCE(SUM(t.valor_liquido), 0) AS soma_titulos
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t
              ON t.chave_nfe = nf.chave_nfe
             AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCondNf}
         GROUP BY nf.nota_key
     ) _agg"
);
$aggStmt->execute([':s' => $start, ':e' => $end]);
$report['agregado'] = $aggStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

// ── 3. Distribuição de status dos títulos vinculados ──────────────────────────
$statusStmt = $pdo->prepare(
    "SELECT COALESCE(t.status_titulo, '(null)') AS status_titulo, COUNT(*) AS qtd
     FROM biomie_notas_fiscais_canonicas nf
     JOIN biomie_titulos_financeiros_canonicos t
          ON t.chave_nfe = nf.chave_nfe
         AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCondNf}
     GROUP BY t.status_titulo
     ORDER BY qtd DESC"
);
$statusStmt->execute([':s' => $start, ':e' => $end]);
$report['status_titulos'] = $statusStmt->fetchAll(\PDO::FETCH_ASSOC);

// ── 4. Exemplos de NFs parceladas (qtd_titulos > 1) ──────────────────────────
$parcStmt = $pdo->prepare(
    "SELECT
         nf.nota_key, nf.numero_nf, nf.nome_cliente, nf.nome_vendedor,
         ({$baseSql})                                        AS base_comercial,
         COUNT(t.titulo_key)                                  AS qtd_titulos,
         COALESCE(SUM(t.valor_liquido), 0)                   AS soma_titulos,
         COALESCE(SUM(t.valor_aberto), 0)                    AS soma_aberto,
         ABS(({$baseSql}) - COALESCE(SUM(t.valor_liquido), 0)) AS diferenca,
         MIN(t.data_vencimento)                               AS menor_vencimento,
         MAX(t.data_vencimento)                               AS maior_vencimento
     FROM biomie_notas_fiscais_canonicas nf
     JOIN biomie_titulos_financeiros_canonicos t
          ON t.chave_nfe = nf.chave_nfe
         AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCondNf}
     GROUP BY nf.nota_key, nf.numero_nf, nf.nome_cliente, nf.nome_vendedor
     HAVING qtd_titulos > 1
     ORDER BY qtd_titulos DESC, diferenca DESC
     LIMIT 5"
);
$parcStmt->execute([':s' => $start, ':e' => $end]);
$report['exemplos_parceladas'] = $parcStmt->fetchAll(\PDO::FETCH_ASSOC);

// ── 5. Detalhe das parcelas de uma NF de exemplo ──────────────────────────────
$sampleStmt = $pdo->prepare(
    "SELECT nf.nota_key, nf.numero_nf
     FROM biomie_notas_fiscais_canonicas nf
     JOIN biomie_titulos_financeiros_canonicos t
          ON t.chave_nfe = nf.chave_nfe
         AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCondNf}
     GROUP BY nf.nota_key, nf.numero_nf
     HAVING COUNT(t.titulo_key) > 1
     ORDER BY COUNT(t.titulo_key) DESC
     LIMIT 1"
);
$sampleStmt->execute([':s' => $start, ':e' => $end]);
$sampleNf = $sampleStmt->fetch(\PDO::FETCH_ASSOC);

if ($sampleNf) {
    $detStmt = $pdo->prepare(
        "SELECT t.titulo_key, t.numero_documento AS titulo_num,
                t.status_titulo, t.data_vencimento, t.data_liquidacao,
                COALESCE(t.valor_liquido, 0) AS valor_liquido,
                COALESCE(t.valor_aberto,  0) AS valor_aberto
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_notas_fiscais_canonicas nf ON nf.chave_nfe = t.chave_nfe
         WHERE nf.nota_key = :nota_key
         ORDER BY t.data_vencimento, t.titulo_key"
    );
    $detStmt->execute([':nota_key' => $sampleNf['nota_key']]);
    $report['amostra_parcelas'] = [
        'nota_key'  => $sampleNf['nota_key'],
        'numero_nf' => $sampleNf['numero_nf'],
        'parcelas'  => $detStmt->fetchAll(\PDO::FETCH_ASSOC),
    ];
}

// ── 6. Valor total financeiro por chave_nfe (top 5 maiores) ──────────────────
$topStmt = $pdo->prepare(
    "SELECT nf.numero_nf, nf.nome_cliente,
            ({$baseSql})                   AS base_comercial,
            COUNT(t.titulo_key)             AS qtd_titulos,
            SUM(t.valor_liquido)            AS total_financeiro,
            SUM(t.valor_aberto)             AS total_aberto
     FROM biomie_notas_fiscais_canonicas nf
     JOIN biomie_titulos_financeiros_canonicos t
          ON t.chave_nfe = nf.chave_nfe
         AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCondNf}
     GROUP BY nf.nota_key, nf.numero_nf, nf.nome_cliente
     ORDER BY total_financeiro DESC
     LIMIT 5"
);
$topStmt->execute([':s' => $start, ':e' => $end]);
$report['top_por_valor'] = $topStmt->fetchAll(\PDO::FETCH_ASSOC);

// ── Output ────────────────────────────────────────────────────────────────────
if ($asJson || !$pretty) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo "  Diagnóstico de Parcelas NF ↔ Títulos — {$start} a {$end}" . PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;

    echo "  ── Distribuição NFs por número de títulos ───────────────────────" . PHP_EOL;
    printf("  %-18s %-10s\n", 'Qtd Títulos', 'Qtd NFs');
    echo "  " . str_repeat('─', 30) . PHP_EOL;
    foreach ($report['distribuicao'] as $d) {
        $label = (int) $d['qtd_titulos'] === 0 ? '0  (sem título)' : $d['qtd_titulos'];
        printf("  %-18s %-10s\n", $label, $d['qtd_nfs']);
    }
    echo PHP_EOL;

    $ag = $report['agregado'];
    echo "  ── Agregação (NFs com ≥ 1 título, divergência por soma) ─────────" . PHP_EOL;
    printf("  %-38s : %s\n",  'Total NFs com título', $ag['total_nfs_com_titulo'] ?? 0);
    printf("  %-38s : %s\n",  'OK após soma (|dif| ≤ R$0,01)', $ag['conciliadas_ok'] ?? 0);
    printf("  %-38s : %s\n",  'Divergência real após soma', $ag['com_divergencia'] ?? 0);
    printf("  %-38s : R$ %.2f\n", 'Diferença média', $ag['diferenca_media'] ?? 0);
    printf("  %-38s : R$ %.2f\n", 'Diferença máxima', $ag['diferenca_max'] ?? 0);
    printf("  %-38s : R$ %.2f\n", 'Divergência acumulada', $ag['diferenca_total_acumulada'] ?? 0);
    echo PHP_EOL;

    if (!empty($report['status_titulos'])) {
        echo "  ── Status dos títulos vinculados ────────────────────────────────" . PHP_EOL;
        foreach ($report['status_titulos'] as $s) {
            printf("  %-20s : %s\n", $s['status_titulo'], $s['qtd']);
        }
        echo PHP_EOL;
    }

    if (!empty($report['exemplos_parceladas'])) {
        echo "  ── NFs com múltiplas parcelas (top 5) ───────────────────────────" . PHP_EOL;
        printf("  %-8s %-10s %-9s %-9s %-8s\n", 'NF', 'Parcelas', 'Base Com.', 'Soma Tít.', 'Diferença');
        echo "  " . str_repeat('─', 50) . PHP_EOL;
        foreach ($report['exemplos_parceladas'] as $ex) {
            printf("  %-8s %-10d R$%-7.2f R$%-7.2f R$%-6.2f\n",
                $ex['numero_nf'] ?? '?',
                (int) $ex['qtd_titulos'],
                (float) $ex['base_comercial'],
                (float) $ex['soma_titulos'],
                (float) $ex['diferenca']
            );
        }
        echo PHP_EOL;
    }

    if (!empty($report['amostra_parcelas'])) {
        $am = $report['amostra_parcelas'];
        echo "  ── Parcelas da NF {$am['numero_nf']} ─────────────────────────────────────" . PHP_EOL;
        printf("  %-14s %-10s %-12s %-10s %-10s\n", 'Título', 'Status', 'Vencimento', 'Valor', 'Aberto');
        echo "  " . str_repeat('─', 60) . PHP_EOL;
        foreach ($am['parcelas'] as $p) {
            printf("  %-14s %-10s %-12s R$%-8.2f R$%-8.2f\n",
                substr($p['titulo_num'] ?? '?', 0, 14),
                str_pad($p['status_titulo'] ?? '?', 10),
                $p['data_vencimento'] ?? '—',
                (float) $p['valor_liquido'],
                (float) $p['valor_aberto']
            );
        }
        echo PHP_EOL;
    }

    echo "═══════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;
}
