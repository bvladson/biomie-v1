<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\CanonicalRepository;
use BIomie\CommercialPlanning;
use BIomie\Support;
use BIomie\TargetsRepository;

final class CanonicalVendorPayloadBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        string $start,
        string $end,
        string $lastSync,
        float $saturdayWeight = 0.33,
        int $vendorLimit = 50
    ): array {
        $summary = $canonical->summarizeCommercial($start, $end);
        $invoiceSummary = $canonical->summarizeInvoicesCommercial($start, $end);
        $vendorRows = $canonical->aggregateByVendorCommercial($start, $end);
        $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
        $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($vendorRows, $targetsMap, $start, $end, $saturdayWeight);

        require_once __DIR__ . '/VendorIdentityResolver.php';
        require_once __DIR__ . '/VendorRealizedService.php';
        $vendorMap = $canonical->getVendorMap();
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMap);
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
        $officialRealized = $realizedService->summarizeByVendor($start, $end);
        $officialRealizedMap = [];
        foreach ($officialRealized as $or) {
            $officialRealizedMap[$or['vendor_id']] = $or;
        }

        $billedVal = (float) ($summary['billed_value'] ?? 0);
        $billedQty = (int) ($summary['billed_orders'] ?? 0);
        $authVal = (float) ($summary['authorized_value'] ?? 0);
        $authQty = (int) ($summary['authorized_orders'] ?? 0);
        $cancelVal = (float) ($summary['cancelled_value'] ?? 0);
        $cancelQty = (int) ($summary['cancelled_orders'] ?? 0);

        $summaryStatus = [
            'billed_value' => Support::money($billedVal),
            'billed_orders' => $billedQty,
            'authorized_value' => Support::money($authVal),
            'authorized_orders' => $authQty,
            'cancelled_value' => Support::money($cancelVal),
            'cancelled_orders' => $cancelQty,
            'backlog_value' => Support::money($authVal),
            'conversion_rate' => ($authQty + $billedQty) > 0 ? round(($billedQty / ($authQty + $billedQty)) * 100, 2) : 0.0,
            'cancel_rate' => ($authQty + $cancelQty) > 0 ? round(($cancelQty / ($authQty + $cancelQty)) * 100, 2) : 0.0,
        ];

        $vendorMatrix = [];
        $byVendor = [];

        foreach ($vendorRows as $row) {
            $vendorId = (string) ($row['codigo_vendedor_omie'] ?? '');
            $vendorName = (string) ($row['nome_vendedor'] ?? 'Sem vendedor');
            $status = mb_strtolower(trim((string) ($row['status_comercial'] ?? 'outro')));
            $ref = $vendorId !== '' ? $vendorId : $vendorName;
            $identity = $resolver->resolve($ref);
            $key = $identity['vendor_id'];

            if (!isset($byVendor[$key])) {
                $byVendor[$key] = [
                    'vendor_id'            => $identity['vendor_id'],
                    'vendor_name'          => $identity['vendor_name'],
                    'vendor_ref'           => $key,
                    'billed_value'         => 0.0,
                    'billed_orders'        => 0,
                    'billed_merchandise'   => 0.0,
                    'authorized_value'     => 0.0,
                    'authorized_orders'    => 0,
                    'pipeline_merchandise' => 0.0,
                    'cancelled_value'      => 0.0,
                    'cancelled_orders'     => 0,
                ];
            }

            $total = (float) ($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);
            $mercadorias = (float) ($row['mercadorias'] ?? 0);

            if ($status === 'faturado') {
                // Ignore these as they will be replaced by Official Realized
                continue;
            }

            if ($status === 'autorizado') {
                $byVendor[$key]['authorized_value'] += $total;
                $byVendor[$key]['authorized_orders'] += $orders;
                $byVendor[$key]['pipeline_merchandise'] += $mercadorias;
                continue;
            }

            if ($status === 'cancelado') {
                $byVendor[$key]['cancelled_value'] += $total;
                $byVendor[$key]['cancelled_orders'] += $orders;
            }
        }

        // Apply Official Realized
        foreach ($officialRealized as $or) {
            $key = $or['vendor_id'];
            if (!isset($byVendor[$key])) {
                $byVendor[$key] = [
                    'vendor_id'            => $or['vendor_id'],
                    'vendor_name'          => $or['vendor_name'],
                    'vendor_ref'           => $key,
                    'billed_value'         => 0.0,
                    'billed_orders'        => 0,
                    'billed_merchandise'   => 0.0,
                    'authorized_value'     => 0.0,
                    'authorized_orders'    => 0,
                    'pipeline_merchandise' => 0.0,
                    'cancelled_value'      => 0.0,
                    'cancelled_orders'     => 0,
                ];
            }
            $byVendor[$key]['billed_value'] = $or['realized'];
            $byVendor[$key]['billed_orders'] = $or['invoices_count'];
            $byVendor[$key]['billed_merchandise'] = $or['merchandise_total'];
        }

        foreach ($byVendor as $vendor) {
            $vendorBilledValue = (float) ($vendor['billed_value'] ?? 0);
            $vendorBilledOrders = (int) ($vendor['billed_orders'] ?? 0);
            $vendorBilledMerc = (float) ($vendor['billed_merchandise'] ?? 0);
            $vendorAuthorizedValue = (float) ($vendor['authorized_value'] ?? 0);
            $vendorAuthorizedOrders = (int) ($vendor['authorized_orders'] ?? 0);
            $vendorPipelineMerc = (float) ($vendor['pipeline_merchandise'] ?? 0);
            $vendorCancelledValue = (float) ($vendor['cancelled_value'] ?? 0);
            $vendorCancelledOrders = (int) ($vendor['cancelled_orders'] ?? 0);
            $vendorRef = (string) ($vendor['vendor_ref'] ?? trim((string) ($vendor['vendor_id'] ?? '')));

            $vendorMatrix[] = [
                'vendor_id'            => (string) ($vendor['vendor_id'] ?? ''),
                'vendor_name'          => (string) ($vendor['vendor_name'] ?? 'Sem vendedor'),
                'vendor_ref'           => $vendorRef,
                'realizado_vendedor'   => Support::money($vendorBilledValue),
                'mercadoria_faturada_vendedor' => Support::money($vendorBilledMerc),
                'billed_value'         => Support::money($vendorBilledValue),
                'billed_orders'        => $vendorBilledOrders,
                'billed_merchandise'   => Support::money($vendorBilledMerc),
                'authorized_value'     => Support::money($vendorAuthorizedValue),
                'authorized_orders'    => $vendorAuthorizedOrders,
                'pipeline_merchandise' => Support::money($vendorPipelineMerc),
                'cancelled_value'      => Support::money($vendorCancelledValue),
                'cancelled_orders'     => $vendorCancelledOrders,
                'backlog_value'        => Support::money($vendorAuthorizedValue),
                'conversion_rate'      => ($vendorAuthorizedOrders + $vendorBilledOrders) > 0
                    ? round(($vendorBilledOrders / ($vendorAuthorizedOrders + $vendorBilledOrders)) * 100, 2)
                    : 0.0,
                'cancel_rate'          => ($vendorAuthorizedOrders + $vendorCancelledOrders) > 0
                    ? round(($vendorCancelledOrders / ($vendorAuthorizedOrders + $vendorCancelledOrders)) * 100, 2)
                    : 0.0,
                'avg_ticket_billed'    => $vendorBilledOrders > 0 ? Support::money($vendorBilledMerc / $vendorBilledOrders) : 0.0,
                'avg_days_to_bill'     => 0,
                'share_billed'         => $billedVal > 0 ? round(($vendorBilledValue / $billedVal) * 100, 2) : 0.0,
                'target_period'        => 0.0,
                'forecast_period'      => 0.0,
                'gap_to_target'        => 0.0,
                'attainment_pct'       => 0.0,
                'risk_status'          => 'SEM_META',
                'forecasts'            => [],
            ];
        }

        usort(
            $vendorMatrix,
            static fn (array $left, array $right): int => $right['billed_value'] <=> $left['billed_value']
        );

        $vendorMatrix = array_slice($vendorMatrix, 0, $vendorLimit);

        $planningByKey = [];
        foreach ($planning['vendors'] as $vendorPlan) {
            $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
            $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
            $planningByKey[$vendorId !== '' ? $vendorId : 'name:' . VendorSupport::normalizeKey($vendorName)] = $vendorPlan;
        }

        foreach ($vendorMatrix as $index => $vendor) {
            $key = trim((string) ($vendor['vendor_id'] ?? '')) !== ''
                ? (string) $vendor['vendor_id']
                : 'name:' . VendorSupport::normalizeKey((string) ($vendor['vendor_name'] ?? 'Sem vendedor'));
            
            // Try to match the key as-is, or by resolving again (since planning might use old logic)
            $plan = $planningByKey[$key] ?? $planningByKey[$vendor['vendor_ref']] ?? null;
            if ($plan === null) {
                // Fallback: try to find by name in the plan map
                $resolvedName = 'name:' . mb_strtolower((string) $vendor['vendor_name']);
                $plan = $planningByKey[$resolvedName] ?? null;
            }
            if ($plan === null) {
                continue;
            }

            $target = Support::toFloat($plan['target_period'] ?? 0);
            $bm = Support::toFloat($vendorMatrix[$index]['billed_value'] ?? 0); // Attainment against REALIZADO (revenue fiscal), not merchandise

            $vendorMatrix[$index]['target_period'] = $target;
            $vendorMatrix[$index]['forecast_period'] = Support::toFloat($plan['forecast_period'] ?? 0);
            $vendorMatrix[$index]['gap_to_target'] = Support::money(max($target - $bm, 0));
            $vendorMatrix[$index]['attainment_pct'] = $target > 0 ? round(($bm / $target) * 100, 2) : 0.0;
            $vendorMatrix[$index]['risk_status'] = (string) ($plan['risk_status'] ?? 'SEM_META');
            $vendorMatrix[$index]['forecasts'] = $plan['forecasts'] ?? [];
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
                continue;
            }

            if ($status === 'autorizado') {
                $dailyStatus[$day]['authorized_value'] += $total;
                $dailyStatus[$day]['authorized_orders'] += $orders;
                continue;
            }

            if ($status === 'cancelado') {
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

        $revenueOrders = $billedQty;
        $grossRevenue = $billedVal;

        return [
            'ok' => true,
            'mode' => 'canonical',
            'source' => 'canonical',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => VendorSupport::formatPeriodLabel($start, $end),
            ],
            'summary' => [
                'revenue' => Support::money($grossRevenue),
                'gross_revenue' => Support::money($grossRevenue),
                'merchandise_total' => Support::money((float) ($invoiceSummary['merchandise_total'] ?? 0)),
                'discounts_total' => Support::money((float) ($invoiceSummary['discounts_total'] ?? 0)),
                'orders' => $revenueOrders,
                'pending_orders' => $authQty,
                'pending_value' => Support::money($authVal),
                'cancelled_orders' => $cancelQty,
                'cancelled_value' => Support::money($cancelVal),
                'avg_ticket' => $revenueOrders > 0 ? Support::money($grossRevenue / $revenueOrders) : 0.0,
                'avg_ticket_billed' => $billedQty > 0 ? Support::money($billedVal / $billedQty) : 0.0,
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
                'authorized' => Support::money($authVal),
                'billed' => Support::money($billedVal),
                'cancelled' => Support::money($cancelVal),
                'received' => 0.0,
            ],
            'alerts_operational' => $alertsOperational,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => 'canonical-db',
                'last_sync' => $lastSync,
                'period_basis' => 'issue_date',
                'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
            ],
        ];
    }
}
