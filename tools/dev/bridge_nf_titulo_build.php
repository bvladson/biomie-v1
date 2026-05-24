<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * tools/dev/bridge_nf_titulo_build.php — Fase Q.10.1 Tarefas 2/3/4
 *
 * Cria/atualiza biomie_bridge_nf_titulo_items (1 NF → N títulos, todos os
 * vínculos por NF) e biomie_bridge_nf_titulo_summary (agregado: soma, recebido,
 * aberto, vencimento, confiança mínima).
 *
 * biomie_bridge_nf_titulo original é preservado como artefato de compatibilidade
 * — este script não o cria nem altera.
 *
 * Estratégia 1 — chave_nfe_exato (confidence 100)
 *   Insere TODOS os títulos com t.chave_nfe = nf.chave_nfe (sem MIN/GROUP).
 *
 * Estratégia 2 — cliente_valor_data3d (confidence 80)
 *   Apenas NFs sem itens após Estratégia 1.
 *   Somente não ambíguo: exatamente 1 título candidato por NF.
 *   Nunca sobrescreve is_manual_override = 1.
 *
 * Usage:
 *   php tools/dev/bridge_nf_titulo_build.php --pretty
 *   php tools/dev/bridge_nf_titulo_build.php --start=2026-01-01 --end=2026-05-31 --pretty
 *   php tools/dev/bridge_nf_titulo_build.php --dry-run --start=2026-01-01 --end=2026-05-31 --pretty
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pretty = in_array('--pretty', $argv, true);
$opts     = getopt('', ['start:', 'end:', 'pretty', 'dry-run', 'strategy:']);
$start    = $opts['start']    ?? null;
$end      = $opts['end']      ?? null;
$dryRun   = isset($opts['dry-run']);
$strategy = trim($opts['strategy'] ?? 'default'); // 'default' | 'pedido'

try {
    $config = new \BIomie\Config();
    $conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = \BIomie\Database::connect($config, $conn);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: DB — " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$visCondNf  = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$baseSql    = FiscalValueBasis::preComputedBaseSql('nf');
$dateWhere  = ($start !== null && $end !== null)
    ? 'AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end'
    : '';
$dateParams = ($start !== null && $end !== null)
    ? [':start' => $start, ':end' => $end]
    : [];

$report   = ['dry_run' => $dryRun, 'strategy' => $strategy, 'period' => compact('start', 'end'), 'steps' => []];
$hasError = false;

$step = function (string $id, string $label, \Closure $fn) use (&$report, &$hasError): void {
    try {
        $r                 = $fn();
        $report['steps'][] = array_merge(['id' => $id, 'label' => $label, 'ok' => true], $r);
    } catch (\Throwable $e) {
        $hasError          = true;
        $report['steps'][] = ['id' => $id, 'label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
};

$hasTable = function (string $name) use ($pdo): bool {
    return (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($name)
    )->fetchColumn();
};

// ── S01: Criar/verificar biomie_bridge_nf_titulo_items ────────────────────────
$step('S01', 'Criar/verificar biomie_bridge_nf_titulo_items', function () use ($pdo, $dryRun, $hasTable): array {
    $exists = $hasTable('biomie_bridge_nf_titulo_items');

    if (!$exists && !$dryRun) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS biomie_bridge_nf_titulo_items (
                id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                nota_key           VARCHAR(100)     NOT NULL,
                titulo_key         VARCHAR(100)     NOT NULL,
                match_method       VARCHAR(50)      NOT NULL DEFAULT 'chave_nfe_exato',
                confidence_score   TINYINT UNSIGNED NOT NULL DEFAULT 100,
                is_manual_override TINYINT(1)       NOT NULL DEFAULT 0,
                created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_nota_titulo (nota_key, titulo_key),
                KEY idx_nota_key   (nota_key),
                KEY idx_titulo_key (titulo_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $exists = true;
    }

    $rows = $exists
        ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items')->fetchColumn()
        : 0;

    return ['table_existed' => $exists ? 'yes' : 'no (dry-run)', 'existing_rows' => $rows];
});

// ── S02: Criar/verificar biomie_bridge_nf_titulo_summary ──────────────────────
$step('S02', 'Criar/verificar biomie_bridge_nf_titulo_summary', function () use ($pdo, $dryRun, $hasTable): array {
    $exists = $hasTable('biomie_bridge_nf_titulo_summary');

    if (!$exists && !$dryRun) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS biomie_bridge_nf_titulo_summary (
                nota_key               VARCHAR(100)     NOT NULL,
                qtd_titulos            SMALLINT         NOT NULL DEFAULT 0,
                valor_titulos_total    DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
                valor_titulos_recebido DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
                valor_titulos_aberto   DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
                menor_vencimento       DATE                  NULL,
                maior_vencimento       DATE                  NULL,
                min_confidence_score   TINYINT UNSIGNED NOT NULL DEFAULT 100,
                best_match_method      VARCHAR(50)      NOT NULL DEFAULT 'chave_nfe_exato',
                has_manual_override    TINYINT(1)       NOT NULL DEFAULT 0,
                updated_at             TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (nota_key),
                KEY idx_min_confidence (min_confidence_score),
                KEY idx_best_method    (best_match_method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $exists = true;
    }

    $rows = $exists
        ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary')->fetchColumn()
        : 0;

    return ['table_existed' => $exists ? 'yes' : 'no (dry-run)', 'existing_rows' => $rows];
});

// ── S03: Estratégia 1 — chave_nfe_exato, TODOS os títulos por NF ─────────────
$step('S03', 'Estratégia 1 — chave_nfe_exato (confidence 100, todos os títulos)', function () use ($pdo, $visCondNf, $dateWhere, $dateParams, $dryRun): array {
    // Candidatos: pares NF↔Título por chave_nfe
    $pairsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_pairs, COUNT(DISTINCT nf.nota_key) AS distinct_nfs
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t
           ON t.chave_nfe = nf.chave_nfe
              AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           {$dateWhere}
           AND {$visCondNf}"
    );
    $pairsStmt->execute($dateParams);
    $counts = $pairsStmt->fetch(\PDO::FETCH_ASSOC) ?: ['total_pairs' => 0, 'distinct_nfs' => 0];

    // NFs com múltiplos títulos (parceladas)
    $multiStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
             SELECT nf.nota_key
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t
               ON t.chave_nfe = nf.chave_nfe
                  AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere}
               AND {$visCondNf}
             GROUP BY nf.nota_key
             HAVING COUNT(t.titulo_key) > 1
         ) _m"
    );
    $multiStmt->execute($dateParams);
    $nfsParceladas = (int) $multiStmt->fetchColumn();

    if ($dryRun) {
        return [
            'pairs_candidates' => (int) $counts['total_pairs'],
            'distinct_nfs'     => (int) $counts['distinct_nfs'],
            'nfs_parceladas'   => $nfsParceladas,
            'affected_rows'    => 0,
            'dry_run'          => true,
        ];
    }

    // Insere TODOS os pares — sem GROUP BY, sem MIN — UNIQUE KEY uk_nota_titulo previne duplicatas
    $stmt = $pdo->prepare(
        "INSERT INTO biomie_bridge_nf_titulo_items
             (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
         SELECT nf.nota_key, t.titulo_key, 'chave_nfe_exato', 100, 0
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t
           ON t.chave_nfe = nf.chave_nfe
              AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           {$dateWhere}
           AND {$visCondNf}
         ON DUPLICATE KEY UPDATE
             confidence_score = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.confidence_score,
                                   GREATEST(biomie_bridge_nf_titulo_items.confidence_score, VALUES(confidence_score))),
             match_method     = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.match_method,
                                   VALUES(match_method)),
             updated_at       = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.updated_at,
                                   CURRENT_TIMESTAMP)"
    );
    $stmt->execute($dateParams);

    return [
        'pairs_candidates' => (int) $counts['total_pairs'],
        'distinct_nfs'     => (int) $counts['distinct_nfs'],
        'nfs_parceladas'   => $nfsParceladas,
        'affected_rows'    => $stmt->rowCount(),
    ];
});

// ── S04: Estratégia 2 — cliente_valor_data3d (NFs sem itens, não ambíguo) ─────
$step('S04', 'Estratégia 2 — cliente_valor_data3d (confidence 80, NFs sem itens, não ambíguo)', function () use ($pdo, $visCondNf, $baseSql, $dateWhere, $dateParams, $dryRun, $hasTable): array {
    $joinCond = "t.codigo_cliente_omie = nf.codigo_cliente_omie
                 AND ABS(COALESCE(t.valor_liquido, 0) - ({$baseSql})) <= 0.01
                 AND ABS(DATEDIFF(
                     COALESCE(t.data_emissao, t.data_competencia),
                     COALESCE(nf.data_competencia, nf.data_emissao)
                 )) <= 3";

    $itemsExists = $hasTable('biomie_bridge_nf_titulo_items');

    // NFs sem itens e com exatamente 1 título candidato (não ambíguo)
    if ($itemsExists) {
        $countSql = "SELECT COUNT(*) FROM (
                         SELECT nf.nota_key
                         FROM biomie_notas_fiscais_canonicas nf
                         JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
                         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                           {$dateWhere}
                           AND {$visCondNf}
                           AND NOT EXISTS (
                               SELECT 1 FROM biomie_bridge_nf_titulo_items bni
                               WHERE bni.nota_key = nf.nota_key
                           )
                         GROUP BY nf.nota_key
                         HAVING COUNT(DISTINCT t.titulo_key) = 1
                     ) _cand";
    } else {
        $countSql = "SELECT COUNT(*) FROM (
                         SELECT nf.nota_key
                         FROM biomie_notas_fiscais_canonicas nf
                         JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
                         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                           {$dateWhere}
                           AND {$visCondNf}
                         GROUP BY nf.nota_key
                         HAVING COUNT(DISTINCT t.titulo_key) = 1
                     ) _cand";
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($dateParams);
    $candidates = (int) $countStmt->fetchColumn();

    if ($dryRun || $candidates === 0) {
        return ['candidates' => $candidates, 'affected_rows' => 0, 'dry_run' => $dryRun];
    }

    // COUNT(DISTINCT) = 1 garante exatamente 1 titulo por NF → MIN() é determinístico
    $stmt = $pdo->prepare(
        "INSERT INTO biomie_bridge_nf_titulo_items
             (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
         SELECT nf.nota_key, MIN(t.titulo_key), 'cliente_valor_data3d', 80, 0
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           {$dateWhere}
           AND {$visCondNf}
           AND NOT EXISTS (
               SELECT 1 FROM biomie_bridge_nf_titulo_items bni
               WHERE bni.nota_key = nf.nota_key
           )
         GROUP BY nf.nota_key
         HAVING COUNT(DISTINCT t.titulo_key) = 1
         ON DUPLICATE KEY UPDATE
             confidence_score = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.confidence_score,
                                   GREATEST(biomie_bridge_nf_titulo_items.confidence_score, VALUES(confidence_score))),
             match_method     = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.match_method,
                                   VALUES(match_method)),
             updated_at       = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                   biomie_bridge_nf_titulo_items.updated_at,
                                   CURRENT_TIMESTAMP)"
    );
    $stmt->execute($dateParams);

    return ['candidates' => $candidates, 'affected_rows' => $stmt->rowCount()];
});

// ── S10–S14: Estratégias por Pedido (somente quando --strategy=pedido) ────────
if ($strategy === 'pedido') {

    // Helper: NOT EXISTS items para esta NF (NFs ainda sem vínculo)
    $semItemsWhere = $hasTable('biomie_bridge_nf_titulo_items')
        ? "AND NOT EXISTS (SELECT 1 FROM biomie_bridge_nf_titulo_items bni WHERE bni.nota_key = nf.nota_key)"
        : '';

    $onDupPedido = function (string $method) use ($dryRun): string {
        return "ON DUPLICATE KEY UPDATE
            confidence_score = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                  biomie_bridge_nf_titulo_items.confidence_score,
                                  GREATEST(biomie_bridge_nf_titulo_items.confidence_score, VALUES(confidence_score))),
            match_method     = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                  biomie_bridge_nf_titulo_items.match_method,
                                  VALUES(match_method)),
            updated_at       = IF(biomie_bridge_nf_titulo_items.is_manual_override = 1,
                                  biomie_bridge_nf_titulo_items.updated_at,
                                  CURRENT_TIMESTAMP)";
    };

    // S10 — P1: pedido_id_omie direto no título (confidence 90, todos os títulos do pedido)
    $step('S10', 'P1 — pedido_id_omie direto (confidence 90, todos os títulos do pedido)', function () use ($pdo, $visCondNf, $dateWhere, $dateParams, $dryRun, $semItemsWhere, $onDupPedido): array {
        $joinCond = "t.pedido_id_omie = nf.pedido_id_omie
                     AND nf.pedido_id_omie > 0
                     AND t.codigo_cliente_omie = nf.codigo_cliente_omie";

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_pairs, COUNT(DISTINCT nf.nota_key) AS distinct_nfs
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere}
               AND {$visCondNf}
               {$semItemsWhere}"
        );
        $countStmt->execute($dateParams);
        $counts = $countStmt->fetch(\PDO::FETCH_ASSOC) ?: ['total_pairs' => 0, 'distinct_nfs' => 0];

        if ($dryRun || (int) $counts['total_pairs'] === 0) {
            return ['pairs_candidates' => (int) $counts['total_pairs'], 'distinct_nfs' => (int) $counts['distinct_nfs'], 'affected_rows' => 0, 'dry_run' => $dryRun];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO biomie_bridge_nf_titulo_items
                 (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
             SELECT nf.nota_key, t.titulo_key, 'pedido_id_omie', 90, 0
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere}
               AND {$visCondNf}
               {$semItemsWhere}
             {$onDupPedido('pedido_id_omie')}"
        );
        $stmt->execute($dateParams);
        return ['pairs_candidates' => (int) $counts['total_pairs'], 'distinct_nfs' => (int) $counts['distinct_nfs'], 'affected_rows' => $stmt->rowCount()];
    });

    // Refresh semItemsWhere after S10 inserts
    $semItemsWhere = $hasTable('biomie_bridge_nf_titulo_items')
        ? "AND NOT EXISTS (SELECT 1 FROM biomie_bridge_nf_titulo_items bni WHERE bni.nota_key = nf.nota_key)"
        : '';

    // S11 — P2: pedido_id no numero_documento (confidence 85, não ambíguo)
    $step('S11', 'P2 — pedido_id no numero_documento (confidence 85, não ambíguo)', function () use ($pdo, $visCondNf, $dateWhere, $dateParams, $dryRun, $semItemsWhere, $onDupPedido): array {
        $joinCond = "t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND nf.pedido_id_omie > 0
                     AND t.numero_documento IS NOT NULL AND t.numero_documento != ''
                     AND t.numero_documento LIKE CONCAT('%', CAST(nf.pedido_id_omie AS CHAR), '%')";

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT nf.nota_key
                 FROM biomie_notas_fiscais_canonicas nf
                 JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
                 WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                   {$dateWhere} AND {$visCondNf} {$semItemsWhere}
                 GROUP BY nf.nota_key
                 HAVING COUNT(DISTINCT t.titulo_key) = 1
             ) _c"
        );
        $countStmt->execute($dateParams);
        $cand = (int) $countStmt->fetchColumn();

        if ($dryRun || $cand === 0) {
            return ['candidates' => $cand, 'affected_rows' => 0, 'dry_run' => $dryRun];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO biomie_bridge_nf_titulo_items
                 (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
             SELECT nf.nota_key, MIN(t.titulo_key), 'pedido_numero_documento', 85, 0
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere} AND {$visCondNf} {$semItemsWhere}
             GROUP BY nf.nota_key
             HAVING COUNT(DISTINCT t.titulo_key) = 1
             {$onDupPedido('pedido_numero_documento')}"
        );
        $stmt->execute($dateParams);
        return ['candidates' => $cand, 'affected_rows' => $stmt->rowCount()];
    });

    // S12 — P3: codigo_pedido_origem = codigo_pedido (confidence 85, não ambíguo)
    $step('S12', 'P3 — codigo_pedido_origem match (confidence 85, não ambíguo)', function () use ($pdo, $visCondNf, $dateWhere, $dateParams, $dryRun, $semItemsWhere, $onDupPedido): array {
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT nf.nota_key
                 FROM biomie_notas_fiscais_canonicas nf
                 LEFT JOIN biomie_pedidos_canonicos p ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
                 JOIN biomie_titulos_financeiros_canonicos t
                   ON t.codigo_cliente_omie = nf.codigo_cliente_omie
                      AND t.codigo_pedido_origem IS NOT NULL AND t.codigo_pedido_origem != ''
                      AND p.codigo_pedido IS NOT NULL AND p.codigo_pedido != ''
                      AND t.codigo_pedido_origem = p.codigo_pedido
                 WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                   {$dateWhere} AND {$visCondNf} {$semItemsWhere}
                 GROUP BY nf.nota_key
                 HAVING COUNT(DISTINCT t.titulo_key) = 1
             ) _c"
        );
        $countStmt->execute($dateParams);
        $cand = (int) $countStmt->fetchColumn();

        if ($dryRun || $cand === 0) {
            return ['candidates' => $cand, 'affected_rows' => 0, 'dry_run' => $dryRun];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO biomie_bridge_nf_titulo_items
                 (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
             SELECT nf.nota_key, MIN(t.titulo_key), 'pedido_codigo_origem', 85, 0
             FROM biomie_notas_fiscais_canonicas nf
             LEFT JOIN biomie_pedidos_canonicos p ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
             JOIN biomie_titulos_financeiros_canonicos t
               ON t.codigo_cliente_omie = nf.codigo_cliente_omie
                  AND t.codigo_pedido_origem IS NOT NULL AND t.codigo_pedido_origem != ''
                  AND p.codigo_pedido IS NOT NULL AND p.codigo_pedido != ''
                  AND t.codigo_pedido_origem = p.codigo_pedido
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere} AND {$visCondNf} {$semItemsWhere}
             GROUP BY nf.nota_key
             HAVING COUNT(DISTINCT t.titulo_key) = 1
             {$onDupPedido('pedido_codigo_origem')}"
        );
        $stmt->execute($dateParams);
        return ['candidates' => $cand, 'affected_rows' => $stmt->rowCount()];
    });

    // S13 — P4: documento_origem_ref = chave_nfe (confidence 80, não ambíguo)
    $step('S13', 'P4 — documento_origem_ref = chave_nfe (confidence 80, não ambíguo)', function () use ($pdo, $visCondNf, $dateWhere, $dateParams, $dryRun, $semItemsWhere, $onDupPedido): array {
        $joinCond = "t.codigo_cliente_omie = nf.codigo_cliente_omie
                     AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
                     AND t.documento_origem_ref IS NOT NULL AND t.documento_origem_ref != ''
                     AND t.documento_origem_ref = nf.chave_nfe";

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT nf.nota_key
                 FROM biomie_notas_fiscais_canonicas nf
                 JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
                 WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                   {$dateWhere} AND {$visCondNf} {$semItemsWhere}
                 GROUP BY nf.nota_key
                 HAVING COUNT(DISTINCT t.titulo_key) = 1
             ) _c"
        );
        $countStmt->execute($dateParams);
        $cand = (int) $countStmt->fetchColumn();

        if ($dryRun || $cand === 0) {
            return ['candidates' => $cand, 'affected_rows' => 0, 'dry_run' => $dryRun];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO biomie_bridge_nf_titulo_items
                 (nota_key, titulo_key, match_method, confidence_score, is_manual_override)
             SELECT nf.nota_key, MIN(t.titulo_key), 'pedido_doc_origem_ref', 80, 0
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t ON {$joinCond}
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere} AND {$visCondNf} {$semItemsWhere}
             GROUP BY nf.nota_key
             HAVING COUNT(DISTINCT t.titulo_key) = 1
             {$onDupPedido('pedido_doc_origem_ref')}"
        );
        $stmt->execute($dateParams);
        return ['candidates' => $cand, 'affected_rows' => $stmt->rowCount()];
    });

    // S14 — P5: cliente + pedido + soma ≈ base_comercial ±10% (confidence 75, diagnóstico, não aplica)
    $step('S14', 'P5 — cliente+pedido+soma±10% (confidence 75, diagnóstico apenas — não aplica)', function () use ($pdo, $visCondNf, $baseSql, $dateWhere, $dateParams, $semItemsWhere): array {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT nf.nota_key)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere} AND {$visCondNf} {$semItemsWhere}
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
        $stmt->execute($dateParams);
        return ['candidates_diag' => (int) $stmt->fetchColumn(), 'affected_rows' => 0, 'note' => 'revisar_manual — não aplicado automaticamente'];
    });

} // end strategy === 'pedido'

// ── S05: Calcular/atualizar biomie_bridge_nf_titulo_summary ───────────────────
$step('S05', 'Calcular/atualizar biomie_bridge_nf_titulo_summary', function () use ($pdo, $dryRun, $hasTable): array {
    $itemsExists   = $hasTable('biomie_bridge_nf_titulo_items');
    $summaryExists = $hasTable('biomie_bridge_nf_titulo_summary');

    if ($dryRun || !$itemsExists || !$summaryExists) {
        $itemsRows   = $itemsExists   ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items')->fetchColumn()   : 0;
        $summaryRows = $summaryExists ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary')->fetchColumn() : 0;
        return ['skipped' => true, 'dry_run' => $dryRun, 'items_rows' => $itemsRows, 'summary_rows' => $summaryRows];
    }

    // Recalcula TODOS os resumos da tabela de itens (idempotente via ON DUPLICATE KEY)
    $stmt = $pdo->prepare(
        "INSERT INTO biomie_bridge_nf_titulo_summary
             (nota_key, qtd_titulos, valor_titulos_total, valor_titulos_recebido,
              valor_titulos_aberto, menor_vencimento, maior_vencimento,
              min_confidence_score, best_match_method, has_manual_override, updated_at)
         SELECT
             bni.nota_key,
             COUNT(DISTINCT bni.titulo_key)                                             AS qtd_titulos,
             ROUND(SUM(COALESCE(t.valor_liquido, 0)), 2)                                AS valor_titulos_total,
             ROUND(GREATEST(0,
                 SUM(COALESCE(t.valor_liquido, 0)) - SUM(COALESCE(t.valor_aberto, 0))
             ), 2)                                                                       AS valor_titulos_recebido,
             ROUND(SUM(COALESCE(t.valor_aberto, 0)), 2)                                 AS valor_titulos_aberto,
             MIN(t.data_vencimento)                                                      AS menor_vencimento,
             MAX(t.data_vencimento)                                                      AS maior_vencimento,
             MIN(bni.confidence_score)                                                   AS min_confidence_score,
             (SELECT bni2.match_method
              FROM biomie_bridge_nf_titulo_items bni2
              WHERE bni2.nota_key = bni.nota_key
              ORDER BY bni2.confidence_score DESC, bni2.id ASC
              LIMIT 1)                                                                   AS best_match_method,
             MAX(bni.is_manual_override)                                                 AS has_manual_override,
             CURRENT_TIMESTAMP
         FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bni.titulo_key
         GROUP BY bni.nota_key
         ON DUPLICATE KEY UPDATE
             qtd_titulos            = VALUES(qtd_titulos),
             valor_titulos_total    = VALUES(valor_titulos_total),
             valor_titulos_recebido = VALUES(valor_titulos_recebido),
             valor_titulos_aberto   = VALUES(valor_titulos_aberto),
             menor_vencimento       = VALUES(menor_vencimento),
             maior_vencimento       = VALUES(maior_vencimento),
             min_confidence_score   = VALUES(min_confidence_score),
             best_match_method      = VALUES(best_match_method),
             has_manual_override    = VALUES(has_manual_override),
             updated_at             = CURRENT_TIMESTAMP"
    );
    $stmt->execute();

    $summaryTotal = (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary')->fetchColumn();

    return [
        'affected_rows'    => $stmt->rowCount(),
        'summary_total_nfs' => $summaryTotal,
    ];
});

// ── S06: Relatório final — distribuição, divergências reais após agregação ────
$step('S06', 'Relatório final — estatísticas e divergências reais após agregação', function () use ($pdo, $visCondNf, $baseSql, $dateWhere, $dateParams, $hasTable): array {
    $itemsExists   = $hasTable('biomie_bridge_nf_titulo_items');
    $summaryExists = $hasTable('biomie_bridge_nf_titulo_summary');

    $result = [];

    if ($itemsExists) {
        $result['items_total'] = (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items')->fetchColumn();
        $result['items_nfs']   = (int) $pdo->query('SELECT COUNT(DISTINCT nota_key) FROM biomie_bridge_nf_titulo_items')->fetchColumn();
    } else {
        $result['items_total'] = 0;
        $result['items_nfs']   = 0;
    }

    if ($summaryExists) {
        $result['summary_total']    = (int)   $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary')->fetchColumn();
        $result['nfs_parceladas']   = (int)   $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary WHERE qtd_titulos > 1')->fetchColumn();
        $result['avg_qtd_titulos']  = (float) $pdo->query('SELECT ROUND(AVG(qtd_titulos), 2) FROM biomie_bridge_nf_titulo_summary')->fetchColumn();

        // Divergências reais após agregação: compara base_comercial vs soma_titulos (filtro de período)
        $divStmt = $pdo->prepare(
            "SELECT
                 COUNT(*)                                                                                      AS nfs_com_summary,
                 SUM(CASE WHEN ABS(({$baseSql}) - s.valor_titulos_total) <= 0.01 THEN 1 ELSE 0 END)           AS conciliadas_ok,
                 SUM(CASE WHEN ABS(({$baseSql}) - s.valor_titulos_total) >  0.01 THEN 1 ELSE 0 END)           AS com_divergencia,
                 ROUND(AVG(ABS(({$baseSql}) - s.valor_titulos_total)), 2)                                      AS diferenca_media,
                 ROUND(MAX(ABS(({$baseSql}) - s.valor_titulos_total)), 2)                                      AS diferenca_max,
                 ROUND(SUM(ABS(({$baseSql}) - s.valor_titulos_total)), 2)                                      AS diferenca_total_acumulada
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = nf.nota_key
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               {$dateWhere}
               AND {$visCondNf}"
        );
        $divStmt->execute($dateParams);
        $div = $divStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $result = array_merge($result, $div);
    } else {
        $result['summary_total']  = 0;
        $result['nfs_parceladas'] = 0;
    }

    return $result;
});

// ── Output ────────────────────────────────────────────────────────────────────
if ($pretty) {
    echo PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo "  Bridge NF ↔ Título — Items + Summary" . ($dryRun ? '  [DRY RUN — sem escrita]' : '') . "  [strategy={$strategy}]" . PHP_EOL;
    if ($start && $end) {
        echo "  Período : {$start} a {$end}" . PHP_EOL;
    } else {
        echo "  Período : completo (sem filtro de data)" . PHP_EOL;
    }
    echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;

    foreach ($report['steps'] as $s) {
        $sym = $s['ok'] ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m";
        echo "  [{$sym}] [{$s['id']}] {$s['label']}" . PHP_EOL;
        foreach ($s as $k => $v) {
            if (in_array($k, ['id', 'label', 'ok'], true) || is_array($v)) { continue; }
            printf("        %-36s : %s\n", $k, (string) $v);
        }
        echo PHP_EOL;
    }

    // Final summary block from S06
    $s06 = null;
    foreach ($report['steps'] as $s) {
        if ($s['id'] === 'S06' && $s['ok']) { $s06 = $s; break; }
    }

    if ($s06) {
        echo "  ── Resumo Bridge (items + summary) ─────────────────────────────" . PHP_EOL;
        printf("  %-38s : %s\n",  'Itens na bridge (pares NF↔Título)',       $s06['items_total']  ?? 0);
        printf("  %-38s : %s\n",  'NFs vinculadas (items)',                   $s06['items_nfs']    ?? 0);
        printf("  %-38s : %s\n",  'NFs no summary',                           $s06['summary_total'] ?? 0);
        printf("  %-38s : %s\n",  'NFs parceladas (> 1 título)',              $s06['nfs_parceladas'] ?? 0);
        if (isset($s06['avg_qtd_titulos'])) {
            printf("  %-38s : %s\n", 'Média títulos/NF',                     $s06['avg_qtd_titulos']);
        }
        if (isset($s06['nfs_com_summary'])) {
            echo PHP_EOL;
            echo "  ── Divergências reais após agregação (período filtrado) ─────────" . PHP_EOL;
            printf("  %-38s : %s\n",    'NFs com summary no período',          $s06['nfs_com_summary']           ?? 0);
            printf("  %-38s : %s\n",    'OK após soma (|dif| ≤ R$0,01)',       $s06['conciliadas_ok']            ?? 0);
            printf("  %-38s : %s\n",    'Divergência real após soma',          $s06['com_divergencia']           ?? 0);
            printf("  %-38s : R$ %.2f\n", 'Diferença média',                  (float) ($s06['diferenca_media']  ?? 0));
            printf("  %-38s : R$ %.2f\n", 'Diferença máxima',                 (float) ($s06['diferenca_max']    ?? 0));
            printf("  %-38s : R$ %.2f\n", 'Divergência acumulada',            (float) ($s06['diferenca_total_acumulada'] ?? 0));
        }
        echo PHP_EOL;
    }

    if ($hasError) {
        echo "\033[31m✘ Erros encontrados. Verifique os steps acima.\033[0m" . PHP_EOL;
    } else {
        echo "\033[32m✔ Bridge items + summary construída com sucesso.\033[0m" . PHP_EOL;
    }
    echo PHP_EOL;
    echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
    echo PHP_EOL;
} else {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit($hasError ? 1 : 0);
