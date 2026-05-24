<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\FiscalValueBasis;

/**
 * api/reconciliation_pending.php — Fase Q.10.4
 *
 * Retorna NFs comerciais sem título financeiro real com ação recomendada.
 * Read-only — não altera nenhum dado.
 *
 * Params:
 *   start, end         — período (obrigatório)
 *   class              — 'all' | 'titulo_nao_lancado' | 'possivel_pagamento_avista'
 *   vendedor           — filtro texto
 *   cliente            — filtro texto
 *   page, per_page     — paginação
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

set_time_limit(30);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end   = $range['end'];

    $class     = FilterNormalizer::text((string) (Http::query('class')    ?? 'all'), 30);
    $fVendedor = FilterNormalizer::text((string) (Http::query('vendedor') ?? ''),   100);
    $fCliente  = FilterNormalizer::text((string) (Http::query('cliente')  ?? ''),   100);
    $page      = max(1, (int) (Http::query('page')     ?? 1));
    $perPage   = max(1, min(100, (int) (Http::query('per_page') ?? 25)));

    if (!in_array($class, ['all', 'titulo_nao_lancado', 'possivel_pagamento_avista'], true)) {
        $class = 'all';
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);

    $t0 = microtime(true);

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

    $whereBase  = "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
                   AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                   AND {$visCondNf}
                   AND {$semTituloFilter}";
    $extraWhere = '';
    $params     = [':start' => $start, ':end' => $end];

    if ($class === 'titulo_nao_lancado') {
        $extraWhere .= " AND EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tf WHERE _tf.codigo_cliente_omie = nf.codigo_cliente_omie)";
    } elseif ($class === 'possivel_pagamento_avista') {
        $extraWhere .= " AND NOT EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tf WHERE _tf.codigo_cliente_omie = nf.codigo_cliente_omie)";
    }

    if ($fVendedor !== '') {
        $extraWhere     .= ' AND nf.nome_vendedor LIKE :vend';
        $params[':vend'] = '%' . $fVendedor . '%';
    }
    if ($fCliente !== '') {
        $extraWhere      .= ' AND nf.nome_cliente LIKE :cli';
        $params[':cli']   = '%' . $fCliente . '%';
    }

    $fullWhere = $whereBase . $extraWhere;

    // Total count
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf WHERE {$fullWhere}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $pagination = Pagination::compute($total, $page, $perPage);
    $offset     = ($page - 1) * $perPage;

    // Summary aggregates (always over full period, ignoring pagination)
    $sumStmt = $pdo->prepare(
        "SELECT
             COUNT(*)                   AS total_nfs,
             ROUND(SUM({$baseSql}), 2)  AS total_base_comercial,
             SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie) THEN 1 ELSE 0 END) AS pagamento_avista,
             SUM(CASE WHEN     EXISTS (SELECT 1 FROM biomie_titulos_financeiros_canonicos _tc WHERE _tc.codigo_cliente_omie = nf.codigo_cliente_omie) THEN 1 ELSE 0 END) AS titulo_nao_lancado
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$whereBase}{$extraWhere}"
    );
    $sumStmt->execute($params);
    $summary = $sumStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    // Page data
    $dataStmt = $pdo->prepare(
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
             ({$acaoSql})                                                              AS acao_recomendada
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$fullWhere}
         ORDER BY ({$baseSql}) DESC, COALESCE(nf.data_competencia, nf.data_emissao) DESC
         LIMIT :limit OFFSET :offset"
    );
    $dataStmt->execute(array_merge($params, [':limit' => $perPage, ':offset' => $offset]));
    $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

    $elapsed = round(microtime(true) - $t0, 3);

    Http::json([
        'start'      => $start,
        'end'        => $end,
        'class'      => $class,
        'summary'    => [
            'total_nfs'             => (int)   ($summary['total_nfs']           ?? 0),
            'total_base_comercial'  => (float) ($summary['total_base_comercial'] ?? 0),
            'titulo_nao_lancado'    => (int)   ($summary['titulo_nao_lancado']   ?? 0),
            'pagamento_avista'      => (int)   ($summary['pagamento_avista']     ?? 0),
        ],
        'pagination' => $pagination,
        'data'       => array_map(function (array $r): array {
            return [
                'nota_key'         => $r['nota_key'],
                'numero_nf'        => $r['numero_nf']        ?? '',
                'data_emissao'     => $r['data_emissao']     ?? '',
                'pedido_id_omie'   => (int)   $r['pedido_id_omie'],
                'nome_cliente'     => $r['nome_cliente']     ?? '',
                'codigo_cliente'   => (int)   $r['codigo_cliente_omie'],
                'nome_vendedor'    => $r['nome_vendedor']    ?? '',
                'base_comercial'   => (float) $r['base_comercial'],
                'classificacao'    => $r['classificacao']    ?? '',
                'acao_recomendada' => $r['acao_recomendada'] ?? '',
            ];
        }, $rows),
        'diagnostics' => ['elapsed_s' => $elapsed, 'items_bridge_active' => $itemsExists],
    ]);
} catch (\Throwable $e) {
    Http::internalError('reconciliation_pending', $e->getMessage());
}
