<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\CommercialPlanning;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Shared\Support\PerfMarker;
use BIomie\Support;
use BIomie\Support\DocumentMask;
use BIomie\TargetsRepository;
use PDO;

final class CanonicalOverviewPayloadBuilder
{
    public function __construct(
        private readonly OverviewDiagnosticsBuilder $diagnostics = new OverviewDiagnosticsBuilder(),
        private readonly OverviewMonthComparisonBuilder $monthComparison = new OverviewMonthComparisonBuilder(),
        private readonly PerfMarker $perf = new PerfMarker(),
    ) {
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @return array<string,mixed>
     */
    public function build(
        PDO $pdo,
        Logger $logger,
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        ?array $snapshot,
        array $range,
        string $lastSync,
        float $saturdayWeight,
        ?string $snapshotError = null
    ): array {
        $start = $range['start'];
        $end = $range['end'];
        $this->perf->mark('canonical_core_start');
        $summaryRow = $canonical->summarizeInvoicesCommercial($start, $end);
        $invoiceVendorRows = $canonical->aggregateInvoicesByVendorCommercial($start, $end);
        $planningRows = $canonical->aggregateByVendorCommercial($start, $end);
        $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
        $pendingSummary = $canonical->summarizePendingCommercial($start, $end);
        $divergences = $canonical->openDivergences(10);
        $canonicalProducts = $canonical->topProductsCommercial($start, $end, 12);
        $canonicalClients = $canonical->topClientsCommercial($start, $end, 8);
        $this->perf->measure('overview.canonical_core', 'canonical_core_start');

        $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($planningRows, $targetsMap, $start, $end, $saturdayWeight);
        $planningMap = [];
        foreach ($planning['vendors'] as $vendorPlan) {
            $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
            $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
            $planningMap[$vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName)] = $vendorPlan;
        }

        require_once __DIR__ . '/../Vendors/VendorIdentityResolver.php';
        require_once __DIR__ . '/../Vendors/VendorRealizedService.php';
        $vendorMapCanonical = $canonical->getVendorMap();
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMapCanonical);
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
        
        $officialPayload = $realizedService->summarizeByVendor($start, $end);

        $billedValue = Support::toFloat($summaryRow['billed_value'] ?? 0);
        $ordersCount = (int) ($summaryRow['billed_orders'] ?? 0);
        $grossRevenue = Support::money($billedValue);

        $merchandiseTotal = 0.0;
        $discountsTotal = 0.0;
        $vendors = [];

        // Base everything on officialPayload
        foreach ($officialPayload as $or) {
            $key = $or['vendor_id'];
            $vendors[$key] = [
                'vendor_id' => $or['vendor_id'],
                'vendor_name' => $or['vendor_name'],
                'revenue' => $or['realized'],
                'gross_revenue' => $or['realized'],
                'merchandise_total' => $or['merchandise_total'],
                'discount_total' => 0.0, // Best effort from earlier if needed, but not critical for ranking
                'orders' => $or['invoices_count'],
            ];
            $merchandiseTotal += $or['merchandise_total'];
            // discount could be summed from invoices if we really need it, but let's keep it 0.0 or calculate it
        }

        // Just to get discount_total accurately from invoiceVendorRows
        foreach ($invoiceVendorRows as $row) {
            $discount = Support::toFloat($row['descontos'] ?? 0);
            $discountsTotal += $discount;
        }

        $vendorRanking = [];
        foreach ($vendors as $key => $row) {
            $revenue = Support::money($row['revenue']);
            $orders = (int) $row['orders'];
            
            // Try to match plan
            $plan = $planningMap[$row['vendor_id']] ?? null;
            if ($plan === null) {
                // fallback to name
                $plan = $planningMap['name:' . mb_strtolower($row['vendor_name'])] ?? null;
            }

            $row['realizado_vendedor'] = $revenue;
            $row['mercadoria_faturada_vendedor'] = Support::money($row['merchandise_total']);
            $row['revenue'] = $revenue;
            $row['gross_revenue'] = Support::money($row['gross_revenue']);
            $row['merchandise_total'] = Support::money($row['merchandise_total']);
            $row['discount_total'] = Support::money($row['discount_total']);
            $row['ticket_avg'] = $orders > 0 ? Support::money($revenue / $orders) : 0.0;
            $row['share'] = $grossRevenue > 0 ? round(($revenue / $grossRevenue) * 100, 2) : 0.0;
            $row['target_period'] = Support::toFloat($plan['target_period'] ?? 0);
            $row['forecast_period'] = Support::toFloat($plan['forecast_period'] ?? 0);
            $row['gap_to_target'] = Support::toFloat($plan['gap_to_target'] ?? 0);
            $row['attainment_pct'] = Support::toFloat($plan['attainment_pct'] ?? 0);

            $vendorRanking[] = $row;
        }
        usort($vendorRanking, static fn (array $left, array $right): int => $right['revenue'] <=> $left['revenue']);

        $dailySalesMap = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $dailySalesMap[$date] = [
                'date' => $date,
                'revenue' => 0.0,
                'gross_revenue' => 0.0,
                'orders' => 0,
            ];
        }

        foreach ($dailyRows as $row) {
            $status = (string) ($row['status_comercial'] ?? 'outro');
            if ($status !== 'faturado') {
                continue;
            }

            $date = (string) ($row['dia'] ?? '');
            if ($date === '' || !isset($dailySalesMap[$date])) {
                continue;
            }

            $total = Support::toFloat($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);

            $dailySalesMap[$date]['revenue'] += $total;
            $dailySalesMap[$date]['gross_revenue'] += $total;
            $dailySalesMap[$date]['orders'] += $orders;
        }

        $dailySales = array_values(array_map(static function (array $row): array {
            $row['revenue'] = Support::money($row['revenue']);
            $row['gross_revenue'] = Support::money($row['gross_revenue']);
            return $row;
        }, $dailySalesMap));

        $this->perf->mark('top_orders_start');
        $orders = $canonical->listTopInvoicesForDisplayCommercial($start, $end, 8);
        $this->perf->measure('overview.top_orders', 'top_orders_start');
        $topOrdersIdentityDiagnostics = $this->diagnostics->buildTopOrdersIdentityDiagnostics($orders, [
            'source_table' => 'biomie_notas_fiscais_canonicas',
            'last_materialized_at' => $lastSync,
            'total_notes_analyzed' => $ordersCount,
        ]);

        $topProducts = $canonicalProducts !== []
            ? array_map(static fn (array $row): array => [
                'product_code' => (string) ($row['product_code'] ?? ''),
                'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
                'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
                'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
                'orders' => (int) ($row['orders'] ?? 0),
            ], $canonicalProducts)
            : (is_array($snapshot['top_products'] ?? null) ? $snapshot['top_products'] : []);
        $topClients = array_map(static fn (array $row): array => [
            'client_id' => (string) ($row['codigo_cliente_omie'] ?? ''),
            'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
            'client_document' => (string) (DocumentMask::maskDocument((string) ($row['client_document'] ?? '')) ?? ''),
            'notes_count' => (int) ($row['notes_count'] ?? 0),
            'invoice_total' => Support::money((float) ($row['invoice_total'] ?? 0)),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
            'share_pct' => $grossRevenue > 0 ? round(((float) ($row['invoice_total'] ?? 0) / $grossRevenue) * 100, 2) : 0.0,
        ], $canonicalClients);
        $top5ClientConcentration = $grossRevenue > 0
            ? round(array_sum(array_map(static fn (array $row): float => (float) ($row['invoice_total'] ?? 0), array_slice($canonicalClients, 0, 5))) / $grossRevenue * 100, 2)
            : 0.0;
        $alerts = is_array($snapshot['alerts'] ?? null) ? $snapshot['alerts'] : [];
        if ($topClients !== []) {
            $firstClient = $topClients[0] ?? null;
            $topShare = (float) ($firstClient['share_pct'] ?? 0);
            if ($topShare >= 35) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Concentracao elevada em cliente',
                    'message' => sprintf('O principal cliente concentrou %.1f%% da receita fiscal do periodo.', $topShare),
                    'metric' => 'Top cliente',
                    'value' => $topShare,
                ];
            }
        }
        if ($alerts === []) {
            foreach ($divergences as $divergence) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => (string) ($divergence['tipo'] ?? 'Divergencia'),
                    'message' => (string) ($divergence['descricao'] ?? 'Inconsistencia detectada na camada canonica.'),
                    'metric' => 'Divergencia',
                    'value' => 1,
                ];
            }
        }

        $mode = $snapshot !== null ? 'hybrid' : 'canonical';
        $source = $snapshot !== null ? 'canonical+legacy' : 'canonical';
        $this->perf->mark('month_comparison_start');
        $monthComparison = $this->monthComparison->build($pdo, $logger, $canonical, $targetsRepository, $start, $saturdayWeight);
        $this->perf->measure('overview.month_comparison', 'month_comparison_start');

        return [
            'ok' => true,
            'mode' => $mode,
            'source' => $source,
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => $range['label'],
            ],
            'summary' => [
                'revenue' => $grossRevenue,
                'gross_revenue' => $grossRevenue,
                'merchandise_total' => Support::money((float) ($summaryRow['merchandise_total'] ?? $merchandiseTotal)),
                'discounts_total' => Support::money((float) ($summaryRow['discounts_total'] ?? $discountsTotal)),
                'orders' => $ordersCount,
                'avg_ticket' => $ordersCount > 0 ? Support::money($grossRevenue / $ordersCount) : 0.0,
                'active_vendors' => count(array_filter(
                    $vendorRanking,
                    static fn (array $row): bool => trim((string) ($row['vendor_name'] ?? '')) !== ''
                        && strcasecmp((string) ($row['vendor_name'] ?? ''), 'Sem vendedor') !== 0
                )),
                'active_clients' => (int) ($summaryRow['active_clients'] ?? count($topClients)),
                'top5_client_concentration_pct' => $top5ClientConcentration,
                'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
                'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
                'pending_orders' => (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0),
                'pending_value' => Support::money((float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0)),
                'cancelled_orders' => (int) ($pendingSummary['cancelled_orders'] ?? 0),
                'cancelled_value' => Support::money((float) ($pendingSummary['cancelled_value'] ?? 0)),
            ],
            'daily_sales' => $dailySales,
            'vendor_ranking' => $vendorRanking,
            'top_orders' => $orders,
            'top_products' => $topProducts,
            'top_clients' => $topClients,
            'month_comparison' => $monthComparison,
            'alerts' => $alerts,
            'diagnostics' => [
                'top_orders_identity' => $topOrdersIdentityDiagnostics,
            ],
            'meta' => [
                'generated_at' => $lastSync,
                'served_from' => $source === 'canonical' ? 'canonical-db' : 'canonical-db+legacy-snapshot',
                'last_sync' => $lastSync,
                'period_basis' => 'issue_date',
                'request_id' => RequestContext::id(),
                'snapshot_fallback_error' => $snapshotError,
                'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
            ],
        ];
    }
}
