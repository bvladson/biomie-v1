<?php

/**
 * api/vendor_detail.php — Vendor 360: resumo completo de vendedor por período.
 *
 * Retorna:
 *   - Dados do plano de voo (meta, realizado, gap, forecast, risco)
 *   - Resumo: orders_count, invoices_count, customers_count, products_count,
 *             gross_total, discount_total, cancelled_total, open_total, ticket_avg
 *   - Charts: daily_revenue, status_breakdown, top_customers, top_products
 *   - Listas: orders, invoices, customers, products
 *   - Meta: source_of_truth, freshness, last_success_at
 *
 * Parâmetros:
 *   vendor_id  — ID/nome do vendedor (obrigatório)
 *   start      — Data início (YYYY-MM-DD)
 *   end        — Data fim   (YYYY-MM-DD)
 *
 * Permissão: sales.view
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    $vendorRef = rawurldecode(trim((string) Http::query('vendor_id')));
    if ($vendorRef === '') {
        Http::json(['ok' => false, 'message' => 'vendor_id é obrigatório.', 'request_id' => RequestContext::id()], 400);
    }

    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end   = $range['end'];

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.', 'request_id' => RequestContext::id()], 503);
    }

    $pdo  = Database::connect($config, $conn);
    $repo = new CanonicalRepository($pdo, $logger);

    if (!$repo->isAvailable()) {
        Http::json(['ok' => false, 'message' => 'Camada canonical indisponível.', 'request_id' => RequestContext::id()], 503);
    }

    // ── Resolve vendor identity ───────────────────────────────────────────────
    $vendorMap  = $repo->getVendorMap();
    $resolvedId = $vendorRef;
    $resolvedName = '';
    foreach ($vendorMap as $id => $v) {
        $name = trim((string) ($v['nome'] ?? ''));
        if ((string) $id === $vendorRef
            || vendorDetailNormalizeV2($name) === vendorDetailNormalizeV2($vendorRef)) {
            $resolvedId   = (string) $id;
            $resolvedName = $name;
            break;
        }
    }

    // ── Orders: invoiced + pipeline + cancelled ───────────────────────────────
    $allInvoices  = $repo->listInvoicedOrdersForDisplayCommercial($start, $end, $resolvedId);
    $allPipeline  = $repo->listPipelineOrdersForDisplayCommercial($start, $end, $resolvedId);
    $allCancelled = vendor360FilterByVendor(
        $repo->listCancelledInvoicesForDisplayCommercial($start, $end),
        $resolvedId, $resolvedName
    );

    // ── Summary counts ────────────────────────────────────────────────────────
    $grossTotal     = 0.0;
    $discountTotal  = 0.0;
    $openTotal      = 0.0;
    $cancelledTotal = 0.0;
    $customerSet    = [];
    $productSet     = [];

    foreach ($allInvoices as $row) {
        $grossTotal    += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
        $discountTotal += Support::toFloat($row['discount_total'] ?? 0);
        $cid = (string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
        if ($cid !== '') { $customerSet[$cid] = true; }
    }
    foreach ($allPipeline as $row) {
        $openTotal += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
        $cid = (string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
        if ($cid !== '') { $customerSet[$cid] = true; }
    }
    foreach ($allCancelled as $row) {
        $cancelledTotal += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
    }

    $invoicesCount = count($allInvoices);
    $ticketAvg     = $invoicesCount > 0 ? Support::money($grossTotal / $invoicesCount) : 0.0;

    // ── Top customers ─────────────────────────────────────────────────────────
    $topCustomers = vendor360TopCustomers($allInvoices, 8);

    // ── Top products ──────────────────────────────────────────────────────────
    $topProducts = vendor360TopProducts($repo, $start, $end, $resolvedId, 8);
    foreach ($topProducts as $p) {
        $pid = (string) ($p['codigo_produto'] ?? $p['product_code'] ?? $p['id'] ?? '');
        if ($pid !== '') { $productSet[$pid] = true; }
    }

    // ── Daily revenue ─────────────────────────────────────────────────────────
    $dailyRevenue = vendor360BuildDailySales($start, $end, $allInvoices);

    // ── Status breakdown ─────────────────────────────────────────────────────
    $statusBreakdown = [
        ['label' => 'Faturado',   'value' => Support::money($grossTotal),    'key' => 'invoiced'],
        ['label' => 'Carteira',   'value' => Support::money($openTotal),     'key' => 'pipeline'],
        ['label' => 'Cancelado',  'value' => Support::money($cancelledTotal),'key' => 'cancelled'],
    ];

    // ── Meta / freshness ─────────────────────────────────────────────────────
    $lastSync = $repo->lastSyncAt($start, $end) ?: null;

    Http::json([
        'ok'     => true,
        'vendor' => [
            'id'   => $resolvedId,
            'name' => $resolvedName ?: $vendorRef,
            'code' => $resolvedId,
            'risk' => null,
            'goal' => 0,
            'realized' => Support::money($grossTotal),
            'gap'      => 0,
            'attainment' => 0,
        ],
        'period'  => ['start' => $start, 'end' => $end],
        'summary' => [
            'orders_count'    => count($allPipeline),
            'invoices_count'  => $invoicesCount,
            'customers_count' => count($customerSet),
            'products_count'  => count($productSet),
            'gross_total'     => Support::money($grossTotal),
            'discount_total'  => Support::money($discountTotal),
            'cancelled_total' => Support::money($cancelledTotal),
            'open_total'      => Support::money($openTotal),
            'ticket_avg'      => $ticketAvg,
        ],
        'charts' => [
            'daily_revenue'    => $dailyRevenue,
            'status_breakdown' => $statusBreakdown,
            'top_customers'    => $topCustomers,
            'top_products'     => $topProducts,
        ],
        'orders'    => array_slice($allPipeline, 0, 30),
        'invoices'  => array_slice($allInvoices, 0, 30),
        'customers' => $topCustomers,
        'products'  => $topProducts,
        'finance'   => [],
        'meta' => [
            'source_of_truth'  => 'canonical',
            'freshness'        => 'canonical',
            'last_success_at'  => $lastSync,
            'last_reconcile_at'=> $lastSync,
            'request_id'       => RequestContext::id(),
        ],
    ]);
} catch (InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage(), 'request_id' => RequestContext::id()], 400);
} catch (Throwable $e) {
    $logger->error('Falha no vendor_detail.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Não foi possível carregar os dados do vendedor.', 'request_id' => RequestContext::id()], 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function vendorDetailNormalizeV2(string $v): string
{
    return preg_replace('/\s+/', ' ', strtolower(trim(rawurldecode($v)))) ?? strtolower(trim($v));
}

/** @param list<array<string,mixed>> $rows */
function vendor360FilterByVendor(array $rows, string $vendorId, string $vendorName): array
{
    if ($vendorId === '' && $vendorName === '') {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $row) use ($vendorId, $vendorName): bool {
        $rid = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
        if ($vendorId !== '' && $rid !== '' && $rid === $vendorId) {
            return true;
        }
        $rname = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''));
        return $vendorName !== '' && vendorDetailNormalizeV2($rname) === vendorDetailNormalizeV2($vendorName);
    }));
}

/** @param list<array<string,mixed>> $invoices */
function vendor360TopCustomers(array $invoices, int $limit): array
{
    $map = [];
    foreach ($invoices as $row) {
        $key   = (string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
        $label = (string) ($row['cliente'] ?? $row['customer_name'] ?? $key);
        if ($key === '') { continue; }
        $map[$key]['label'] = $label;
        $map[$key]['value'] = (float) ($map[$key]['value'] ?? 0)
            + Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
        $map[$key]['orders'] = ((int) ($map[$key]['orders'] ?? 0)) + 1;
    }
    uasort($map, static fn ($a, $b) => $b['value'] <=> $a['value']);
    $result = [];
    foreach (array_slice($map, 0, $limit, true) as $id => $data) {
        $result[] = [
            'id'     => $id,
            'label'  => $data['label'],
            'value'  => Support::money($data['value']),
            'orders' => $data['orders'],
        ];
    }
    return $result;
}

function vendor360TopProducts(CanonicalRepository $repo, string $start, string $end, string $vendorId, int $limit): array
{
    try {
        $rows = $repo->topProductsCommercial($start, $end, $limit * 3);
        if ($vendorId !== '') {
            $rows = array_values(array_filter($rows, static fn ($r) => trim((string) ($r['vendor_id'] ?? $r['codigo_vendedor_omie'] ?? '')) === $vendorId));
        }
        return array_slice(array_values($rows), 0, $limit);
    } catch (Throwable) {
        return [];
    }
}

/** @param list<array<string,mixed>> $invoices */
function vendor360BuildDailySales(string $start, string $end, array $invoices): array
{
    $daily = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $daily[$date] = ['date' => $date, 'gross_revenue' => 0.0, 'orders' => 0];
    }
    foreach ($invoices as $row) {
        $date = (string) ($row['issue_date'] ?? $row['status_date'] ?? '');
        if ($date === '' || !isset($daily[$date])) { continue; }
        $daily[$date]['gross_revenue'] += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
        $daily[$date]['orders']++;
    }
    foreach ($daily as &$d) {
        $d['gross_revenue'] = Support::money((float) $d['gross_revenue']);
    }
    return array_values($daily);
}
