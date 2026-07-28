<?php

declare(strict_types=1);

/**
 * api/geo_territory_evolution.php
 *
 * GEO.R1.8.2 — Evolução mensal de vendas de um território.
 *
 * Parâmetros:
 *   territory_type = fortaleza_bairro|metro_bairro|rmf_municipio_fallback|ceara_municipio
 *   cidade_key     = cidade normalizada (ex: eusebio, fortaleza)
 *   bairro_key     = bairro normalizado — opcional (ex: olho_d_agua)
 *   date_from      = YYYY-MM-DD
 *   date_to        = YYYY-MM-DD
 *
 * Retorna:
 *   months[]       = [{ competencia, revenue, invoices, customers }]
 *   totals         = { revenue, invoices, customers }
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\MapFilterSupport;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f          = MapFilterSupport::fromRequest($_GET);
    $cidadeKey  = strtolower(trim((string) ($_GET['cidade_key']  ?? '')));
    $bairroKey  = strtolower(trim((string) ($_GET['bairro_key']  ?? '')));
    $ttype      = trim((string) ($_GET['territory_type'] ?? ''));

    if ($cidadeKey === '') {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
    }

    $startComp = substr($f['date_from'], 0, 7);
    $endComp   = substr($f['date_to'],   0, 7);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json([
            'ok'     => true,
            'months' => [],
            'totals' => ['revenue' => 0, 'invoices' => 0, 'customers' => 0],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    // Decide o filtro por tipo territorial
    if ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
        // Bairro de Fortaleza — filtrar cidade=fortaleza + bairro
        $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND LOWER(TRIM(bairro_norm)) = :bairro AND competencia BETWEEN :s AND :e';
        $params = [':cidade' => 'fortaleza', ':bairro' => $bairroKey, ':s' => $startComp, ':e' => $endComp];
    } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
        // Bairro metropolitano — cidade + bairro
        $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND LOWER(TRIM(bairro_norm)) = :bairro AND competencia BETWEEN :s AND :e';
        $params = [':cidade' => $cidadeKey, ':bairro' => $bairroKey, ':s' => $startComp, ':e' => $endComp];
    } else {
        // Município (RMF fallback ou CE) — agregado por cidade
        $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND competencia BETWEEN :s AND :e';
        $params = [':cidade' => $cidadeKey, ':s' => $startComp, ':e' => $endComp];
    }
    $where = \BIomie\Support\CommercialMartScope::condition(
        $pdo,
        'mart_compradores_por_geo'
    ) . ' AND ' . $where;

    $sql = "
        SELECT
            competencia,
            COALESCE(SUM(faturamento), 0)                                             AS revenue,
            COALESCE(SUM(qtd_notas), 0)                                               AS invoices,
            COUNT(DISTINCT CONCAT(company_id, ':', COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome))) AS customers
        FROM mart_compradores_por_geo
        WHERE {$where}
        GROUP BY competencia
        ORDER BY competencia ASC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $months    = [];
    $totRevenue = 0.0;
    $totInvoices = 0;
    $totCustomers = 0;

    foreach ($rows as $row) {
        $rev = (float)($row['revenue'] ?? 0);
        $inv = (int)($row['invoices'] ?? 0);
        $cus = (int)($row['customers'] ?? 0);
        $months[] = [
            'competencia' => $row['competencia'],
            'revenue'     => $rev,
            'invoices'    => $inv,
            'customers'   => $cus,
        ];
        $totRevenue   += $rev;
        $totInvoices  += $inv;
        if ($cus > $totCustomers) $totCustomers = $cus;
    }

    Http::json([
        'ok'     => true,
        'cidade_key'  => $cidadeKey,
        'bairro_key'  => $bairroKey ?: null,
        'territory_type' => $ttype ?: null,
        'months' => $months,
        'totals' => [
            'revenue'   => $totRevenue,
            'invoices'  => $totInvoices,
            'customers' => $totCustomers,
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_territory_evolution_error', $e->getMessage());
}
