<?php

declare(strict_types=1);

/**
 * tools/dev/nf_title_bridge_coverage_audit.php
 *
 * COM.R2.2 — Fase 3 — Diagnóstico de cobertura Bridge NF × Título.
 *
 * Mostra:
 *   - NFs canônicas no período (comercial, autorizadas, visíveis)
 *   - Títulos financeiros no período
 *   - Bridge items existentes  (biomie_bridge_nf_titulo_items)
 *   - Bridge summary existentes (biomie_bridge_nf_titulo_summary)
 *   - NFs sem bridge
 *   - Títulos sem NF vinculada
 *   - Oportunidades de match por nota_key, chave_nfe, pedido_id_omie, numero_nf
 *
 * NÃO cria, remove ou altera nenhum vínculo — apenas leitura.
 *
 * Usage:
 *   php tools/dev/nf_title_bridge_coverage_audit.php --start=2026-01-01 --end=2026-05-29
 *   php tools/dev/nf_title_bridge_coverage_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/nf_title_bridge_coverage_audit.php --start=2026-01-01 --end=2026-05-29 --json
 *
 * Exit codes:
 *   0 — cobertura bridge >= threshold (sem ação urgente)
 *   1 — gaps significativos detectados (rematerialização necessária)
 *   2 — erro de conexão
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

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
    $msg = 'ERRO: Falha ao conectar: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function ntbcaTableExists(PDO $pdo, string $table): bool
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

function ntbcaColumnExists(PDO $pdo, string $table, string $col): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $st->execute([':t' => $table, ':c' => $col]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function ntbcaFmt(float $val): string
{
    if ($val >= 1_000_000) {
        return 'R$' . number_format($val / 1_000_000, 2, ',', '.') . 'M';
    }
    if ($val >= 1_000) {
        return 'R$' . number_format($val / 1_000, 1, ',', '.') . 'k';
    }
    return 'R$' . number_format($val, 2, ',', '.');
}

// ── Section 1: NFs canônicas no período ──────────────────────────────────────

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

$stNfs = $pdo->prepare(
    "SELECT
        COUNT(*)                                                          AS nfs_total,
        COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_total)), 0) AS base_comercial,
        COUNT(DISTINCT nf.codigo_cliente_omie)                           AS clientes,
        COUNT(DISTINCT nf.nome_vendedor)                                  AS vendedores,
        COUNT(CASE WHEN nf.chave_nfe IS NOT NULL AND nf.chave_nfe <> '' THEN 1 END) AS nfs_com_chave,
        COUNT(CASE WHEN nf.pedido_id_omie IS NOT NULL AND nf.pedido_id_omie > 0 THEN 1 END) AS nfs_com_pedido,
        COUNT(CASE WHEN nf.nota_key IS NOT NULL AND nf.nota_key <> '' THEN 1 END) AS nfs_com_nota_key
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada'
       AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
       AND {$visCond}"
);
$stNfs->execute([':start' => $startDate, ':end' => $endDate]);
$nfsRow = $stNfs->fetch(PDO::FETCH_ASSOC) ?: [];

$nfsSection = [
    'total'          => (int)   ($nfsRow['nfs_total']      ?? 0),
    'base_comercial' => (float) ($nfsRow['base_comercial'] ?? 0),
    'clientes'       => (int)   ($nfsRow['clientes']       ?? 0),
    'vendedores'     => (int)   ($nfsRow['vendedores']     ?? 0),
    'com_chave_nfe'  => (int)   ($nfsRow['nfs_com_chave']  ?? 0),
    'com_pedido_id'  => (int)   ($nfsRow['nfs_com_pedido'] ?? 0),
    'com_nota_key'   => (int)   ($nfsRow['nfs_com_nota_key'] ?? 0),
];

// ── Section 2: Títulos financeiros no período ─────────────────────────────────

$hasTitulosTable = ntbcaTableExists($pdo, 'biomie_titulos_financeiros_canonicos');
$titulosSection  = ['available' => $hasTitulosTable];

if ($hasTitulosTable) {
    $stTit = $pdo->prepare(
        "SELECT
            COUNT(*)                                                           AS titulos_total,
            COALESCE(SUM(valor_liquido), 0)                                    AS valor_total,
            COUNT(CASE WHEN nota_key IS NOT NULL AND nota_key <> '' THEN 1 END) AS com_nota_key,
            COUNT(CASE WHEN chave_nfe IS NOT NULL AND chave_nfe <> '' THEN 1 END) AS com_chave_nfe,
            COUNT(CASE WHEN pedido_id_omie IS NOT NULL AND pedido_id_omie > 0 THEN 1 END) AS com_pedido_id,
            COUNT(CASE WHEN titulo_key IS NOT NULL AND titulo_key <> '' THEN 1 END) AS com_titulo_key
         FROM biomie_titulos_financeiros_canonicos
         WHERE COALESCE(data_competencia, data_vencimento) BETWEEN :start AND :end"
    );
    $stTit->execute([':start' => $startDate, ':end' => $endDate]);
    $titRow = $stTit->fetch(PDO::FETCH_ASSOC) ?: [];

    $titulosSection += [
        'total'        => (int)   ($titRow['titulos_total']  ?? 0),
        'valor_total'  => (float) ($titRow['valor_total']    ?? 0),
        'com_nota_key' => (int)   ($titRow['com_nota_key']   ?? 0),
        'com_chave_nfe' => (int)  ($titRow['com_chave_nfe']  ?? 0),
        'com_pedido_id' => (int)  ($titRow['com_pedido_id']  ?? 0),
        'com_titulo_key' => (int) ($titRow['com_titulo_key'] ?? 0),
    ];
}

// ── Section 3: Bridge items ───────────────────────────────────────────────────

$hasItemsTable  = ntbcaTableExists($pdo, 'biomie_bridge_nf_titulo_items');
$itemsSection   = ['available' => $hasItemsTable];

if ($hasItemsTable) {
    $stItems = $pdo->prepare(
        "SELECT
            COUNT(*)                              AS items_total,
            COUNT(DISTINCT nf.nota_key)           AS nfs_com_bridge,
            COUNT(DISTINCT bni.titulo_key)        AS titulos_vinculados,
            MAX(bni.confidence_score)             AS max_confidence,
            MIN(bni.confidence_score)             AS min_confidence,
            COUNT(CASE WHEN bni.is_manual_override = 1 THEN 1 END) AS manual_overrides
         FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bni.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}"
    );
    $stItems->execute([':start' => $startDate, ':end' => $endDate]);
    $itemsRow = $stItems->fetch(PDO::FETCH_ASSOC) ?: [];

    // Method breakdown
    $stMethods = $pdo->prepare(
        "SELECT bni.match_method, COUNT(*) AS cnt
         FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bni.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
         GROUP BY bni.match_method
         ORDER BY cnt DESC"
    );
    $stMethods->execute([':start' => $startDate, ':end' => $endDate]);
    $methods = $stMethods->fetchAll(PDO::FETCH_KEY_PAIR);

    $itemsSection += [
        'items_total'       => (int) ($itemsRow['items_total']       ?? 0),
        'nfs_com_bridge'    => (int) ($itemsRow['nfs_com_bridge']    ?? 0),
        'titulos_vinculados' => (int) ($itemsRow['titulos_vinculados'] ?? 0),
        'max_confidence'    => (int) ($itemsRow['max_confidence']    ?? 0),
        'min_confidence'    => (int) ($itemsRow['min_confidence']    ?? 0),
        'manual_overrides'  => (int) ($itemsRow['manual_overrides']  ?? 0),
        'methods'           => $methods,
    ];
}

// ── Section 4: Bridge summary ─────────────────────────────────────────────────

$hasSummaryTable = ntbcaTableExists($pdo, 'biomie_bridge_nf_titulo_summary');
$summarySection  = ['available' => $hasSummaryTable];

if ($hasSummaryTable) {
    $stSum = $pdo->prepare(
        "SELECT
            COUNT(s.nota_key)                                              AS nfs_no_summary,
            COALESCE(SUM(s.qtd_titulos), 0)                               AS titulos_vinculados,
            COALESCE(SUM(s.valor_titulos_total), 0)                        AS valor_summary,
            COUNT(CASE WHEN s.qtd_titulos > 1 THEN 1 END)                  AS parceladas,
            COUNT(CASE WHEN s.has_manual_override = 1 THEN 1 END)          AS com_override,
            MIN(s.updated_at)                                              AS oldest_summary,
            MAX(s.updated_at)                                              AS newest_summary
         FROM biomie_bridge_nf_titulo_summary s
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = s.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}"
    );
    $stSum->execute([':start' => $startDate, ':end' => $endDate]);
    $sumRow = $stSum->fetch(PDO::FETCH_ASSOC) ?: [];

    $summarySection += [
        'nfs_no_summary'    => (int)    ($sumRow['nfs_no_summary']    ?? 0),
        'titulos_vinculados' => (int)   ($sumRow['titulos_vinculados'] ?? 0),
        'valor_summary'     => (float)  ($sumRow['valor_summary']     ?? 0),
        'parceladas'        => (int)    ($sumRow['parceladas']        ?? 0),
        'com_override'      => (int)    ($sumRow['com_override']      ?? 0),
        'oldest_summary'    => (string) ($sumRow['oldest_summary']    ?? ''),
        'newest_summary'    => (string) ($sumRow['newest_summary']    ?? ''),
    ];
}

// ── Section 5: NFs sem bridge ─────────────────────────────────────────────────

$nfsSemBridgeSection = ['available' => $hasItemsTable];

if ($hasItemsTable) {
    $stSemBridge = $pdo->prepare(
        "SELECT COUNT(*) AS nfs_sem_bridge
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_bridge_nf_titulo_items bni ON bni.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
           AND bni.nota_key IS NULL"
    );
    $stSemBridge->execute([':start' => $startDate, ':end' => $endDate]);
    $nfsSemBridge = (int) $stSemBridge->fetchColumn();
    $nfsSemBridgeSection['count'] = $nfsSemBridge;

    // Sample up to 5 NFs sem bridge para diagnóstico
    $stSample = $pdo->prepare(
        "SELECT nf.nota_key, nf.chave_nfe, nf.pedido_id_omie,
                COALESCE(nf.data_competencia, nf.data_emissao) AS data_ref,
                COALESCE(nf.valor_base_comercial_nf, nf.valor_total) AS valor,
                nf.nome_cliente, nf.nome_vendedor
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_bridge_nf_titulo_items bni ON bni.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
           AND bni.nota_key IS NULL
         ORDER BY COALESCE(nf.data_competencia, nf.data_emissao) DESC
         LIMIT 5"
    );
    $stSample->execute([':start' => $startDate, ':end' => $endDate]);
    $nfsSemBridgeSection['sample'] = $stSample->fetchAll(PDO::FETCH_ASSOC);
}

// ── Section 6: Títulos sem NF vinculada ──────────────────────────────────────

$titulosSemNfSection = ['available' => $hasTitulosTable];

if ($hasTitulosTable && $hasItemsTable) {
    $stSemNf = $pdo->prepare(
        "SELECT COUNT(*) AS titulos_sem_nf
         FROM biomie_titulos_financeiros_canonicos t
         LEFT JOIN biomie_bridge_nf_titulo_items bni ON bni.titulo_key = t.titulo_key
         WHERE COALESCE(t.data_competencia, t.data_vencimento) BETWEEN :start AND :end
           AND bni.titulo_key IS NULL"
    );
    $stSemNf->execute([':start' => $startDate, ':end' => $endDate]);
    $titulosSemNfSection['count'] = (int) $stSemNf->fetchColumn();
} elseif ($hasTitulosTable) {
    $titulosSemNfSection['count'] = null;
    $titulosSemNfSection['note']  = 'biomie_bridge_nf_titulo_items ausente — não é possível calcular títulos sem bridge';
}

// ── Section 7: Oportunidades de match ─────────────────────────────────────────

$matchOpSection = ['available' => $hasTitulosTable];

if ($hasTitulosTable) {
    // Oportunidade 1: match direto por nota_key
    $stOp1 = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
           AND nf.nota_key IS NOT NULL AND nf.nota_key <> ''"
    );
    $stOp1->execute([':start' => $startDate, ':end' => $endDate]);
    $matchOpSection['by_nota_key'] = (int) $stOp1->fetchColumn();

    // Oportunidade 2: match por chave_nfe (44 chars)
    $stOp2 = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key) AS cnt
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
           AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe <> ''"
    );
    $stOp2->execute([':start' => $startDate, ':end' => $endDate]);
    $matchOpSection['by_chave_nfe'] = (int) $stOp2->fetchColumn();

    // Oportunidade 3: match por pedido_id_omie
    $stOp3 = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key) AS cnt
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.pedido_id_omie = nf.pedido_id_omie
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCond}
           AND nf.pedido_id_omie IS NOT NULL AND nf.pedido_id_omie > 0"
    );
    $stOp3->execute([':start' => $startDate, ':end' => $endDate]);
    $matchOpSection['by_pedido_id'] = (int) $stOp3->fetchColumn();

    // Oportunidade 4: match por numero NF via numero_documento
    $hasNumDoc = ntbcaColumnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'numero_documento_nf');
    $hasNumNf  = ntbcaColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'numero_nota');
    if ($hasNumDoc && $hasNumNf) {
        $stOp4 = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key) AS cnt
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t
               ON t.numero_documento_nf = CAST(nf.numero_nota AS CHAR)
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.operation_scope = 'commercial'
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
               AND {$visCond}
               AND nf.numero_nota IS NOT NULL"
        );
        $stOp4->execute([':start' => $startDate, ':end' => $endDate]);
        $matchOpSection['by_numero_nf'] = (int) $stOp4->fetchColumn();
    } else {
        $matchOpSection['by_numero_nf'] = null;
        $matchOpSection['by_numero_nf_note'] = 'coluna numero_documento_nf ou numero_nota ausente';
    }
}

// ── Compute coverage ratio ────────────────────────────────────────────────────

$totalNfs       = $nfsSection['total'];
$nfsComBridge   = $hasItemsTable ? ($itemsSection['nfs_com_bridge'] ?? 0) : 0;
$coveragePct    = $totalNfs > 0 ? round($nfsComBridge / $totalNfs * 100, 1) : 0.0;
$nfsSemBridge   = $hasItemsTable ? ($nfsSemBridgeSection['count'] ?? 0) : $totalNfs;

$status   = 'ok';
$exitCode = 0;
if (!$hasItemsTable) {
    $status   = 'bridge_table_missing';
    $exitCode = 1;
} elseif ($coveragePct < 50.0) {
    $status   = 'coverage_critical';
    $exitCode = 1;
} elseif ($coveragePct < 80.0) {
    $status   = 'coverage_warning';
    $exitCode = 1;
}

// ── Assemble output ───────────────────────────────────────────────────────────

$output = [
    'tool'    => 'nf_title_bridge_coverage_audit',
    'period'  => ['start' => $startDate, 'end' => $endDate],
    'status'  => $status,
    'coverage_pct' => $coveragePct,
    'canonical_nfs'   => $nfsSection,
    'titulos'         => $titulosSection,
    'bridge_items'    => $itemsSection,
    'bridge_summary'  => $summarySection,
    'nfs_sem_bridge'  => $nfsSemBridgeSection,
    'titulos_sem_nf'  => $titulosSemNfSection,
    'match_opportunities' => $matchOpSection,
    'action_needed' => match ($status) {
        'bridge_table_missing' => 'biomie_bridge_nf_titulo_items não existe — executar rebuild da bridge NF×Título',
        'coverage_critical'    => "Cobertura {$coveragePct}% < 50% — executar rebuild da bridge NF×Título urgente",
        'coverage_warning'     => "Cobertura {$coveragePct}% < 80% — verificar bridge rebuild para o período",
        default                => "Cobertura {$coveragePct}% OK",
    },
];

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

// ── Pretty output ─────────────────────────────────────────────────────────────

$bar = str_repeat('═', 72);
$sep = str_repeat('─', 72);

echo "\n{$bar}\n";
echo " Bridge NF × Título — Diagnóstico de Cobertura\n";
echo "{$bar}\n";
echo " Período: {$startDate} → {$endDate}\n";
echo " Status : {$output['action_needed']}\n";
echo "{$sep}\n\n";

// NFs canônicas
echo " NFs Canônicas (comercial, autorizada, visível)\n";
printf("   Total       : %d\n", $nfsSection['total']);
printf("   Base Comerc.: %s\n", ntbcaFmt($nfsSection['base_comercial']));
printf("   Clientes    : %d\n", $nfsSection['clientes']);
printf("   Vendedores  : %d\n", $nfsSection['vendedores']);
printf("   Com nota_key: %d / %d (%.1f%%)\n",
    $nfsSection['com_nota_key'], $nfsSection['total'],
    $nfsSection['total'] > 0 ? $nfsSection['com_nota_key'] / $nfsSection['total'] * 100 : 0
);
printf("   Com chave_nfe: %d  |  Com pedido_id: %d\n",
    $nfsSection['com_chave_nfe'], $nfsSection['com_pedido_id']
);

echo "\n {$sep}\n";

// Títulos
if ($titulosSection['available']) {
    echo " Títulos Financeiros\n";
    printf("   Total         : %d\n", $titulosSection['total'] ?? 0);
    printf("   Valor total   : %s\n", ntbcaFmt($titulosSection['valor_total'] ?? 0));
    printf("   Com nota_key  : %d  |  Com chave_nfe: %d\n",
        $titulosSection['com_nota_key'] ?? 0, $titulosSection['com_chave_nfe'] ?? 0
    );
    printf("   Com pedido_id : %d  |  Com titulo_key: %d\n",
        $titulosSection['com_pedido_id'] ?? 0, $titulosSection['com_titulo_key'] ?? 0
    );
} else {
    echo " Títulos: tabela biomie_titulos_financeiros_canonicos ausente\n";
}

echo "\n {$sep}\n";

// Bridge items
if ($itemsSection['available']) {
    echo " Bridge Items (biomie_bridge_nf_titulo_items)\n";
    printf("   Items total     : %d\n", $itemsSection['items_total'] ?? 0);
    printf("   NFs com bridge  : %d / %d (%.1f%%)\n",
        $nfsComBridge, $totalNfs, $coveragePct
    );
    printf("   NFs SEM bridge  : %d\n", $nfsSemBridge);
    printf("   Títulos vinc.   : %d\n", $itemsSection['titulos_vinculados'] ?? 0);
    printf("   Confidence      : min=%d  max=%d\n",
        $itemsSection['min_confidence'] ?? 0, $itemsSection['max_confidence'] ?? 0
    );
    printf("   Manual overrides: %d\n", $itemsSection['manual_overrides'] ?? 0);
    if (!empty($itemsSection['methods'])) {
        echo "   Métodos:\n";
        foreach ($itemsSection['methods'] as $method => $cnt) {
            printf("     %-40s : %d\n", $method, $cnt);
        }
    }
} else {
    echo " Bridge Items: tabela biomie_bridge_nf_titulo_items ausente — bridge rebuild necessário\n";
}

echo "\n {$sep}\n";

// Bridge summary
if ($summarySection['available']) {
    echo " Bridge Summary (biomie_bridge_nf_titulo_summary)\n";
    printf("   NFs no summary: %d  |  Títulos: %d  |  Parceladas: %d\n",
        $summarySection['nfs_no_summary'] ?? 0,
        $summarySection['titulos_vinculados'] ?? 0,
        $summarySection['parceladas'] ?? 0
    );
    printf("   Valor summary : %s\n", ntbcaFmt($summarySection['valor_summary'] ?? 0));
    printf("   Última atualiz: %s\n", $summarySection['newest_summary'] ?? 'N/A');
} else {
    echo " Bridge Summary: tabela biomie_bridge_nf_titulo_summary ausente\n";
}

echo "\n {$sep}\n";

// Títulos sem NF
echo " Títulos sem NF vinculada (no período)\n";
if (isset($titulosSemNfSection['count'])) {
    printf("   Titulos sem bridge NF: %d\n", $titulosSemNfSection['count']);
} else {
    printf("   %s\n", $titulosSemNfSection['note'] ?? 'não calculado');
}

echo "\n {$sep}\n";

// Oportunidades de match
echo " Oportunidades de Match (NFs que teriam título correspondente)\n";
if ($matchOpSection['available']) {
    printf("   Por nota_key    : %d NFs\n", $matchOpSection['by_nota_key']   ?? 0);
    printf("   Por chave_nfe   : %d NFs\n", $matchOpSection['by_chave_nfe']  ?? 0);
    printf("   Por pedido_id   : %d NFs\n", $matchOpSection['by_pedido_id']  ?? 0);
    if (isset($matchOpSection['by_numero_nf'])) {
        printf("   Por numero NF   : %d NFs\n", $matchOpSection['by_numero_nf']);
    } elseif (isset($matchOpSection['by_numero_nf_note'])) {
        printf("   Por numero NF   : N/A (%s)\n", $matchOpSection['by_numero_nf_note']);
    }
} else {
    echo "   Tabela de títulos ausente\n";
}

// Sample NFs sem bridge
if ($hasItemsTable && $nfsSemBridge > 0 && !empty($nfsSemBridgeSection['sample'])) {
    echo "\n {$sep}\n";
    echo " Amostra de NFs sem bridge (últimas {$nfsSemBridge}, máx 5)\n";
    printf("   %-25s %-12s %-14s %-12s\n", 'nota_key (trunc)', 'data_ref', 'valor', 'cliente');
    foreach ($nfsSemBridgeSection['sample'] as $row) {
        $nk = substr((string) ($row['nota_key'] ?? ''), 0, 25);
        printf("   %-25s %-12s %-14s %-12s\n",
            $nk ?: '-',
            substr((string) ($row['data_ref'] ?? ''), 0, 10),
            ntbcaFmt((float) ($row['valor'] ?? 0)),
            substr((string) ($row['nome_cliente'] ?? ''), 0, 20)
        );
    }
}

echo "\n{$bar}\n";

// Summary line
$statusSymbol = $exitCode === 0 ? "\033[32m✔\033[0m" : "\033[31m✗\033[0m";
echo " {$statusSymbol}  Bridge coverage: {$coveragePct}%  |  NFs sem bridge: {$nfsSemBridge}  |  Status: {$status}\n";
echo "{$bar}\n\n";

exit($exitCode);
