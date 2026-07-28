<?php

declare(strict_types=1);

namespace BIomie\Competencia;

use BIomie\CanonicalRepository;
use BIomie\CommercialGoldReconciler;
use BIomie\CommercialPlanning;
use BIomie\FlightPlanV2Service;
use BIomie\GoldMartRepository;
use BIomie\Support;
use BIomie\TargetsRepository;

final class CompetenciaPayloadBuilder
{
    private ?CommercialGoldReconciler $goldReconciler = null;

    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly ?TargetsRepository $targetsRepository,
        private readonly float $saturdayWeight,
        private readonly CompetenciaSupport $support,
        private readonly ?GoldMartRepository $goldMart = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildFlightPlanPayload(string $start, string $end): array
    {
        $service = new FlightPlanV2Service($this->canonical, $this->targetsRepository, $this->saturdayWeight);

        return $service->build($start, $end, null);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildTargetsPayload(string $competencia): array
    {
        $service = new FlightPlanV2Service($this->canonical, $this->targetsRepository, $this->saturdayWeight);

        return $service->buildTargetsEditor($competencia, 1, PHP_INT_MAX);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildVendorsPayload(string $start, string $end): array
    {
        $reconciliation = $this->goldReconciler()->reconcile($start, $end);
        $useGold = (bool) ($reconciliation['reconciled'] ?? false);
        $core = $this->selectedCore($reconciliation);
        $summary = is_array($core['commercial_summary'] ?? null) ? $core['commercial_summary'] : [];
        $invoiceSummary = is_array($core['invoice_summary'] ?? null) ? $core['invoice_summary'] : [];
        $vendorRows = is_array($core['vendor_rows'] ?? null) ? $core['vendor_rows'] : [];
        $dailyRows = is_array($core['daily_rows'] ?? null) ? $core['daily_rows'] : [];
        $targetsMap = ($this->targetsRepository !== null && $this->targetsRepository->isAvailable())
            ? $this->targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($vendorRows, $targetsMap, $start, $end, $this->saturdayWeight);

        $billedValue = (float) ($summary['billed_value'] ?? 0);
        $billedOrders = (int) ($summary['billed_orders'] ?? 0);
        $authorizedValue = (float) ($summary['authorized_value'] ?? 0);
        $authorizedOrders = (int) ($summary['authorized_orders'] ?? 0);
        $cancelledValue = (float) ($summary['cancelled_value'] ?? 0);
        $cancelledOrders = (int) ($summary['cancelled_orders'] ?? 0);

        $summaryStatus = [
            'billed_value' => Support::money($billedValue),
            'billed_orders' => $billedOrders,
            'authorized_value' => Support::money($authorizedValue),
            'authorized_orders' => $authorizedOrders,
            'cancelled_value' => Support::money($cancelledValue),
            'cancelled_orders' => $cancelledOrders,
            'backlog_value' => Support::money($authorizedValue),
            'conversion_rate' => ($authorizedOrders + $billedOrders) > 0
                ? round(($billedOrders / ($authorizedOrders + $billedOrders)) * 100, 2)
                : 0.0,
            'cancel_rate' => ($authorizedOrders + $cancelledOrders) > 0
                ? round(($cancelledOrders / ($authorizedOrders + $cancelledOrders)) * 100, 2)
                : 0.0,
        ];

        $vendorsByKey = [];
        foreach ($vendorRows as $row) {
            $vendorId = (string) ($row['codigo_vendedor_omie'] ?? '');
            $vendorName = (string) ($row['nome_vendedor'] ?? 'Sem vendedor');
            $status = (string) ($row['status_comercial'] ?? 'outro');
            $key = $vendorId !== '' ? $vendorId : 'name:' . $this->support->normalizeVendorKey($vendorName);

            if (!isset($vendorsByKey[$key])) {
                $vendorsByKey[$key] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'billed_value' => 0.0,
                    'billed_orders' => 0,
                    'authorized_value' => 0.0,
                    'authorized_orders' => 0,
                    'cancelled_value' => 0.0,
                    'cancelled_orders' => 0,
                ];
            }

            $total = (float) ($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);

            if ($status === 'faturado') {
                $vendorsByKey[$key]['billed_value'] += $total;
                $vendorsByKey[$key]['billed_orders'] += $orders;
            } elseif ($status === 'autorizado') {
                $vendorsByKey[$key]['authorized_value'] += $total;
                $vendorsByKey[$key]['authorized_orders'] += $orders;
            } elseif ($status === 'cancelado') {
                $vendorsByKey[$key]['cancelled_value'] += $total;
                $vendorsByKey[$key]['cancelled_orders'] += $orders;
            }
        }

        $vendorMatrix = [];
        foreach ($vendorsByKey as $vendor) {
            $vendorBilledValue = (float) ($vendor['billed_value'] ?? 0);
            $vendorBilledOrders = (int) ($vendor['billed_orders'] ?? 0);
            $vendorAuthorizedValue = (float) ($vendor['authorized_value'] ?? 0);
            $vendorAuthorizedOrders = (int) ($vendor['authorized_orders'] ?? 0);
            $vendorCancelledValue = (float) ($vendor['cancelled_value'] ?? 0);
            $vendorCancelledOrders = (int) ($vendor['cancelled_orders'] ?? 0);

            $vendorMatrix[] = [
                'vendor_id' => (string) ($vendor['vendor_id'] ?? ''),
                'vendor_name' => (string) ($vendor['vendor_name'] ?? 'Sem vendedor'),
                'billed_value' => Support::money($vendorBilledValue),
                'billed_orders' => $vendorBilledOrders,
                'authorized_value' => Support::money($vendorAuthorizedValue),
                'authorized_orders' => $vendorAuthorizedOrders,
                'cancelled_value' => Support::money($vendorCancelledValue),
                'cancelled_orders' => $vendorCancelledOrders,
                'backlog_value' => Support::money($vendorAuthorizedValue),
                'conversion_rate' => ($vendorAuthorizedOrders + $vendorBilledOrders) > 0
                    ? round(($vendorBilledOrders / ($vendorAuthorizedOrders + $vendorBilledOrders)) * 100, 2)
                    : 0.0,
                'cancel_rate' => ($vendorAuthorizedOrders + $vendorCancelledOrders) > 0
                    ? round(($vendorCancelledOrders / ($vendorAuthorizedOrders + $vendorCancelledOrders)) * 100, 2)
                    : 0.0,
                'avg_ticket_billed' => $vendorBilledOrders > 0 ? Support::money($vendorBilledValue / $vendorBilledOrders) : 0.0,
                'avg_days_to_bill' => 0,
                'share_billed' => $billedValue > 0 ? round(($vendorBilledValue / $billedValue) * 100, 2) : 0.0,
                'target_period' => 0.0,
                'forecast_period' => 0.0,
                'gap_to_target' => 0.0,
            ];
        }

        usort($vendorMatrix, static fn (array $left, array $right): int => $right['billed_value'] <=> $left['billed_value']);

        $planningByKey = [];
        foreach ($planning['vendors'] as $vendorPlan) {
            $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
            $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
            $planningByKey[$vendorId !== '' ? $vendorId : 'name:' . $this->support->normalizeVendorKey($vendorName)] = $vendorPlan;
        }

        foreach ($vendorMatrix as $index => $vendor) {
            $key = trim((string) ($vendor['vendor_id'] ?? '')) !== ''
                ? (string) $vendor['vendor_id']
                : 'name:' . $this->support->normalizeVendorKey((string) ($vendor['vendor_name'] ?? 'Sem vendedor'));
            $plan = $planningByKey[$key] ?? null;
            if ($plan === null) {
                continue;
            }

            $vendorMatrix[$index]['target_period'] = Support::toFloat($plan['target_period'] ?? 0);
            $vendorMatrix[$index]['forecast_period'] = Support::toFloat($plan['forecast_period'] ?? 0);
            $vendorMatrix[$index]['gap_to_target'] = Support::toFloat($plan['gap_to_target'] ?? 0);
        }

        $dailyStatus = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $dailyStatus[$date] = [
                'date' => $date,
                'billed_value' => 0.0,
                'billed_orders' => 0,
                'authorized_value' => 0.0,
                'authorized_orders' => 0,
                'cancelled_value' => 0.0,
                'cancelled_orders' => 0,
            ];
        }

        foreach ($dailyRows as $row) {
            $day = (string) ($row['dia'] ?? '');
            $status = (string) ($row['status_comercial'] ?? '');
            if (!isset($dailyStatus[$day])) {
                continue;
            }

            $total = (float) ($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);

            if ($status === 'faturado') {
                $dailyStatus[$day]['billed_value'] += $total;
                $dailyStatus[$day]['billed_orders'] += $orders;
            } elseif ($status === 'autorizado') {
                $dailyStatus[$day]['authorized_value'] += $total;
                $dailyStatus[$day]['authorized_orders'] += $orders;
            } elseif ($status === 'cancelado') {
                $dailyStatus[$day]['cancelled_value'] += $total;
                $dailyStatus[$day]['cancelled_orders'] += $orders;
            }
        }

        $alertsOperational = [];
        foreach ($vendorMatrix as $vendor) {
            $baseOrders = (int) ($vendor['cancelled_orders'] ?? 0) + (int) ($vendor['authorized_orders'] ?? 0);
            if ((float) ($vendor['cancel_rate'] ?? 0) > 15 && $baseOrders >= 3) {
                $alertsOperational[] = [
                    'level' => 'danger',
                    'title' => (string) ($vendor['vendor_name'] ?? 'Sem vendedor') . ': cancelamento de ' . $vendor['cancel_rate'] . '%',
                    'message' => (int) ($vendor['cancelled_orders'] ?? 0) . ' cancelado(s) de ' . $baseOrders . ' pedido(s).',
                ];
            }
        }

        return [
            'ok' => true,
            'mode' => 'historical_snapshot',
            'source' => 'historical_snapshot',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => $this->support->periodLabel($start, $end),
            ],
            'summary' => [
                'revenue' => Support::money($billedValue),
                'gross_revenue' => Support::money($billedValue),
                'merchandise_total' => Support::money((float) ($invoiceSummary['merchandise_total'] ?? 0)),
                'discounts_total' => Support::money((float) ($invoiceSummary['discounts_total'] ?? 0)),
                'orders' => $billedOrders,
                'pending_orders' => $authorizedOrders,
                'pending_value' => Support::money($authorizedValue),
                'cancelled_orders' => $cancelledOrders,
                'cancelled_value' => Support::money($cancelledValue),
                'avg_ticket' => $billedOrders > 0 ? Support::money($billedValue / $billedOrders) : 0.0,
                'avg_ticket_billed' => $billedOrders > 0 ? Support::money($billedValue / $billedOrders) : 0.0,
                'active_vendors' => count(array_filter(
                    $vendorMatrix,
                    static fn (array $vendor): bool => trim((string) ($vendor['vendor_name'] ?? '')) !== ''
                        && strcasecmp((string) ($vendor['vendor_name'] ?? ''), 'Sem vendedor') !== 0
                )),
                'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
                'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
            ],
            'summary_status' => $summaryStatus,
            'vendors' => $vendorMatrix,
            'vendor_status_matrix' => $vendorMatrix,
            'daily_status' => array_values(array_map(static fn (array $day): array => [
                'date' => (string) ($day['date'] ?? ''),
                'billed_value' => Support::money((float) ($day['billed_value'] ?? 0)),
                'billed_orders' => (int) ($day['billed_orders'] ?? 0),
                'authorized_value' => Support::money((float) ($day['authorized_value'] ?? 0)),
                'authorized_orders' => (int) ($day['authorized_orders'] ?? 0),
                'cancelled_value' => Support::money((float) ($day['cancelled_value'] ?? 0)),
                'cancelled_orders' => (int) ($day['cancelled_orders'] ?? 0),
            ], $dailyStatus)),
            'funnel' => [
                'authorized' => Support::money($authorizedValue),
                'billed' => Support::money($billedValue),
                'cancelled' => Support::money($cancelledValue),
                'received' => 0.0,
            ],
            'alerts_operational' => $alertsOperational,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => $useGold ? 'gold-mart' : 'historical-read-model',
                'commercial_core_source' => $useGold ? 'gold_live_reconciled' : 'canonical_live',
                'commercial_core_reconciliation' => CommercialGoldReconciler::diagnostics($reconciliation),
                'last_sync' => $this->canonical->lastSyncAt($start, $end) ?: null,
                'period_basis' => 'issue_date',
                'targets_source' => ($this->targetsRepository !== null && $this->targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildOverviewPayload(string $start, string $end): array
    {
        $reconciliation = $this->goldReconciler()->reconcile($start, $end);
        $useGold = (bool) ($reconciliation['reconciled'] ?? false);
        $core = $this->selectedCore($reconciliation);
        $summaryRow = is_array($core['invoice_summary'] ?? null) ? $core['invoice_summary'] : [];
        $planningRows = is_array($core['vendor_rows'] ?? null) ? $core['vendor_rows'] : [];
        $invoiceVendorRows = array_values(array_filter(
            $planningRows,
            static fn (array $row): bool => ($row['status_comercial'] ?? '') === 'faturado',
        ));
        $dailyRows = is_array($core['daily_rows'] ?? null) ? $core['daily_rows'] : [];
        // Produto e cliente sao marts mensais. Para um intervalo parcial, a
        // competencia inteira conteria dias fora do filtro solicitado; use a
        // leitura canonica exata mesmo quando os demais graos Gold reconciliam.
        $canonicalProducts = $useGold && $this->isWholeMonthRange($start, $end)
            ? $this->goldMart->topProductsCommercial($start, $end, 12)
            : $this->canonical->topProductsCommercial($start, $end, 12);
        // mart_cliente_periodo still contains a few order-derived auxiliary
        // columns. Keep customer ranking on the canonical fiscal grain until
        // every client metric is materialized and reconciled from NF-e.
        $canonicalClients  = $this->canonical->topClientsCommercial($start, $end, 8);
        $pendingSummary = $this->canonical->summarizePendingCommercial($start, $end);
        $divergences    = $this->canonical->openDivergences(10);
        $targetsMap = ($this->targetsRepository !== null && $this->targetsRepository->isAvailable())
            ? $this->targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($planningRows, $targetsMap, $start, $end, $this->saturdayWeight);

        $planningByKey = [];
        foreach ($planning['vendors'] as $vendorPlan) {
            $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
            $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
            $planningByKey[$vendorId !== '' ? $vendorId : 'name:' . $this->support->normalizeVendorKey($vendorName)] = $vendorPlan;
        }

        $billedValue = Support::toFloat($summaryRow['billed_value'] ?? 0);
        $ordersCount = (int) ($summaryRow['billed_orders'] ?? 0);
        $grossRevenue = Support::money($billedValue);
        $merchandiseTotal = 0.0;
        $discountsTotal = 0.0;
        $vendors = [];

        foreach ($invoiceVendorRows as $row) {
            $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
            $vendorName = trim((string) ($row['nome_vendedor'] ?? 'Sem vendedor'));
            $key = $vendorId !== '' ? $vendorId : 'name:' . $this->support->normalizeVendorKey($vendorName);

            if (!isset($vendors[$key])) {
                $vendors[$key] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                    'revenue' => 0.0,
                    'gross_revenue' => 0.0,
                    'merchandise_total' => 0.0,
                    'discount_total' => 0.0,
                    'orders' => 0,
                ];
            }

            $total = Support::toFloat($row['total'] ?? 0);
            $merchandise = Support::toFloat($row['mercadorias'] ?? 0);
            $discount = Support::toFloat($row['descontos'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);

            $vendors[$key]['revenue'] += $total;
            $vendors[$key]['gross_revenue'] += $total;
            $vendors[$key]['merchandise_total'] += $merchandise;
            $vendors[$key]['discount_total'] += $discount;
            $vendors[$key]['orders'] += $orders;

            $merchandiseTotal += $merchandise;
            $discountsTotal += $discount;
        }

        $vendorRanking = [];
        foreach ($vendors as $key => $row) {
            $revenue = Support::money($row['revenue']);
            $orders = (int) $row['orders'];
            $plan = $planningByKey[$key] ?? null;

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
            $dailySalesMap[$date] = ['date' => $date, 'revenue' => 0.0, 'gross_revenue' => 0.0, 'orders' => 0];
        }

        foreach ($dailyRows as $row) {
            if ((string) ($row['status_comercial'] ?? 'outro') !== 'faturado') {
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

        $orders = $this->canonical->listInvoicesForDisplayCommercial($start, $end);
        usort(
            $orders,
            static fn (array $left, array $right): int =>
                Support::toFloat($right['gross_total'] ?? $right['total'] ?? 0) <=> Support::toFloat($left['gross_total'] ?? $left['total'] ?? 0)
        );

        $topProducts = array_map(static fn (array $row): array => [
            'product_code' => (string) ($row['product_code'] ?? ''),
            'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
            'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
            'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
            'orders' => (int) ($row['orders'] ?? 0),
        ], $canonicalProducts);

        $topClients = array_map(static fn (array $row): array => [
            'client_id' => (string) ($row['codigo_cliente_omie'] ?? ''),
            'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
            'client_document' => (string) ($row['client_document'] ?? ''),
            'notes_count' => (int) ($row['notes_count'] ?? 0),
            'invoice_total' => Support::money((float) ($row['invoice_total'] ?? 0)),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
            'last_purchase' => (string) ($row['last_purchase'] ?? ''),
            'share_pct' => $grossRevenue > 0 ? round(((float) ($row['invoice_total'] ?? 0) / $grossRevenue) * 100, 2) : 0.0,
        ], $canonicalClients);

        $top5ClientConcentration = $grossRevenue > 0
            ? round(
                array_sum(array_map(static fn (array $row): float => (float) ($row['invoice_total'] ?? 0), array_slice($canonicalClients, 0, 5)))
                / $grossRevenue * 100,
                2
            )
            : 0.0;

        $alerts = [];
        foreach ($divergences as $divergence) {
            $alerts[] = [
                'level' => 'warning',
                'title' => (string) ($divergence['tipo'] ?? 'Divergencia'),
                'message' => (string) ($divergence['descricao'] ?? 'Verificar divergencia fiscal/comercial.'),
            ];
        }

        if ((float) ($planning['team']['goal_progress'] ?? 0) < 85 && (float) ($planning['team']['target_period'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Abaixo do ritmo',
                'message' => 'O faturamento fiscal do periodo segue abaixo da linha de 85% da meta.',
            ];
        }

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
            $alerts[] = [
                'level' => 'success',
                'title' => 'Snapshot historico pronto',
                'message' => 'Periodo fechado servido pela materializacao historica da base canonical.',
            ];
        }

        return [
            'ok' => true,
            'mode' => 'historical_snapshot',
            'source' => 'historical_snapshot',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => $this->support->periodLabel($start, $end),
            ],
            'summary' => [
                'revenue' => $grossRevenue,
                'gross_revenue' => $grossRevenue,
                'merchandise_total' => Support::money($merchandiseTotal),
                'discounts_total' => Support::money($discountsTotal),
                'orders' => $ordersCount,
                'pending_orders' => (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0),
                'pending_value' => Support::money((float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0)),
                'cancelled_orders' => (int) ($planning['team']['cancelled_notes_count'] ?? 0),
                'cancelled_value' => Support::money((float) ($planning['team']['cancelled_value'] ?? 0)),
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
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? ($planning['team']['attainment_pct'] ?? 0)),
            ],
            'daily_sales' => $dailySales,
            'vendor_ranking' => $vendorRanking,
            'top_orders' => array_slice($orders, 0, 12),
            'top_products' => $topProducts,
            'top_clients' => $topClients,
            'alerts' => array_slice($alerts, 0, 8),
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => $useGold ? 'gold-mart' : 'historical-read-model',
                'commercial_core_source' => $useGold ? 'gold_live_reconciled' : 'canonical_live',
                'commercial_core_reconciliation' => CommercialGoldReconciler::diagnostics($reconciliation),
                'last_sync' => $this->canonical->lastSyncAt($start, $end) ?: null,
                'period_basis' => 'issue_date',
                'targets_source' => ($this->targetsRepository !== null && $this->targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
            ],
        ];
    }

    private function goldReconciler(): CommercialGoldReconciler
    {
        return $this->goldReconciler ??= new CommercialGoldReconciler(
            $this->canonical,
            $this->goldMart,
        );
    }

    /**
     * @param array<string,mixed> $reconciliation
     * @return array<string,mixed>
     */
    private function selectedCore(array $reconciliation): array
    {
        $key = (bool) ($reconciliation['reconciled'] ?? false) ? 'gold' : 'canonical';
        $core = $reconciliation[$key] ?? null;

        return is_array($core) ? $core : [];
    }

    private function isWholeMonthRange(string $start, string $end): bool
    {
        $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$startDate instanceof \DateTimeImmutable || !$endDate instanceof \DateTimeImmutable) {
            return false;
        }

        return $startDate->format('d') === '01'
            && $endDate->format('Y-m-d') === $endDate->format('Y-m-t');
    }

}
