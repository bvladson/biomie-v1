<?php

declare(strict_types=1);

use BIomie\CommercialPlanning;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\FlightPlanFilters;
use BIomie\Support\FiscalValueBasis;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

set_time_limit(60);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $start  = $range['start'];
    $end    = $range['end'];
    $months = CommercialPlanning::monthsInRange($start, $end);

    if ($months === []) {
        Http::json(['ok' => false, 'error' => ['code' => 'FLIGHT_PLAN_SEARCH_FAILED', 'message' => 'Período inválido.']], 400);
    }

    // ── Params ────────────────────────────────────────────────────────────────
    $sortWl    = FlightPlanFilters::sortWhitelist();
    $rawSort   = FilterNormalizer::text((string) (Http::query('sort') ?? 'percentual_meta'), 40);
    $sortExpr  = $sortWl->resolve($rawSort);
    $sortDir   = FilterNormalizer::direction((string) (Http::query('direction') ?? 'desc'));
    $page      = max(1, (int) (Http::query('page') ?? 1));
    $perPage   = max(1, min(100, (int) (Http::query('per_page') ?? 25)));

    $fQ          = FilterNormalizer::text((string) (Http::query('q') ?? ''), 100);
    $fVendedor   = FilterNormalizer::text((string) (Http::query('vendedor') ?? ''), 100);
    $fVendedorId = FilterNormalizer::text((string) (Http::query('vendedor_id') ?? ''), 60);
    $fStatusMeta = FilterNormalizer::text((string) (Http::query('status_meta') ?? ''), 30);

    $allowedStatus = ['acima_meta', 'abaixo_meta', 'sem_venda', 'com_venda', ''];
    if (!in_array($fStatusMeta, $allowedStatus, true)) {
        $fStatusMeta = '';
    }

    // ── Connect ───────────────────────────────────────────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);

    $t0 = microtime(true);

    // ── BiVisibilityScope + FiscalValueBasis ──────────────────────────────────
    $visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    $baseSql  = FiscalValueBasis::preComputedBaseSql('nf');

    // ── Month IN clause ───────────────────────────────────────────────────────
    $monthPlaceholders = [];
    $params = [':start' => $start, ':end' => $end];
    foreach ($months as $idx => $m) {
        $key = ':m' . $idx;
        $monthPlaceholders[] = $key;
        $params[$key] = $m;
    }
    $monthsIn = implode(', ', $monthPlaceholders);

    // ── NF aggregation subquery ───────────────────────────────────────────────
    $nfSubquery = <<<SQL
        SELECT
            nf.codigo_vendedor_omie AS vendor_id,
            COALESCE(SUM({$baseSql}), 0) AS realizado,
            COUNT(*) AS nfs,
            COUNT(DISTINCT NULLIF(nf.codigo_pedido_origem, '')) AS pedidos,
            COUNT(DISTINCT nf.cnpj_cliente) AS clientes
        FROM biomie_notas_fiscais_canonicas nf
        WHERE nf.status_fiscal = 'autorizada'
          AND nf.operation_scope = 'commercial'
          AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
          AND {$visCond}
        GROUP BY nf.codigo_vendedor_omie
        SQL;

    // ── Outer WHERE (on targets subquery columns) ─────────────────────────────
    $outerWhere = [];
    $searchTerm = $fQ !== '' ? $fQ : $fVendedor;
    if ($searchTerm !== '') {
        $outerWhere[]    = 't.nome_vendedor LIKE :q';
        $params[':q']    = '%' . $searchTerm . '%';
    }
    if ($fVendedorId !== '') {
        $outerWhere[]         = 't.vendor_id = :vendor_id';
        $params[':vendor_id'] = $fVendedorId;
    }

    // ── HAVING (status_meta on computed columns) ──────────────────────────────
    $havingClauses = [];
    if ($fStatusMeta === 'acima_meta') {
        $havingClauses[] = 'COALESCE(nf_agg.realizado, 0) >= t.meta AND t.meta > 0';
    } elseif ($fStatusMeta === 'abaixo_meta') {
        $havingClauses[] = 'COALESCE(nf_agg.realizado, 0) < t.meta AND COALESCE(nf_agg.realizado, 0) > 0';
    } elseif ($fStatusMeta === 'sem_venda') {
        $havingClauses[] = 'COALESCE(nf_agg.realizado, 0) = 0';
    } elseif ($fStatusMeta === 'com_venda') {
        $havingClauses[] = 'COALESCE(nf_agg.realizado, 0) > 0';
    }

    $whereStr  = $outerWhere    ? 'WHERE '  . implode(' AND ', $outerWhere)  : '';
    $havingStr = $havingClauses ? 'HAVING ' . implode(' AND ', $havingClauses) : '';

    // ── Main query ────────────────────────────────────────────────────────────
    $mainSql = <<<SQL
        SELECT
            t.vendor_id,
            t.nome_vendedor,
            t.meta,
            COALESCE(nf_agg.realizado, 0) AS realizado,
            COALESCE(nf_agg.nfs, 0) AS nfs,
            COALESCE(nf_agg.pedidos, 0) AS pedidos,
            COALESCE(nf_agg.clientes, 0) AS clientes,
            GREATEST(t.meta - COALESCE(nf_agg.realizado, 0), 0) AS gap,
            CASE WHEN t.meta > 0
                 THEN ROUND(COALESCE(nf_agg.realizado, 0) / t.meta * 100, 2)
                 ELSE 0 END AS percentual_meta,
            CASE WHEN COALESCE(nf_agg.nfs, 0) > 0
                 THEN ROUND(COALESCE(nf_agg.realizado, 0) / COALESCE(nf_agg.nfs, 1), 2)
                 ELSE 0 END AS ticket_medio
        FROM (
            SELECT vendor_id, vendor_name AS nome_vendedor, SUM(target_amount) AS meta
            FROM biomie_sales_targets_monthly
            WHERE `year_month` IN ({$monthsIn})
            GROUP BY vendor_id, vendor_name
        ) t
        LEFT JOIN ({$nfSubquery}) nf_agg ON nf_agg.vendor_id = t.vendor_id
        {$whereStr}
        {$havingStr}
        SQL;

    // ── COUNT ─────────────────────────────────────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$mainSql}) _cnt");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $pagination = new Pagination($total, $page, $perPage);

    // ── Fetch page ────────────────────────────────────────────────────────────
    $dataStmt = $pdo->prepare("{$mainSql} ORDER BY {$sortExpr} {$sortDir} LIMIT :limit OFFSET :offset");
    $dataStmt->bindValue(':limit',  $pagination->perPage, \PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $pagination->offset,  \PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Summary across all matching rows ──────────────────────────────────────
    $sumStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(t.meta), 0) AS meta_total,
            COALESCE(SUM(COALESCE(nf_agg.realizado, 0)), 0) AS realizado_total,
            COALESCE(SUM(COALESCE(nf_agg.nfs, 0)), 0) AS nfs_total,
            COUNT(CASE WHEN COALESCE(nf_agg.realizado, 0) >= t.meta AND t.meta > 0 THEN 1 END) AS vendedores_acima_meta,
            COUNT(CASE WHEN COALESCE(nf_agg.realizado, 0) < t.meta AND COALESCE(nf_agg.realizado, 0) > 0 THEN 1 END) AS vendedores_abaixo_meta,
            COUNT(CASE WHEN COALESCE(nf_agg.realizado, 0) = 0 THEN 1 END) AS vendedores_sem_venda
         FROM ({$mainSql}) _sum"
    );
    foreach ($params as $k => $v) {
        $sumStmt->bindValue($k, $v);
    }
    $sumStmt->execute();
    $sumRow = $sumStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $realizadoTotal = (float) ($sumRow['realizado_total'] ?? 0);
    $metaTotal      = (float) ($sumRow['meta_total'] ?? 0);
    $nfsTotal       = (int)   ($sumRow['nfs_total'] ?? 0);

    $queryMs = round((microtime(true) - $t0) * 1000, 1);

    Http::json([
        'ok'   => true,
        'data' => array_map(static fn (array $r): array => [
            'vendor_id'       => (string) ($r['vendor_id'] ?? ''),
            'vendor_name'     => (string) ($r['nome_vendedor'] ?? ''),
            'meta'            => round((float) $r['meta'], 2),
            'realizado'       => round((float) $r['realizado'], 2),
            'gap'             => round((float) $r['gap'], 2),
            'percentual_meta' => (float) $r['percentual_meta'],
            'nfs'             => (int)   $r['nfs'],
            'pedidos'         => (int)   $r['pedidos'],
            'clientes'        => (int)   $r['clientes'],
            'ticket_medio'    => round((float) $r['ticket_medio'], 2),
        ], $rows),
        'summary' => [
            'meta_total'             => round($metaTotal, 2),
            'realizado_total'        => round($realizadoTotal, 2),
            'gap_total'              => round(max($metaTotal - $realizadoTotal, 0), 2),
            'percentual_meta'        => $metaTotal > 0 ? round($realizadoTotal / $metaTotal * 100, 2) : 0.0,
            'nfs_total'              => $nfsTotal,
            'vendedores_acima_meta'  => (int) ($sumRow['vendedores_acima_meta'] ?? 0),
            'vendedores_abaixo_meta' => (int) ($sumRow['vendedores_abaixo_meta'] ?? 0),
            'vendedores_sem_venda'   => (int) ($sumRow['vendedores_sem_venda'] ?? 0),
        ],
        'pagination' => $pagination->toArray(),
        'filters'    => [
            'q'           => $searchTerm,
            'vendedor_id' => $fVendedorId,
            'status_meta' => $fStatusMeta,
            'start'       => $start,
            'end'         => $end,
        ],
        'sort'        => ['field' => $rawSort, 'direction' => $sortDir],
        'diagnostics' => [
            'query_ms'   => $queryMs,
            'source'     => 'biomie_notas_fiscais_canonicas + biomie_sales_targets_monthly',
            'base'       => 'Base Comercial NF',
            'visibility' => 'BiVisibilityScope',
        ],
    ]);

} catch (\InvalidArgumentException $e) {
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'FLIGHT_PLAN_SEARCH_FAILED', 'message' => $e->getMessage()],
    ], 400);
} catch (\Throwable $e) {
    $logger->warning('flight_plan_search.php failed', ['message' => $e->getMessage()]);
    Http::json([
        'ok'    => false,
        'error' => [
            'code'    => 'FLIGHT_PLAN_SEARCH_FAILED',
            'message' => 'Não foi possível atualizar o Plano de Voo agora.',
        ],
    ], 500);
}
