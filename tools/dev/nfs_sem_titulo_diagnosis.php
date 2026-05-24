<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * tools/dev/nfs_sem_titulo_diagnosis.php — Fase Q.10.2 Tarefa 1
 *
 * Diagnóstico completo das NFs comerciais autorizadas sem título financeiro vinculado.
 * Somente leitura — não altera bridge, items, summary ou dados existentes.
 *
 * Seções:
 *   1. Base stats (total, soma base_comercial)
 *   2. Distribuição por vendedor (top 10)
 *   3. Distribuição por cliente (top 10)
 *   4. Distribuição por mês
 *   5. Distribuição por faixa de valor
 *   6. Características das NFs (chave_nfe, pedido_id)
 *   7. Busca de candidatos (8 estratégias)
 *   8. Classificação provável por categoria
 *   9. Top N maiores NFs sem título (detalhe manual)
 *
 * Usage:
 *   php tools/dev/nfs_sem_titulo_diagnosis.php --start=2026-01-01 --end=2026-05-31 --pretty
 *   php tools/dev/nfs_sem_titulo_diagnosis.php --start=2026-01-01 --end=2026-05-31 --json
 *   php tools/dev/nfs_sem_titulo_diagnosis.php --start=2026-01-01 --end=2026-05-31 --pretty --limit=30
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pretty = in_array('--pretty', $argv, true);
$opts   = getopt('', ['start:', 'end:', 'pretty', 'json', 'limit:', 'focus:']);
$start  = $opts['start'] ?? '2026-01-01';
$end    = $opts['end']   ?? date('Y-m-d');
$asJson = isset($opts['json']);
$limit  = max(5, min(100, (int) ($opts['limit'] ?? 20)));
$focus  = trim($opts['focus'] ?? '');

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

// ── "Sem título" filter: prefer items table (authoritative), else direct chave_nfe ──
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

$baseWhere = "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
              AND {$visCondNf}
              AND {$semTituloFilter}";

// Focus filter: restrict to sub-class of sem_titulo
if ($focus === 'cliente_tem_titulo_sem_match') {
    $baseWhere .= " AND EXISTS (
        SELECT 1 FROM biomie_titulos_financeiros_canonicos _tf
        WHERE _tf.codigo_cliente_omie = nf.codigo_cliente_omie
    )";
}

$params = [':start' => $start, ':end' => $end];

$report = ['periodo' => compact('start', 'end'), 'sem_titulo_filter' => $itemsExists ? 'items' : 'chave_nfe_direto'];

// ── Seção 1: Base stats ────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)                      AS total_nfs,
                ROUND(SUM({$baseSql}), 2)     AS soma_base_comercial,
                ROUND(AVG({$baseSql}), 2)     AS media_base_comercial,
                ROUND(MIN({$baseSql}), 2)     AS min_base_comercial,
                ROUND(MAX({$baseSql}), 2)     AS max_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}"
    );
    $stmt->execute($params);
    $report['base_stats'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $report['base_stats'] = ['error' => $e->getMessage()];
}

// ── Seção 2: Distribuição por vendedor (top 10) ───────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(nf.nome_vendedor, '(sem vendedor)') AS nome_vendedor,
                COUNT(*)                                      AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2)                    AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY nf.nome_vendedor
         ORDER BY qtd_nfs DESC, soma_base_comercial DESC
         LIMIT 10"
    );
    $stmt->execute($params);
    $report['por_vendedor'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['por_vendedor'] = ['error' => $e->getMessage()];
}

// ── Seção 3: Distribuição por cliente (top 10) ────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(nf.nome_cliente, '(sem cliente)') AS nome_cliente,
                COUNT(*)                                    AS qtd_nfs,
                ROUND(SUM({$baseSql}), 2)                  AS soma_base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         GROUP BY nf.nome_cliente
         ORDER BY qtd_nfs DESC, soma_base_comercial DESC
         LIMIT 10"
    );
    $stmt->execute($params);
    $report['por_cliente'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['por_cliente'] = ['error' => $e->getMessage()];
}

// ── Seção 4: Distribuição por mês ─────────────────────────────────────────────
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

// ── Seção 5: Distribuição por faixa de valor ──────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             SUM(CASE WHEN ({$baseSql}) <= 500                                    THEN 1 ELSE 0 END) AS ate_500,
             SUM(CASE WHEN ({$baseSql}) > 500  AND ({$baseSql}) <=  2000          THEN 1 ELSE 0 END) AS de_500_2k,
             SUM(CASE WHEN ({$baseSql}) > 2000 AND ({$baseSql}) <= 10000          THEN 1 ELSE 0 END) AS de_2k_10k,
             SUM(CASE WHEN ({$baseSql}) > 10000 AND ({$baseSql}) <= 50000         THEN 1 ELSE 0 END) AS de_10k_50k,
             SUM(CASE WHEN ({$baseSql}) > 50000                                   THEN 1 ELSE 0 END) AS acima_50k,
             ROUND(SUM(CASE WHEN ({$baseSql}) <= 500  THEN ({$baseSql}) ELSE 0 END), 2)           AS soma_ate_500,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 500  AND ({$baseSql}) <= 2000  THEN ({$baseSql}) ELSE 0 END), 2) AS soma_500_2k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 2000 AND ({$baseSql}) <= 10000 THEN ({$baseSql}) ELSE 0 END), 2) AS soma_2k_10k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 10000 AND ({$baseSql}) <= 50000 THEN ({$baseSql}) ELSE 0 END), 2) AS soma_10k_50k,
             ROUND(SUM(CASE WHEN ({$baseSql}) > 50000 THEN ({$baseSql}) ELSE 0 END), 2)          AS soma_acima_50k
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}"
    );
    $stmt->execute($params);
    $report['por_faixa_valor'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $report['por_faixa_valor'] = ['error' => $e->getMessage()];
}

// ── Seção 6: Características das NFs ──────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             COUNT(*)                                                                     AS total,
             SUM(CASE WHEN nf.chave_nfe IS NOT NULL AND LENGTH(nf.chave_nfe) = 44 THEN 1 ELSE 0 END) AS com_chave_nfe_valida,
             SUM(CASE WHEN nf.chave_nfe IS NULL OR LENGTH(nf.chave_nfe) != 44     THEN 1 ELSE 0 END) AS sem_chave_nfe_valida,
             SUM(CASE WHEN COALESCE(nf.pedido_id_omie, 0) > 0                     THEN 1 ELSE 0 END) AS com_pedido,
             SUM(CASE WHEN COALESCE(nf.pedido_id_omie, 0) = 0                     THEN 1 ELSE 0 END) AS sem_pedido,
             SUM(CASE WHEN nf.chave_nfe IS NOT NULL AND LENGTH(nf.chave_nfe) = 44
                       AND COALESCE(nf.pedido_id_omie, 0) > 0                     THEN 1 ELSE 0 END) AS chave_e_pedido,
             SUM(CASE WHEN nf.chave_nfe IS NOT NULL AND LENGTH(nf.chave_nfe) = 44
                       AND COALESCE(nf.pedido_id_omie, 0) = 0                     THEN 1 ELSE 0 END) AS chave_sem_pedido,
             SUM(CASE WHEN (nf.chave_nfe IS NULL OR LENGTH(nf.chave_nfe) != 44)
                       AND COALESCE(nf.pedido_id_omie, 0) > 0                     THEN 1 ELSE 0 END) AS pedido_sem_chave,
             SUM(CASE WHEN (nf.chave_nfe IS NULL OR LENGTH(nf.chave_nfe) != 44)
                       AND COALESCE(nf.pedido_id_omie, 0) = 0                     THEN 1 ELSE 0 END) AS sem_chave_nem_pedido
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}"
    );
    $stmt->execute($params);
    $report['caracteristicas'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $report['caracteristicas'] = ['error' => $e->getMessage()];
}

// ── Seção 7: Candidatos por estratégia ────────────────────────────────────────
$candidatos = [];

// E1: Número da NF no numero_documento do título (mesmo cliente)
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND nf.numero_nf IS NOT NULL AND nf.numero_nf != ''
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND t.numero_documento IS NOT NULL AND t.numero_documento != ''
                 AND t.numero_documento LIKE CONCAT('%', nf.numero_nf, '%')
           )"
    );
    $stmt->execute($params);
    $candidatos['E1_numero_nf_no_documento'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E1_numero_nf_no_documento'] = null;
}

// E2: Cliente + valor exato (±R$0,01) + data ±3 dias
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                 AND ABS(DATEDIFF(
                     COALESCE(t.data_emissao, t.data_competencia),
                     COALESCE(nf.data_competencia, nf.data_emissao)
                 )) <= 3
           )"
    );
    $stmt->execute($params);
    $candidatos['E2_cliente_valor_exato_3d'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E2_cliente_valor_exato_3d'] = null;
}

// E3: Cliente + valor exato (±R$0,01) + data ±10 dias
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                 AND ABS(DATEDIFF(
                     COALESCE(t.data_emissao, t.data_competencia),
                     COALESCE(nf.data_competencia, nf.data_emissao)
                 )) <= 10
           )"
    );
    $stmt->execute($params);
    $candidatos['E3_cliente_valor_exato_10d'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E3_cliente_valor_exato_10d'] = null;
}

// E4: Cliente + valor aproximado (±5%) + data ±15 dias
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND ({$baseSql}) > 0
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND COALESCE(t.valor_liquido, 0) BETWEEN ({$baseSql}) * 0.95 AND ({$baseSql}) * 1.05
                 AND ABS(DATEDIFF(
                     COALESCE(t.data_emissao, t.data_competencia),
                     COALESCE(nf.data_competencia, nf.data_emissao)
                 )) <= 15
           )"
    );
    $stmt->execute($params);
    $candidatos['E4_cliente_valor_aprox_5pct_15d'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E4_cliente_valor_aprox_5pct_15d'] = null;
}

// E5: Número do pedido no numero_documento do título (NFs com pedido)
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_pedidos_canonicos p ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
         WHERE {$baseWhere}
           AND COALESCE(nf.pedido_id_omie, 0) > 0
           AND p.codigo_pedido IS NOT NULL AND p.codigo_pedido != ''
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND t.numero_documento IS NOT NULL AND t.numero_documento != ''
                 AND t.numero_documento LIKE CONCAT('%', p.codigo_pedido, '%')
           )"
    );
    $stmt->execute($params);
    $candidatos['E5_pedido_no_documento'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E5_pedido_no_documento'] = null;
}

// E6: Cliente sem NENHUM título no banco inteiro (provável pagamento à vista)
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND NOT EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
           )"
    );
    $stmt->execute($params);
    $candidatos['E6_cliente_sem_titulo_no_banco'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E6_cliente_sem_titulo_no_banco'] = null;
}

// E7: Cliente tem títulos mas nenhum coincide com chave_nfe (título existe sem chave_nfe)
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
           AND nf.chave_nfe IS NOT NULL AND LENGTH(nf.chave_nfe) = 44
           AND EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND (t.chave_nfe IS NULL OR t.chave_nfe = '')
           )
           AND NOT EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.chave_nfe = nf.chave_nfe
           )"
    );
    $stmt->execute($params);
    $candidatos['E7_titulo_sem_chave_nfe_para_cliente'] = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    $candidatos['E7_titulo_sem_chave_nfe_para_cliente'] = null;
}

// E8: Payload bruto — tenta biomie_omie_bronze (graceful skip se não existir)
try {
    $bronzeExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_omie_bronze'"
    )->fetchColumn();

    if (!$bronzeExists) {
        $candidatos['E8_payload_bruto_bronze'] = null;
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND nf.numero_nf IS NOT NULL AND nf.numero_nf != ''
               AND EXISTS (
                   SELECT 1 FROM biomie_omie_bronze b
                   WHERE b.payload IS NOT NULL
                     AND b.payload LIKE CONCAT('%', nf.numero_nf, '%')
                     AND b.tipo LIKE '%financ%'
               )"
        );
        $stmt->execute($params);
        $candidatos['E8_payload_bruto_bronze'] = (int) $stmt->fetchColumn();
    }
} catch (\Throwable $e) {
    $candidatos['E8_payload_bruto_bronze'] = null;
}

$report['candidatos_por_estrategia'] = $candidatos;

// ── Seção P: Estratégias por Pedido (apenas no modo --focus=cliente_tem_titulo_sem_match) ─────
$pCandidatos = [];
if ($focus === 'cliente_tem_titulo_sem_match') {
    // P1 — pedido_id_omie direto no título (confidence 90)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND COALESCE(nf.pedido_id_omie, 0) > 0
               AND EXISTS (
                   SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.pedido_id_omie = nf.pedido_id_omie
                     AND t.codigo_cliente_omie = nf.codigo_cliente_omie
               )"
        );
        $stmt->execute($params);
        $pCandidatos['P1_pedido_id_omie'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P1_pedido_id_omie'] = null; }

    // P2 — pedido_id_omie no numero_documento do título (confidence 85)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND COALESCE(nf.pedido_id_omie, 0) > 0
               AND EXISTS (
                   SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND t.numero_documento IS NOT NULL AND t.numero_documento != ''
                     AND t.numero_documento LIKE CONCAT('%', CAST(nf.pedido_id_omie AS CHAR), '%')
               )"
        );
        $stmt->execute($params);
        $pCandidatos['P2_pedido_numero_documento'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P2_pedido_numero_documento'] = null; }

    // P3 — codigo_pedido_origem = codigo_pedido do pedido (confidence 85)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             LEFT JOIN biomie_pedidos_canonicos p
                    ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
             WHERE {$baseWhere}
               AND p.codigo_pedido IS NOT NULL AND p.codigo_pedido != ''
               AND EXISTS (
                   SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND t.codigo_pedido_origem IS NOT NULL AND t.codigo_pedido_origem != ''
                     AND t.codigo_pedido_origem = p.codigo_pedido
               )"
        );
        $stmt->execute($params);
        $pCandidatos['P3_codigo_pedido_origem'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P3_codigo_pedido_origem'] = null; }

    // P4 — documento_origem_ref = chave_nfe da NF (confidence 80)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
               AND EXISTS (
                   SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND t.documento_origem_ref IS NOT NULL AND t.documento_origem_ref != ''
                     AND t.documento_origem_ref = nf.chave_nfe
               )"
        );
        $stmt->execute($params);
        $pCandidatos['P4_documento_origem_ref'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P4_documento_origem_ref'] = null; }

    // P5 — cliente + pedido + soma ≈ base_comercial ±10% (confidence 75, diagnóstico)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND COALESCE(nf.pedido_id_omie, 0) > 0
               AND ({$baseSql}) > 0
               AND EXISTS (
                   SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND t.pedido_id_omie = nf.pedido_id_omie
               )
               AND ABS((
                   SELECT COALESCE(SUM(t2.valor_liquido), 0)
                   FROM biomie_titulos_financeiros_canonicos t2
                   WHERE t2.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND t2.pedido_id_omie = nf.pedido_id_omie
               ) - ({$baseSql})) <= ({$baseSql}) * 0.10"
        );
        $stmt->execute($params);
        $pCandidatos['P5_cliente_pedido_valor'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P5_cliente_pedido_valor'] = null; }

    // P6 — cliente + mesmo mês + soma ≈ base_comercial ±15% (confidence 60, apenas diagnóstico)
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
               AND ({$baseSql}) > 0
               AND ABS((
                   SELECT COALESCE(SUM(t.valor_liquido), 0)
                   FROM biomie_titulos_financeiros_canonicos t
                   WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND DATE_FORMAT(COALESCE(t.data_emissao, t.data_competencia), '%Y-%m')
                         = DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')
               ) - ({$baseSql})) <= ({$baseSql}) * 0.15"
        );
        $stmt->execute($params);
        $pCandidatos['P6_cliente_mes_valor'] = (int) $stmt->fetchColumn();
    } catch (\Throwable $e) { $pCandidatos['P6_cliente_mes_valor'] = null; }

    $report['candidatos_por_pedido'] = $pCandidatos;
}

// ── Seção 8: Classificação provável (uma categoria por NF, por prioridade) ────
try {
    $stmt = $pdo->prepare(
        "SELECT classificacao, COUNT(*) AS qtd,
                ROUND(SUM(base_com), 2) AS soma_base_comercial
         FROM (
             SELECT nf.nota_key,
                    ({$baseSql}) AS base_com,
                    CASE
                        -- Provável pagamento à vista: cliente sem NENHUM título no banco
                        WHEN NOT EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                        ) THEN 'possivel_pagamento_avista'

                        -- Título existe mas sem chave_nfe: forte candidato por numero_nf
                        WHEN nf.numero_nf IS NOT NULL AND nf.numero_nf != ''
                          AND EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                              AND t.numero_documento LIKE CONCAT('%', nf.numero_nf, '%')
                        ) THEN 'titulo_sem_chave_nfe'

                        -- Vínculo por cliente + valor + data ±3 dias
                        WHEN EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                              AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                              AND ABS(DATEDIFF(
                                  COALESCE(t.data_emissao, t.data_competencia),
                                  COALESCE(nf.data_competencia, nf.data_emissao)
                              )) <= 3
                        ) THEN 'vinculo_por_cliente_valor_data'

                        -- Vínculo por cliente + valor + data ±10 dias (janela maior)
                        WHEN EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                              AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                              AND ABS(DATEDIFF(
                                  COALESCE(t.data_emissao, t.data_competencia),
                                  COALESCE(nf.data_competencia, nf.data_emissao)
                              )) <= 10
                        ) THEN 'vinculo_por_cliente_valor_data_10d'

                        -- Candidato fraco: valor ±5% + data ±15 dias
                        WHEN ({$baseSql}) > 0
                          AND EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                              AND COALESCE(t.valor_liquido, 0)
                                  BETWEEN ({$baseSql}) * 0.95 AND ({$baseSql}) * 1.05
                              AND ABS(DATEDIFF(
                                  COALESCE(t.data_emissao, t.data_competencia),
                                  COALESCE(nf.data_competencia, nf.data_emissao)
                              )) <= 15
                        ) THEN 'baixa_confianca'

                        -- Título existe para cliente mas sem match claro
                        WHEN EXISTS (
                            SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                            WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                        ) THEN 'titulo_nao_lancado'

                        -- Sem título para cliente em nenhum contexto
                        ELSE 'investigar_manual'
                    END AS classificacao
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
         ) _cls
         GROUP BY classificacao
         ORDER BY qtd DESC"
    );
    $stmt->execute($params);
    $report['classificacao_provavel'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['classificacao_provavel'] = ['error' => $e->getMessage()];
}

// ── Seção 9: Top N maiores NFs sem título (para investigação manual) ──────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             nf.nota_key,
             nf.numero_nf,
             COALESCE(nf.nome_cliente, '')                                           AS nome_cliente,
             COALESCE(nf.nome_vendedor, '')                                          AS nome_vendedor,
             COALESCE(nf.chave_nfe, '')                                              AS chave_nfe,
             COALESCE(nf.pedido_id_omie, 0)                                          AS pedido_id_omie,
             DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m-%d') AS data,
             ROUND(({$baseSql}), 2)                                                  AS base_comercial,
             CASE
                 WHEN NOT EXISTS (
                     SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                     WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 ) THEN 'possivel_pagamento_avista'
                 WHEN nf.numero_nf IS NOT NULL AND nf.numero_nf != ''
                   AND EXISTS (
                     SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                     WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                       AND t.numero_documento LIKE CONCAT('%', nf.numero_nf, '%')
                 ) THEN 'titulo_sem_chave_nfe'
                 WHEN EXISTS (
                     SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                     WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                       AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                       AND ABS(DATEDIFF(COALESCE(t.data_emissao, t.data_competencia), COALESCE(nf.data_competencia, nf.data_emissao))) <= 10
                 ) THEN 'vinculo_por_cliente_valor_data'
                 WHEN EXISTS (
                     SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                     WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                 ) THEN 'titulo_nao_lancado'
                 ELSE 'investigar_manual'
             END AS classificacao_provavel
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$baseWhere}
         ORDER BY ({$baseSql}) DESC
         LIMIT :lim"
    );
    $stmt->execute(array_merge($params, [':lim' => $limit]));
    $report['top_nfs_sem_titulo'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $report['top_nfs_sem_titulo'] = ['error' => $e->getMessage()];
}

// ── Seção 10: Detalhe por NF com títulos do cliente (focus mode) ──────────────
if ($focus === 'cliente_tem_titulo_sem_match') {
    try {
        $stmt = $pdo->prepare(
            "SELECT nf.nota_key,
                    nf.numero_nf,
                    COALESCE(nf.pedido_id_omie, 0)                                           AS pedido_id_omie,
                    COALESCE(nf.codigo_cliente_omie, 0)                                      AS codigo_cliente_omie,
                    COALESCE(nf.nome_cliente, '')                                            AS nome_cliente,
                    COALESCE(nf.codigo_vendedor_omie, '')                                    AS codigo_vendedor_omie,
                    COALESCE(nf.nome_vendedor, '')                                           AS nome_vendedor,
                    DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m-%d') AS data_emissao,
                    ROUND(({$baseSql}), 2)                                                   AS base_comercial,
                    CAST((COALESCE(nf.pedido_id_omie, 0) > 0 AND EXISTS (
                        SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                        WHERE t.pedido_id_omie = nf.pedido_id_omie
                          AND t.codigo_cliente_omie = nf.codigo_cliente_omie
                    )) AS UNSIGNED)                                                           AS p1_ok,
                    CAST((COALESCE(nf.pedido_id_omie, 0) > 0 AND EXISTS (
                        SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                        WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                          AND t.numero_documento LIKE CONCAT('%', CAST(nf.pedido_id_omie AS CHAR), '%')
                    )) AS UNSIGNED)                                                           AS p2_ok,
                    CAST((nf.chave_nfe IS NOT NULL AND nf.chave_nfe != '' AND EXISTS (
                        SELECT 1 FROM biomie_titulos_financeiros_canonicos t
                        WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
                          AND t.documento_origem_ref = nf.chave_nfe
                    )) AS UNSIGNED)                                                           AS p4_ok
             FROM biomie_notas_fiscais_canonicas nf
             WHERE {$baseWhere}
             ORDER BY ({$baseSql}) DESC
             LIMIT :lim"
        );
        $stmt->execute(array_merge($params, [':lim' => $limit]));
        $nfDetails = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $titStmt = $pdo->prepare(
            "SELECT t.titulo_key, COALESCE(t.numero_documento, '')      AS numero_documento,
                    COALESCE(t.pedido_id_omie, 0)                       AS pedido_id_omie,
                    COALESCE(t.codigo_pedido_origem, '')                 AS codigo_pedido_origem,
                    COALESCE(t.documento_origem_ref, '')                 AS documento_origem_ref,
                    COALESCE(t.chave_nfe, '')                           AS chave_nfe,
                    COALESCE(t.valor_liquido, 0)                        AS valor_liquido,
                    COALESCE(t.valor_aberto, 0)                         AS valor_aberto,
                    COALESCE(t.status_titulo, '')                       AS status_titulo,
                    DATE_FORMAT(t.data_emissao, '%Y-%m-%d')             AS data_emissao,
                    DATE_FORMAT(t.data_vencimento, '%Y-%m-%d')          AS data_vencimento
             FROM biomie_titulos_financeiros_canonicos t
             WHERE t.codigo_cliente_omie = :cod_cli
             ORDER BY t.data_emissao DESC
             LIMIT 15"
        );

        foreach ($nfDetails as &$nfRow) {
            try {
                $titStmt->execute([':cod_cli' => $nfRow['codigo_cliente_omie']]);
                $nfRow['titulos_cliente'] = $titStmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $nfRow['titulos_cliente'] = [];
            }
        }
        unset($nfRow);
        $report['detalhe_nfs_focus'] = $nfDetails;
    } catch (\Throwable $e) {
        $report['detalhe_nfs_focus'] = ['error' => $e->getMessage()];
    }
}

// ── Seção recomendação ─────────────────────────────────────────────────────────
$totalSemTitulo  = (int) ($report['base_stats']['total_nfs'] ?? 0);
$somaSemTitulo   = (float) ($report['base_stats']['soma_base_comercial'] ?? 0);
$classif         = is_array($report['classificacao_provavel']) ? $report['classificacao_provavel'] : [];
$classifMap      = [];
foreach ($classif as $c) { $classifMap[$c['classificacao']] = (int) $c['qtd']; }

$forteCandidato  = ($classifMap['titulo_sem_chave_nfe'] ?? 0) + ($classifMap['vinculo_por_cliente_valor_data'] ?? 0) + ($classifMap['vinculo_por_cliente_valor_data_10d'] ?? 0);
$medioCandidato  = ($classifMap['baixa_confianca'] ?? 0);
$semCandidato    = ($classifMap['possivel_pagamento_avista'] ?? 0) + ($classifMap['titulo_nao_lancado'] ?? 0) + ($classifMap['investigar_manual'] ?? 0);

$report['recomendacao'] = [
    'total_sem_titulo'    => $totalSemTitulo,
    'soma_impactada'      => $somaSemTitulo,
    'candidato_forte'     => $forteCandidato,
    'candidato_medio'     => $medioCandidato,
    'sem_candidato'       => $semCandidato,
    'proximo_patch'       => $forteCandidato > 0
        ? "Patch Q.10.3: vincular {$forteCandidato} NF(s) com candidato forte (titulo_sem_chave_nfe + vinculo_por_cliente_valor_data)."
        : "Investigar manualmente — sem candidatos automáticos claros.",
];

// ── Output ────────────────────────────────────────────────────────────────────
if ($asJson || !$pretty) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$fmt = function (float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
};

echo PHP_EOL;
echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
echo "  Diagnóstico — NFs sem Título Financeiro — {$start} a {$end}" . PHP_EOL;
echo "  Filtro bridge: " . ($itemsExists ? 'biomie_bridge_nf_titulo_items' : 'chave_nfe direto') . PHP_EOL;
echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL . PHP_EOL;

// Seção 1
$bs = $report['base_stats'];
if (is_array($bs) && !isset($bs['error'])) {
    echo "  ── 1. Base Stats ─────────────────────────────────────────────────" . PHP_EOL;
    printf("  %-38s : %s\n", 'Total NFs sem título', $bs['total_nfs'] ?? 0);
    printf("  %-38s : %s\n", 'Soma Base Comercial NF', $fmt((float)($bs['soma_base_comercial'] ?? 0)));
    printf("  %-38s : %s\n", 'Média Base Comercial NF', $fmt((float)($bs['media_base_comercial'] ?? 0)));
    printf("  %-38s : %s — %s\n", 'Faixa de valor', $fmt((float)($bs['min_base_comercial'] ?? 0)), $fmt((float)($bs['max_base_comercial'] ?? 0)));
    echo PHP_EOL;
}

// Seção 2
if (!empty($report['por_vendedor']) && is_array($report['por_vendedor'])) {
    echo "  ── 2. Por Vendedor (top 10) ──────────────────────────────────────" . PHP_EOL;
    printf("  %-30s %6s  %s\n", 'Vendedor', 'NFs', 'Soma Base Com.');
    echo "  " . str_repeat('─', 55) . PHP_EOL;
    foreach ($report['por_vendedor'] as $v) {
        printf("  %-30s %6d  %s\n", substr($v['nome_vendedor'], 0, 30), (int)$v['qtd_nfs'], $fmt((float)$v['soma_base_comercial']));
    }
    echo PHP_EOL;
}

// Seção 3
if (!empty($report['por_cliente']) && is_array($report['por_cliente'])) {
    echo "  ── 3. Por Cliente (top 10) ───────────────────────────────────────" . PHP_EOL;
    printf("  %-35s %6s  %s\n", 'Cliente', 'NFs', 'Soma Base Com.');
    echo "  " . str_repeat('─', 60) . PHP_EOL;
    foreach ($report['por_cliente'] as $c) {
        printf("  %-35s %6d  %s\n", substr($c['nome_cliente'], 0, 35), (int)$c['qtd_nfs'], $fmt((float)$c['soma_base_comercial']));
    }
    echo PHP_EOL;
}

// Seção 4
if (!empty($report['por_mes']) && is_array($report['por_mes'])) {
    echo "  ── 4. Por Mês ────────────────────────────────────────────────────" . PHP_EOL;
    printf("  %-8s  %6s  %s\n", 'Mês', 'NFs', 'Soma Base Com.');
    echo "  " . str_repeat('─', 40) . PHP_EOL;
    foreach ($report['por_mes'] as $m) {
        printf("  %-8s  %6d  %s\n", $m['mes'], (int)$m['qtd_nfs'], $fmt((float)$m['soma_base_comercial']));
    }
    echo PHP_EOL;
}

// Seção 5
$fv = $report['por_faixa_valor'];
if (is_array($fv) && !isset($fv['error'])) {
    echo "  ── 5. Por Faixa de Valor ─────────────────────────────────────────" . PHP_EOL;
    printf("  %-20s  %6s  %s\n", 'Faixa', 'NFs', 'Soma');
    echo "  " . str_repeat('─', 48) . PHP_EOL;
    $faixas = [
        'Até R$500'          => ['ate_500',    'soma_ate_500'],
        'R$500 – R$2k'       => ['de_500_2k',  'soma_500_2k'],
        'R$2k – R$10k'       => ['de_2k_10k',  'soma_2k_10k'],
        'R$10k – R$50k'      => ['de_10k_50k', 'soma_10k_50k'],
        'Acima de R$50k'     => ['acima_50k',  'soma_acima_50k'],
    ];
    foreach ($faixas as $label => [$cntKey, $somaKey]) {
        printf("  %-20s  %6d  %s\n", $label, (int)($fv[$cntKey] ?? 0), $fmt((float)($fv[$somaKey] ?? 0)));
    }
    echo PHP_EOL;
}

// Seção 6
$car = $report['caracteristicas'];
if (is_array($car) && !isset($car['error'])) {
    echo "  ── 6. Características das NFs ────────────────────────────────────" . PHP_EOL;
    printf("  %-38s : %s\n", 'Com chave_nfe válida (44 chars)', $car['com_chave_nfe_valida'] ?? 0);
    printf("  %-38s : %s\n", 'Sem chave_nfe válida', $car['sem_chave_nfe_valida'] ?? 0);
    printf("  %-38s : %s\n", 'Com pedido_id_omie', $car['com_pedido'] ?? 0);
    printf("  %-38s : %s\n", 'Sem pedido_id_omie', $car['sem_pedido'] ?? 0);
    echo "  " . str_repeat('─', 45) . PHP_EOL;
    printf("  %-38s : %s\n", 'Chave + Pedido (2 estratégias ok)', $car['chave_e_pedido'] ?? 0);
    printf("  %-38s : %s\n", 'Chave sem Pedido', $car['chave_sem_pedido'] ?? 0);
    printf("  %-38s : %s\n", 'Pedido sem Chave', $car['pedido_sem_chave'] ?? 0);
    printf("  %-38s : %s\n", 'Sem Chave nem Pedido (casos difíceis)', $car['sem_chave_nem_pedido'] ?? 0);
    echo PHP_EOL;
}

// Seção 7
echo "  ── 7. Candidatos por Estratégia ──────────────────────────────────" . PHP_EOL;
$stratLabels = [
    'E1_numero_nf_no_documento'         => 'E1: Nº NF no documento do título',
    'E2_cliente_valor_exato_3d'         => 'E2: Cliente + valor ±R$0,01 + data ±3d',
    'E3_cliente_valor_exato_10d'        => 'E3: Cliente + valor ±R$0,01 + data ±10d',
    'E4_cliente_valor_aprox_5pct_15d'   => 'E4: Cliente + valor ±5% + data ±15d',
    'E5_pedido_no_documento'            => 'E5: Nº pedido no documento do título',
    'E6_cliente_sem_titulo_no_banco'    => 'E6: Cliente sem nenhum título no banco',
    'E7_titulo_sem_chave_nfe_para_cliente' => 'E7: Título do cliente sem chave_nfe',
    'E8_payload_bruto_bronze'           => 'E8: Payload bruto bronze',
];
$total = (int)($report['base_stats']['total_nfs'] ?? 0);
foreach ($stratLabels as $key => $label) {
    $cnt = $candidatos[$key] ?? null;
    $pct = ($cnt !== null && $total > 0) ? sprintf(' (%d%%)', (int) round($cnt / $total * 100)) : '';
    $val = $cnt === null ? 'N/A (skip)' : "{$cnt} NFs{$pct}";
    printf("  %-42s : %s\n", $label, $val);
}
echo PHP_EOL;

// Seção 8
echo "  ── 8. Classificação Provável ─────────────────────────────────────" . PHP_EOL;
$classifLabels = [
    'possivel_pagamento_avista'        => 'Provável pagamento à vista',
    'titulo_sem_chave_nfe'             => 'Título existe sem chave_nfe (E1)',
    'vinculo_por_cliente_valor_data'   => 'Vínculo cliente+valor+data ±3d (E2)',
    'vinculo_por_cliente_valor_data_10d' => 'Vínculo cliente+valor+data ±10d (E3)',
    'baixa_confianca'                  => 'Candidato fraco (±5%, ±15d)',
    'titulo_nao_lancado'               => 'Cliente tem título mas sem match',
    'investigar_manual'                => 'Investigar manualmente',
];
echo "  " . str_repeat('─', 58) . PHP_EOL;
foreach ($classif as $c) {
    $label = $classifLabels[$c['classificacao']] ?? $c['classificacao'];
    printf("  %-40s : %4d  %s\n", $label, (int)$c['qtd'], $fmt((float)$c['soma_base_comercial']));
}
echo PHP_EOL;

// Seção 9
if (!empty($report['top_nfs_sem_titulo']) && is_array($report['top_nfs_sem_titulo'])) {
    echo "  ── 9. Top {$limit} NFs Sem Título (maiores valores) ─────────────────" . PHP_EOL;
    printf("  %-8s %-8s %-30s %-12s %-26s\n", 'NF', 'Pedido', 'Cliente', 'Base Com.', 'Classificação');
    echo "  " . str_repeat('─', 88) . PHP_EOL;
    foreach ($report['top_nfs_sem_titulo'] as $r) {
        printf("  %-8s %-8s %-30s %-12s %-26s\n",
            substr($r['numero_nf'] ?? '?', 0, 8),
            $r['pedido_id_omie'] > 0 ? $r['pedido_id_omie'] : '—',
            substr($r['nome_cliente'], 0, 30),
            $fmt((float)$r['base_comercial']),
            $r['classificacao_provavel'] ?? '?'
        );
    }
    echo PHP_EOL;
}

// Seção P: Candidatos por Estratégia de Pedido (focus mode)
if ($focus === 'cliente_tem_titulo_sem_match' && isset($report['candidatos_por_pedido'])) {
    $pc    = $report['candidatos_por_pedido'];
    $total = (int) ($report['base_stats']['total_nfs'] ?? 0);
    echo "  ── P. Candidatos por Estratégia de Pedido (focus: cliente_tem_titulo_sem_match) ──" . PHP_EOL;
    $pLabels = [
        'P1_pedido_id_omie'         => 'P1: pedido_id_omie direto no título  [conf=90]',
        'P2_pedido_numero_documento' => 'P2: pedido_id no numero_documento    [conf=85]',
        'P3_codigo_pedido_origem'    => 'P3: codigo_pedido_origem match       [conf=85]',
        'P4_documento_origem_ref'    => 'P4: documento_origem_ref=chave_nfe  [conf=80]',
        'P5_cliente_pedido_valor'    => 'P5: cliente+pedido+soma±10%         [conf=75 diag]',
        'P6_cliente_mes_valor'       => 'P6: cliente+mês+soma±15%            [conf=60 diag]',
    ];
    foreach ($pLabels as $key => $label) {
        $cnt = $pc[$key] ?? null;
        $pct = ($cnt !== null && $total > 0) ? sprintf(' (%d%%)', (int) round($cnt / $total * 100)) : '';
        $val = $cnt === null ? 'N/A' : "{$cnt} NFs{$pct}";
        printf("  %-52s : %s\n", $label, $val);
    }
    echo PHP_EOL;
}

// Seção 10: Detalhe por NF com títulos do cliente (focus mode)
if ($focus === 'cliente_tem_titulo_sem_match' && !empty($report['detalhe_nfs_focus']) && is_array($report['detalhe_nfs_focus'])) {
    echo "  ── 10. Detalhe NFs × Títulos do Cliente (focus mode) ────────────────" . PHP_EOL;
    foreach ($report['detalhe_nfs_focus'] as $nfRow) {
        $matches = [];
        if ($nfRow['p1_ok']) { $matches[] = 'P1'; }
        if ($nfRow['p2_ok']) { $matches[] = 'P2'; }
        if ($nfRow['p4_ok']) { $matches[] = 'P4'; }
        $matchStr = $matches ? '[' . implode('+', $matches) . ']' : '[sem match]';
        echo "  ┌ NF " . ($nfRow['numero_nf'] ?? '?') . "  Pedido=" . ($nfRow['pedido_id_omie'] ?: '—') .
             "  {$matchStr}" . PHP_EOL;
        printf("  │ Cliente : %s\n", substr($nfRow['nome_cliente'], 0, 60));
        printf("  │ Vendedor: %s\n", substr($nfRow['nome_vendedor'], 0, 50));
        printf("  │ Data    : %s   Base Com.: %s\n",
            $nfRow['data_emissao'] ?? '?',
            $fmt((float) $nfRow['base_comercial'])
        );
        if (!empty($nfRow['titulos_cliente'])) {
            echo "  │ Títulos do cliente:" . PHP_EOL;
            foreach ($nfRow['titulos_cliente'] as $t) {
                $pedStr = $t['pedido_id_omie'] ? "ped={$t['pedido_id_omie']}" : '';
                $cpStr  = $t['codigo_pedido_origem'] ? "orig={$t['codigo_pedido_origem']}" : '';
                $docStr = $t['numero_documento'] ? "doc={$t['numero_documento']}" : '';
                $meta   = implode(' ', array_filter([$pedStr, $cpStr, $docStr]));
                printf("  │   %-30s vl=%s  %s  status=%s  emit=%s\n",
                    substr($t['titulo_key'], 0, 30),
                    number_format((float)$t['valor_liquido'], 2, ',', '.'),
                    $meta,
                    $t['status_titulo'],
                    $t['data_emissao'] ?? '?'
                );
            }
        } else {
            echo "  │ (nenhum título encontrado para este cliente)" . PHP_EOL;
        }
        echo "  └─────────────────────────────────────────────────────────────────" . PHP_EOL;
    }
    echo PHP_EOL;
}

// Recomendação final
$rec = $report['recomendacao'];
echo "  ── Recomendação ──────────────────────────────────────────────────" . PHP_EOL;
printf("  %-30s : %s\n",  'Total sem título', $rec['total_sem_titulo']);
printf("  %-30s : %s\n",  'Soma impactada', $fmt((float)$rec['soma_impactada']));
printf("  %-30s : %s\n",  'Candidato forte', $rec['candidato_forte']);
printf("  %-30s : %s\n",  'Candidato médio', $rec['candidato_medio']);
printf("  %-30s : %s\n",  'Sem candidato', $rec['sem_candidato']);
echo PHP_EOL;
echo "  Próximo patch:" . PHP_EOL;
echo "  " . $rec['proximo_patch'] . PHP_EOL;
echo PHP_EOL;
echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL . PHP_EOL;
