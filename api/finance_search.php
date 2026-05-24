<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\FinanceFilters;
use BIomie\Support\Filters\QueryStringMapper;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

try {
    $today = date('Y-m-d');

    $mapper = new QueryStringMapper(100);
    $state  = $mapper->map($_GET);

    $start = $state->start !== '' ? $state->start : '';
    $end   = $state->end   !== '' ? $state->end   : '';

    if ($start === '' || $end === '') {
        Http::json([
            'ok'    => false,
            'error' => ['code' => 'MISSING_PERIOD', 'message' => 'start e end são obrigatórios.', 'details' => null],
        ], 400);
        return;
    }

    Http::validateDateRange($start, $end);

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json([
            'ok'    => false,
            'error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Banco de dados não configurado.', 'details' => null],
        ], 503);
        return;
    }

    $pdo = Database::connect($config, $conn);

    if (!Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        Http::json([
            'ok'   => true,
            'data' => [],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'total_pages' => 1, 'has_next' => false, 'has_prev' => false],
            'summary'    => ['total_valor' => 0, 'total_recebido' => 0, 'total_aberto' => 0, 'total_vencido' => 0, 'qtd_titulos' => 0, 'qtd_vencidos' => 0],
            'diagnostics' => ['source' => 'table_missing', 'elapsed_ms' => 0, 'filters_applied' => []],
        ]);
        return;
    }

    // ── Sort ─────────────────────────────────────────────────────────────────
    $sortWhitelist = FinanceFilters::sortWhitelist();
    $sortExpr      = $sortWhitelist->resolve($state->sortField);

    // atraso sort: oldest due date first = most overdue first
    if ($state->sortField === 'atraso') {
        $sortDir = strtoupper($state->sortDir) === 'DESC' ? 'ASC' : 'DESC';
    } else {
        $sortDir = strtoupper($state->sortDir) === 'ASC' ? 'ASC' : 'DESC';
    }

    // ── Date field ───────────────────────────────────────────────────────────
    $rawDateField = FilterNormalizer::text((string) ($_GET['date_field'] ?? 'vencimento'), 20);
    $dateCol      = FinanceFilters::resolveDateField($rawDateField);

    // ── Build WHERE ───────────────────────────────────────────────────────────
    $where  = ["{$dateCol} BETWEEN :start AND :end"];
    $params = ['start' => $start, 'end' => $end, 'ref' => $today];

    // Status filter
    $rawStatus = FilterNormalizer::text((string) ($_GET['status'] ?? ''), 20);
    $statusMap = FinanceFilters::statusConditions();
    if ($rawStatus !== '' && isset($statusMap[$rawStatus])) {
        $where[] = $statusMap[$rawStatus];
    }

    // Aging filter (implies open/overdue, so exclude received)
    $rawAging = FilterNormalizer::text((string) ($_GET['aging'] ?? ''), 20);
    $agingMap = FinanceFilters::agingConditions();
    if ($rawAging !== '' && isset($agingMap[$rawAging]) && $rawStatus === '') {
        $where[] = "status_titulo NOT IN ('liquidado','recebido','pago')";
        $where[] = "COALESCE(valor_aberto, 0) > 0";
        $where[] = $agingMap[$rawAging];
    }

    // General search: q searches client, document, origin, vendor
    if ($state->hasQ()) {
        $escaped           = str_replace(['%', '_'], ['\\%', '\\_'], $state->q);
        $params['q_like']  = '%' . $escaped . '%';
        $where[] = '(nome_cliente LIKE :q_like OR numero_documento LIKE :q_like OR nome_vendedor LIKE :q_like OR documento_cliente LIKE :q_like OR documento_origem_ref LIKE :q_like OR COALESCE(codigo_pedido_origem, \'\') LIKE :q_like)';
    }

    // Specific client filter
    if ($state->hasFilter('cliente')) {
        $v = FilterNormalizer::text((string) $state->filterVal('cliente'));
        if ($v !== '') {
            $escaped2            = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_cliente'] = '%' . $escaped2 . '%';
            $where[]             = 'nome_cliente LIKE :f_cliente';
        }
    }

    // Specific vendor filter
    if ($state->hasFilter('vendedor')) {
        $v = FilterNormalizer::text((string) $state->filterVal('vendedor'));
        if ($v !== '') {
            $escaped3             = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_vendedor'] = '%' . $escaped3 . '%';
            $where[]              = 'nome_vendedor LIKE :f_vendedor';
        }
    }

    // Payment method filter
    if ($state->hasFilter('forma_pagamento')) {
        $v = FilterNormalizer::text((string) $state->filterVal('forma_pagamento'));
        if ($v !== '') {
            $escaped4                = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_forma_pag']   = '%' . $escaped4 . '%';
            $where[]                 = 'forma_pagamento LIKE :f_forma_pag';
        }
    }

    // Value range
    $valMin = FilterNormalizer::money((string) ($_GET['valor_min'] ?? ''));
    $valMax = FilterNormalizer::money((string) ($_GET['valor_max'] ?? ''));
    if ($valMin !== null) {
        $params['val_min'] = $valMin;
        $where[]           = 'COALESCE(valor_liquido, valor_pago, 0) >= :val_min';
    }
    if ($valMax !== null) {
        $params['val_max'] = $valMax;
        $where[]           = 'COALESCE(valor_liquido, valor_pago, 0) <= :val_max';
    }

    $whereClause = implode(' AND ', $where);

    // ── SELECT ────────────────────────────────────────────────────────────────
    $selectCols = <<<'SQL'
        titulo_key,
        titulo_id_omie,
        COALESCE(numero_documento, '')            AS documento,
        COALESCE(especie_documento, 'Titulo')     AS especie,
        COALESCE(forma_pagamento, '')             AS forma_pagamento,
        COALESCE(codigo_cliente_omie, 0)          AS cliente_id,
        COALESCE(nome_cliente, 'Cliente nao informado') AS cliente,
        COALESCE(documento_cliente, '')           AS documento_cliente,
        COALESCE(codigo_vendedor_omie, '')        AS vendedor_id,
        COALESCE(nome_vendedor, 'Sem vendedor')   AS vendedor,
        COALESCE(nota_key, '')                    AS nota_key,
        COALESCE(codigo_pedido_origem, '')        AS pedido_ref,
        pedido_id_omie,
        data_emissao,
        data_vencimento,
        data_liquidacao,
        data_competencia,
        COALESCE(status_titulo, 'outro')          AS status,
        COALESCE(valor_pago,    0)                AS valor_pago,
        COALESCE(valor_liquido, valor_pago, 0)    AS valor,
        COALESCE(valor_aberto,  0)                AS valor_aberto,
        DATEDIFF(:ref_days, data_vencimento)      AS dias_atraso,
        CASE
            WHEN status_titulo IN ('liquidado','recebido','pago') THEN 'recebido'
            WHEN data_vencimento IS NULL THEN 'sem_data'
            WHEN data_vencimento  > :ref_bucket THEN 'a_vencer'
            WHEN data_vencimento  = :ref_bucket THEN 'vence_hoje'
            WHEN DATEDIFF(:ref_bucket2, data_vencimento) BETWEEN 1 AND 7   THEN '1_7'
            WHEN DATEDIFF(:ref_bucket2, data_vencimento) BETWEEN 8 AND 15  THEN '8_15'
            WHEN DATEDIFF(:ref_bucket2, data_vencimento) BETWEEN 16 AND 30 THEN '16_30'
            WHEN DATEDIFF(:ref_bucket2, data_vencimento) BETWEEN 31 AND 60 THEN '31_60'
            ELSE '60_plus'
        END AS aging_bucket
    SQL;

    $params['ref_days']    = $today;
    $params['ref_bucket']  = $today;
    $params['ref_bucket2'] = $today;

    $t0 = hrtime(true);

    // ── Count ─────────────────────────────────────────────────────────────────
    $countSql  = "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE {$whereClause}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        if (in_array($k, ['ref_days', 'ref_bucket', 'ref_bucket2'], true)) continue; // not used in COUNT
        $countStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    // bind 'ref' separately (used in status/aging conditions)
    $countStmt->bindValue(':ref', $today);
    $countStmt->execute();
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    $pag = new Pagination($total, $state->page, max(1, min(100, $state->perPage ?: 25)));

    // ── Data query ────────────────────────────────────────────────────────────
    $dataSql  = "SELECT {$selectCols} FROM biomie_titulos_financeiros_canonicos WHERE {$whereClause} ORDER BY {$sortExpr} {$sortDir} LIMIT :per_page OFFSET :offset";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $dataStmt->bindValue(':per_page', $pag->perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset',   $pag->offset,  PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Summary (same WHERE, aggregated) ─────────────────────────────────────
    $summarySql = <<<SQL
        SELECT
            COALESCE(SUM(CASE WHEN status_titulo IN ('liquidado','recebido','pago')
                THEN COALESCE(valor_liquido, valor_pago, 0) ELSE 0 END), 0) AS total_recebido,
            COALESCE(SUM(CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                AND COALESCE(valor_aberto, 0) > 0
                THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0)              AS total_aberto,
            COALESCE(SUM(CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                AND COALESCE(valor_aberto, 0) > 0
                AND data_vencimento < :ref_sum
                THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0)              AS total_vencido,
            COUNT(*)                                                          AS qtd_titulos,
            COUNT(CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                AND COALESCE(valor_aberto, 0) > 0
                AND data_vencimento < :ref_sum2 THEN 1 END)                  AS qtd_vencidos
        FROM biomie_titulos_financeiros_canonicos
        WHERE {$whereClause}
    SQL;

    $sumStmt = $pdo->prepare($summarySql);
    foreach ($params as $k => $v) {
        if (in_array($k, ['ref_days', 'ref_bucket', 'ref_bucket2'], true)) continue;
        $sumStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $sumStmt->bindValue(':ref',      $today);
    $sumStmt->bindValue(':ref_sum',  $today);
    $sumStmt->bindValue(':ref_sum2', $today);
    $sumStmt->execute();
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $queryMs = round((hrtime(true) - $t0) / 1e6, 2);

    // ── Normalize rows ────────────────────────────────────────────────────────
    $data = array_map(static function (array $r): array {
        return [
            'id'               => (string) ($r['titulo_key'] ?? ''),
            'titulo_id_omie'   => $r['titulo_id_omie'] ? (int) $r['titulo_id_omie'] : null,
            'documento'        => (string) ($r['documento'] ?? ''),
            'especie'          => (string) ($r['especie'] ?? 'Titulo'),
            'forma_pagamento'  => (string) ($r['forma_pagamento'] ?? ''),
            'cliente_id'       => (string) ($r['cliente_id'] ?? ''),
            'cliente'          => (string) ($r['cliente'] ?? 'Cliente nao informado'),
            'documento_cliente'=> (string) ($r['documento_cliente'] ?? ''),
            'vendedor_id'      => (string) ($r['vendedor_id'] ?? ''),
            'vendedor'         => (string) ($r['vendedor'] ?? 'Sem vendedor'),
            'nota_key'         => (string) ($r['nota_key'] ?? ''),
            'pedido_ref'       => (string) ($r['pedido_ref'] ?? ''),
            'pedido_id_omie'   => $r['pedido_id_omie'] ? (int) $r['pedido_id_omie'] : null,
            'emissao'          => $r['data_emissao'] ? (string) $r['data_emissao'] : null,
            'vencimento'       => $r['data_vencimento'] ? (string) $r['data_vencimento'] : null,
            'pagamento'        => $r['data_liquidacao'] ? (string) $r['data_liquidacao'] : null,
            'competencia'      => $r['data_competencia'] ? (string) $r['data_competencia'] : null,
            'status'           => (string) ($r['status'] ?? 'outro'),
            'valor'            => (float) ($r['valor'] ?? 0),
            'valor_recebido'   => (float) ($r['valor_pago'] ?? 0),
            'valor_aberto'     => (float) ($r['valor_aberto'] ?? 0),
            'dias_atraso'      => max(0, (int) ($r['dias_atraso'] ?? 0)),
            'aging_bucket'     => (string) ($r['aging_bucket'] ?? 'a_vencer'),
        ];
    }, $rows);

    Http::json([
        'ok'   => true,
        'data' => $data,
        'pagination' => $pag->toArray(),
        'summary' => [
            'total_recebido' => round((float) ($sumRow['total_recebido'] ?? 0), 2),
            'total_aberto'   => round((float) ($sumRow['total_aberto']   ?? 0), 2),
            'total_vencido'  => round((float) ($sumRow['total_vencido']  ?? 0), 2),
            'qtd_titulos'    => (int) ($sumRow['qtd_titulos']  ?? 0),
            'qtd_vencidos'   => (int) ($sumRow['qtd_vencidos'] ?? 0),
        ],
        'filters' => array_merge($state->toApiArray(), [
            'date_field' => $rawDateField,
            'status'     => $rawStatus ?: null,
            'aging'      => $rawAging  ?: null,
            'valor_min'  => $valMin,
            'valor_max'  => $valMax,
        ]),
        'sort' => ['field' => $state->sortField, 'direction' => $state->sortDir],
        'diagnostics' => [
            'source'          => 'canonical',
            'elapsed_ms'      => $queryMs,
            'filters_applied' => $state->toApiArray(),
            'request_id'      => RequestContext::id(),
        ],
    ]);

} catch (\InvalidArgumentException $e) {
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'INVALID_PARAMS', 'message' => $e->getMessage(), 'details' => null],
    ], 400);
} catch (\Throwable $e) {
    if (isset($logger)) {
        $logger->error('finance_search falhou.', [
            'message'    => $e->getMessage(),
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'FINANCE_SEARCH_ERROR', 'message' => 'Não foi possível executar a busca financeira.', 'details' => null],
    ], 500);
}
