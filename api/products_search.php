<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\ProductFilters;
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

    // Verify tables exist
    if (!Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas') || !Http::tableExists($pdo, 'biomie_notas_fiscais_itens_canonicos')) {
        Http::json([
            'ok'    => false,
            'error' => ['code' => 'TABLES_UNAVAILABLE', 'message' => 'Tabelas canonicas indisponíveis.', 'details' => null],
        ], 503);
        return;
    }

    // Detect operation_scope column availability (same as ProductMixReadRepository)
    $hasScope = false;
    try {
        $hasScope = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'biomie_notas_fiscais_canonicas'
               AND COLUMN_NAME  = 'operation_scope'"
        )->fetchColumn() > 0;
    } catch (Throwable) {}
    $scope = in_array($state->scope, ['commercial', 'remittance', 'all'], true) ? $state->scope : 'commercial';
    $scopeFilter = '';
    if ($hasScope) {
        $scopeFilter = match ($scope) {
            'remittance' => "\n              AND n.operation_scope = 'remittance'",
            'all' => "\n              AND n.operation_scope IN ('commercial', 'remittance')",
            default => "\n              AND n.operation_scope = 'commercial'",
        };
    }
    // Fase L: exclude non-commercial business units (e.g. Licitação) by default.
    $visibilityFilter = "\n              AND " . BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'n');

    $sortWhitelist = ProductFilters::sortWhitelist();
    $sortExpr      = $sortWhitelist->resolve($state->sortField);
    $sortDir       = strtoupper($state->sortDir) === 'ASC' ? 'ASC' : 'DESC';

    // ── WHERE predicates ──────────────────────────────────────────────────
    $where  = [
        "n.status_fiscal = 'autorizada'",
        'COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end',
    ];
    $params = ['start' => $start, 'end' => $end];

    // q — search product description and code
    if ($state->hasQ()) {
        $escaped           = str_replace(['%', '_'], ['\\%', '\\_'], $state->q);
        $like              = '%' . $escaped . '%';
        $params['q_desc']  = $like;
        $params['q_code']  = $like;
        $where[]           = '(i.descricao_produto LIKE :q_desc OR i.codigo_produto LIKE :q_code)';
    }

    // produto — product description only
    if ($state->hasFilter('produto')) {
        $v = FilterNormalizer::text((string) $state->filterVal('produto'));
        if ($v !== '') {
            $escaped           = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_prod']  = '%' . $escaped . '%';
            $where[]           = 'i.descricao_produto LIKE :f_prod';
        }
    }

    // sku — product code only
    if ($state->hasFilter('sku')) {
        $v = FilterNormalizer::text((string) $state->filterVal('sku'));
        if ($v !== '') {
            $escaped          = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_sku']  = '%' . $escaped . '%';
            $where[]          = 'i.codigo_produto LIKE :f_sku';
        }
    }

    // vendedor — vendor name search (filters NFs at join level)
    if ($state->hasFilter('vendedor')) {
        $v = FilterNormalizer::text((string) $state->filterVal('vendedor'));
        if ($v !== '') {
            $escaped              = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_vendedor'] = '%' . $escaped . '%';
            $where[]              = 'n.nome_vendedor LIKE :f_vendedor';
        }
    }

    // cliente — client name search (filters NFs at join level)
    if ($state->hasFilter('cliente')) {
        $v = FilterNormalizer::text((string) $state->filterVal('cliente'));
        if ($v !== '') {
            $escaped             = str_replace(['%', '_'], ['\\%', '\\_'], $v);
            $params['f_cliente'] = '%' . $escaped . '%';
            $where[]             = 'n.nome_cliente LIKE :f_cliente';
        }
    }

    $whereClause = implode(' AND ', $where);

    // ── HAVING predicates (post-aggregation) ──────────────────────────────
    $having = [];

    if ($state->hasFilter('min_quantidade')) {
        $v = FilterNormalizer::money((string) $state->filterVal('min_quantidade'));
        if ($v !== null) {
            $having[]              = 'quantity >= :min_qty';
            $params['min_qty']     = $v;
        }
    }
    if ($state->hasFilter('max_quantidade')) {
        $v = FilterNormalizer::money((string) $state->filterVal('max_quantidade'));
        if ($v !== null) {
            $having[]              = 'quantity <= :max_qty';
            $params['max_qty']     = $v;
        }
    }
    if ($state->hasFilter('min_revenue')) {
        $v = FilterNormalizer::money((string) $state->filterVal('min_revenue'));
        if ($v !== null) {
            $having[]              = 'revenue >= :min_rev';
            $params['min_rev']     = $v;
        }
    }
    if ($state->hasFilter('max_revenue')) {
        $v = FilterNormalizer::money((string) $state->filterVal('max_revenue'));
        if ($v !== null) {
            $having[]              = 'revenue <= :max_rev';
            $params['max_rev']     = $v;
        }
    }

    $havingClause = $having ? ('HAVING ' . implode(' AND ', $having)) : '';

    // ── Core SELECT ───────────────────────────────────────────────────────
    $coreSelect = <<<SQL
        SELECT
            NULLIF(TRIM(COALESCE(i.codigo_produto, '')), '')                   AS product_code,
            COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao') AS description,
            :scope_label                                                       AS scope_label,
            :scope_value                                                       AS scope,
            COALESCE(SUM(i.quantidade),    0)                                  AS quantity,
            COALESCE(SUM(i.valor_total),   0)                                  AS revenue,
            COALESCE(SUM(i.valor_desconto),0)                                  AS discount_total,
            COUNT(DISTINCT n.nota_key)                                         AS invoices_count,
            COUNT(DISTINCT n.codigo_cliente_omie)                              AS customers_count,
            COUNT(DISTINCT n.codigo_vendedor_omie)                             AS vendors_count,
            CASE
                WHEN COUNT(DISTINCT n.nota_key) > 0
                THEN COALESCE(SUM(i.valor_total), 0) / COUNT(DISTINCT n.nota_key)
                ELSE 0
            END AS avg_ticket
        FROM biomie_notas_fiscais_itens_canonicos i
        INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
        WHERE {$whereClause}{$scopeFilter}{$visibilityFilter}
        GROUP BY
            NULLIF(TRIM(COALESCE(i.codigo_produto, '')), ''),
            COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao')
        {$havingClause}
    SQL;

    // ── Count ─────────────────────────────────────────────────────────────
    $t0 = hrtime(true);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM ({$coreSelect}) AS sub");
    foreach ($params as $k => $v) {
        $countStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $countStmt->bindValue(':scope_label', $scope === 'remittance' ? 'Remessas' : ($scope === 'all' ? 'Todas' : 'Orc-PD'));
    $countStmt->bindValue(':scope_value', $scope);
    $countStmt->execute();
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    $pag = new Pagination($total, $state->page, $state->perPage);

    // ── Data query ────────────────────────────────────────────────────────
    $dataSql  = "{$coreSelect} ORDER BY {$sortExpr} {$sortDir} LIMIT :per_page OFFSET :offset";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $dataStmt->bindValue(':scope_label', $scope === 'remittance' ? 'Remessas' : ($scope === 'all' ? 'Todas' : 'Orc-PD'));
    $dataStmt->bindValue(':scope_value', $scope);
    $dataStmt->bindValue(':per_page', $state->perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset',   $pag->offset,    PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $queryMs = round((hrtime(true) - $t0) / 1e6, 2);

    $data = array_map(static function (array $row): array {
        return [
            'product_code'    => (string) ($row['product_code'] ?? ''),
            'description'     => (string) ($row['description'] ?? 'Produto sem descricao'),
            'scope'           => (string) ($row['scope'] ?? $scope),
            'scope_label'     => (string) ($row['scope_label'] ?? ($scope === 'remittance' ? 'Remessas' : ($scope === 'all' ? 'Todas' : 'Orc-PD'))),
            'quantity'        => (float)  ($row['quantity'] ?? 0),
            'revenue'         => (float)  ($row['revenue'] ?? 0),
            'discount_total'  => (float)  ($row['discount_total'] ?? 0),
            'invoices_count'  => (int)    ($row['invoices_count'] ?? 0),
            'customers_count' => (int)    ($row['customers_count'] ?? 0),
            'vendors_count'   => (int)    ($row['vendors_count'] ?? 0),
            'avg_ticket'      => (float)  ($row['avg_ticket'] ?? 0),
        ];
    }, $rows);

    Http::json([
        'ok'         => true,
        'data'       => $data,
        'pagination' => $pag->toArray(),
        'filters'    => $state->toApiArray(),
        'scope'      => $scope,
        'sort'       => ['field' => $state->sortField, 'direction' => $state->sortDir],
        'diagnostics' => [
            'query_ms'  => $queryMs,
            'source'    => 'canonical',
            'base'      => 'SUM(item.valor_total) — receita por produto',
            'period'    => ['start' => $start, 'end' => $end],
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
        $logger->error('products_search falhou.', [
            'message'    => $e->getMessage(),
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'PRODUCTS_SEARCH_FAILED', 'message' => 'Não foi possível aplicar os filtros agora.', 'details' => null],
    ], 500);
}
