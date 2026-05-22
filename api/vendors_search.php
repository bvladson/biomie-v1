<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\VendorFilters;
use BIomie\Support\Filters\QueryStringMapper;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

try {
    $mapper = new QueryStringMapper();
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

    $sortWhitelist = VendorFilters::sortWhitelist();
    $sortExpr      = $sortWhitelist->resolve($state->sortField);
    $sortDir       = strtoupper($state->sortDir) === 'ASC' ? 'ASC' : 'DESC';

    // ── WHERE predicates ──────────────────────────────────────────────────
    $where  = [
        "nf.status_fiscal = 'autorizada'",
        "nf.operation_scope = 'commercial'",
        'nf.data_competencia BETWEEN :start AND :end',
        // Fase L: exclude non-commercial business units (e.g. Licitação) by default.
        BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf'),
    ];
    $params = ['start' => $start, 'end' => $end];

    // q — vendor name text search
    if ($state->hasQ()) {
        $escaped        = str_replace(['%', '_'], ['\\%', '\\_'], $state->q);
        $params['q_like'] = '%' . $escaped . '%';
        $where[]          = 'COALESCE(v.nome, nf.nome_vendedor) LIKE :q_like';
    }

    $whereClause = implode(' AND ', $where);

    // ── HAVING predicates (post-aggregation) ──────────────────────────────
    $having = [];

    if ($state->hasFilter('min_base_comercial')) {
        $v = FilterNormalizer::money((string) $state->filterVal('min_base_comercial'));
        if ($v !== null) {
            $having[]            = 'base_comercial >= :min_bc';
            $params['min_bc']    = $v;
        }
    }
    if ($state->hasFilter('max_base_comercial')) {
        $v = FilterNormalizer::money((string) $state->filterVal('max_base_comercial'));
        if ($v !== null) {
            $having[]            = 'base_comercial <= :max_bc';
            $params['max_bc']    = $v;
        }
    }
    if ($state->hasFilter('min_ticket')) {
        $v = FilterNormalizer::money((string) $state->filterVal('min_ticket'));
        if ($v !== null) {
            $having[]            = 'avg_ticket >= :min_tk';
            $params['min_tk']    = $v;
        }
    }
    if ($state->hasFilter('max_ticket')) {
        $v = FilterNormalizer::money((string) $state->filterVal('max_ticket'));
        if ($v !== null) {
            $having[]            = 'avg_ticket <= :max_tk';
            $params['max_tk']    = $v;
        }
    }

    $havingClause = $having ? ('HAVING ' . implode(' AND ', $having)) : '';

    // ── Core SELECT ───────────────────────────────────────────────────────
    $baseComercialExpr = <<<'SQL'
        COALESCE(SUM(
            nf.valor_total
            - COALESCE(nf.valor_frete, 0)
            - COALESCE(nf.valor_outras_despesas, 0)
            - COALESCE(nf.valor_seguro, 0)
        ), 0)
    SQL;

    $coreSelect = <<<SQL
        SELECT
            COALESCE(v.codigo_omie, nf.codigo_vendedor_omie, '')    AS vendor_id,
            COALESCE(v.nome, nf.nome_vendedor, 'Sem vendedor')      AS vendor_name,
            COUNT(DISTINCT nf.nota_key)                              AS invoices_count,
            COUNT(DISTINCT nf.codigo_cliente_omie)                   AS customers_count,
            COALESCE(SUM(nf.valor_mercadorias), 0)                   AS billed_merchandise,
            COALESCE(SUM(nf.valor_total), 0)                         AS billed_value,
            $baseComercialExpr                                       AS base_comercial,
            CASE
                WHEN COUNT(DISTINCT nf.nota_key) > 0
                THEN $baseComercialExpr / COUNT(DISTINCT nf.nota_key)
                ELSE 0
            END AS avg_ticket
        FROM biomie_notas_fiscais_canonicas nf
        LEFT JOIN biomie_vendedores_omie v ON v.codigo_omie = nf.codigo_vendedor_omie
        WHERE $whereClause
        GROUP BY
            COALESCE(v.codigo_omie, nf.codigo_vendedor_omie, ''),
            COALESCE(v.nome, nf.nome_vendedor, 'Sem vendedor')
        $havingClause
    SQL;

    // ── Count (total rows without LIMIT) ──────────────────────────────────
    $t0 = hrtime(true);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM ($coreSelect) AS sub");
    foreach ($params as $k => $v) {
        $countStmt->bindValue(':' . $k, $v, is_float($v) ? PDO::PARAM_STR : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    $pag = new Pagination($total, $state->page, $state->perPage);

    // ── Data query ────────────────────────────────────────────────────────
    $dataSql  = "$coreSelect ORDER BY $sortExpr $sortDir LIMIT :per_page OFFSET :offset";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $dataStmt->bindValue(':per_page', $state->perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset',   $pag->offset,    PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $queryMs = round((hrtime(true) - $t0) / 1e6, 2);

    $data = array_map(static function (array $row): array {
        return [
            'vendor_id'          => (string) ($row['vendor_id'] ?? ''),
            'vendor_ref'         => (string) ($row['vendor_id'] ?? ''),
            'vendor_name'        => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
            'invoices_count'     => (int)    ($row['invoices_count'] ?? 0),
            'customers_count'    => (int)    ($row['customers_count'] ?? 0),
            'billed_merchandise' => (float)  ($row['billed_merchandise'] ?? 0),
            'billed_value'       => (float)  ($row['billed_value'] ?? 0),
            'base_comercial'     => (float)  ($row['base_comercial'] ?? 0),
            'avg_ticket'         => (float)  ($row['avg_ticket'] ?? 0),
        ];
    }, $rows);

    Http::json([
        'ok'         => true,
        'data'       => $data,
        'pagination' => $pag->toArray(),
        'filters'    => $state->toApiArray(),
        'sort'       => ['field' => $state->sortField, 'direction' => $state->sortDir],
        'diagnostics' => [
            'query_ms' => $queryMs,
            'source'   => 'canonical',
            'period'   => ['start' => $start, 'end' => $end],
            'request_id' => RequestContext::id(),
        ],
    ]);

} catch (InvalidArgumentException $e) {
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'INVALID_PARAMS', 'message' => $e->getMessage(), 'details' => null],
    ], 400);
} catch (Throwable $e) {
    if (isset($logger)) {
        $logger->error('vendors_search falhou.', [
            'message'    => $e->getMessage(),
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'VENDORS_SEARCH_FAILED', 'message' => 'Não foi possível executar a busca de vendedores.', 'details' => null],
    ], 500);
}
