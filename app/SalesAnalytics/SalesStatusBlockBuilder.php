<?php

declare(strict_types=1);

namespace BIomie\SalesAnalytics;

use BIomie\Support;
use DateTimeImmutable;

final class SalesStatusBlockBuilder
{
    public function __construct(
        private readonly SalesSupport $support,
    ) {
    }

    /**
     * Gera blocos de status: summary_status, vendor_status_matrix, daily_status, funnel, alerts_operational.
     *
     * @param list<array<string,mixed>> $orders
     * @param list<array<string,mixed>> $vendorRanking
     * @return array<string,mixed>
     */
    public function buildStatusBlocks(array $orders, array $vendorRanking, string $start, string $end): array
    {
        $today = new DateTimeImmutable('today');
        unset($today, $vendorRanking);

        $byStatus = ['faturado' => [], 'autorizado' => [], 'cancelado' => [], 'outro' => []];
        foreach ($orders as $order) {
            $group = $order['status_group'] ?? $this->support->classifyStatus((string) ($order['stage'] ?? ''));
            $byStatus[$group][] = $order;
        }

        $billedVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $byStatus['faturado']));
        $authVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $byStatus['autorizado']));
        $cancelVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $byStatus['cancelado']));
        $billedQty = count($byStatus['faturado']);
        $authQty = count($byStatus['autorizado']);
        $cancelQty = count($byStatus['cancelado']);

        $backlogVal = $authVal;
        $convRate = ($authQty + $billedQty) > 0 ? round(($billedQty / ($authQty + $billedQty)) * 100, 2) : 0;
        $cancelRate = ($authQty + $cancelQty) > 0 ? round(($cancelQty / ($authQty + $cancelQty)) * 100, 2) : 0;

        $summaryStatus = [
            'billed_value' => Support::money($billedVal),
            'billed_orders' => $billedQty,
            'authorized_value' => Support::money($authVal),
            'authorized_orders' => $authQty,
            'cancelled_value' => Support::money($cancelVal),
            'cancelled_orders' => $cancelQty,
            'backlog_value' => Support::money($backlogVal),
            'conversion_rate' => $convRate,
            'cancel_rate' => $cancelRate,
        ];

        $vendorMatrix = [];
        $ordersByVendor = [];
        foreach ($orders as $order) {
            $vName = trim((string) ($order['vendor_name'] ?? 'Sem vendedor'));
            $ordersByVendor[$vName][] = $order;
        }

        foreach ($ordersByVendor as $vName => $vOrders) {
            $vBilled = array_filter($vOrders, fn ($o) => ($o['status_group'] ?? '') === 'faturado');
            $vAuth = array_filter($vOrders, fn ($o) => ($o['status_group'] ?? '') === 'autorizado');
            $vCancel = array_filter($vOrders, fn ($o) => ($o['status_group'] ?? '') === 'cancelado');

            $vBilledVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $vBilled));
            $vBilledMerch = array_sum(array_map(fn ($o) => Support::toFloat($o['merchandise_total'] ?? 0), $vBilled));
            $vAuthVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $vAuth));
            $vAuthMerch = array_sum(array_map(fn ($o) => Support::toFloat($o['merchandise_total'] ?? 0), $vAuth));
            $vCancelVal = array_sum(array_map(fn ($o) => Support::toFloat($o['gross_total'] ?? 0), $vCancel));
            $vBilledQty = count($vBilled);
            $vAuthQty = count($vAuth);
            $vCancelQty = count($vCancel);

            $daysToBill = [];
            foreach ($vBilled as $o) {
                $issue = $o['issue_date'] ?? '';
                $billing = $o['billing_date'] ?? '';
                if ($issue !== '' && $billing !== '') {
                    $diff = (int) (new DateTimeImmutable((string) $billing))->diff(new DateTimeImmutable((string) $issue))->days;
                    $daysToBill[] = $diff;
                }
            }
            $avgDaysToBill = $daysToBill !== [] ? round(array_sum($daysToBill) / count($daysToBill), 1) : 0;

            $vId = '';
            foreach ($vOrders as $o) {
                if (!empty($o['vendor_id'])) {
                    $vId = (string) $o['vendor_id'];
                    break;
                }
            }

            $vendorMatrix[] = [
                'vendor_id' => $vId,
                'vendor_name' => $vName,
                'billed_value' => Support::money($vBilledVal),
                'billed_merchandise' => Support::money($vBilledMerch),
                'billed_orders' => $vBilledQty,
                'authorized_value' => Support::money($vAuthVal),
                'authorized_orders' => $vAuthQty,
                'pipeline_merchandise' => Support::money($vAuthMerch),
                'cancelled_value' => Support::money($vCancelVal),
                'cancelled_orders' => $vCancelQty,
                'backlog_value' => Support::money($vAuthVal),
                'conversion_rate' => ($vAuthQty + $vBilledQty) > 0 ? round(($vBilledQty / ($vAuthQty + $vBilledQty)) * 100, 2) : 0,
                'cancel_rate' => ($vAuthQty + $vCancelQty) > 0 ? round(($vCancelQty / ($vAuthQty + $vCancelQty)) * 100, 2) : 0,
                'avg_ticket_billed' => $vBilledQty > 0 ? Support::money($vBilledMerch / $vBilledQty) : 0,
                'avg_days_to_bill' => $avgDaysToBill,
                'share_billed' => $billedVal > 0 ? round(($vBilledVal / $billedVal) * 100, 2) : 0,
            ];
        }
        usort($vendorMatrix, fn ($a, $b) => $b['billed_value'] <=> $a['billed_value']);

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
        foreach ($orders as $order) {
            $date = $order['status_date'] ?? $order['billing_date'] ?? $order['issue_date'] ?? '';
            if ($date === '' || !isset($dailyStatus[$date])) {
                continue;
            }
            $group = $order['status_group'] ?? 'faturado';
            $val = Support::toFloat($order['gross_total'] ?? 0);
            if ($group === 'faturado') {
                $dailyStatus[$date]['billed_value'] += $val;
                $dailyStatus[$date]['billed_orders']++;
            } elseif ($group === 'autorizado') {
                $dailyStatus[$date]['authorized_value'] += $val;
                $dailyStatus[$date]['authorized_orders']++;
            } elseif ($group === 'cancelado') {
                $dailyStatus[$date]['cancelled_value'] += $val;
                $dailyStatus[$date]['cancelled_orders']++;
            }
        }
        ksort($dailyStatus);

        $funnel = [
            'authorized' => Support::money($authVal),
            'billed' => Support::money($billedVal),
            'cancelled' => Support::money($cancelVal),
            'pending_orders' => Support::money($authVal),
            'received' => 0.0,
        ];

        $alertsOp = [];
        $staleAuth = array_filter($byStatus['autorizado'], fn ($o) => ($o['days_in_stage'] ?? 0) > 5);
        if ($staleAuth !== []) {
            $names = array_unique(array_map(fn ($o) => $o['vendor_name'] ?? '?', array_slice($staleAuth, 0, 3)));
            $alertsOp[] = [
                'level' => 'warning',
                'title' => count($staleAuth) . ' pedido(s) autorizado(s) há mais de 5 dias',
                'message' => 'Vendedores: ' . implode(', ', $names) . (count($staleAuth) > 3 ? '...' : ''),
            ];
        }

        foreach ($vendorMatrix as $v) {
            if ($v['cancel_rate'] > 15 && ($v['cancelled_orders'] + $v['authorized_orders']) >= 3) {
                $alertsOp[] = [
                    'level' => 'danger',
                    'title' => $v['vendor_name'] . ': cancelamento de ' . $v['cancel_rate'] . '%',
                    'message' => $v['cancelled_orders'] . ' cancelado(s) de ' . ($v['cancelled_orders'] + $v['authorized_orders']) . ' pedido(s).',
                ];
            }
        }

        foreach ($vendorMatrix as $v) {
            if ($v['authorized_orders'] > 3 && $v['billed_orders'] === 0) {
                $alertsOp[] = [
                    'level' => 'warning',
                    'title' => $v['vendor_name'] . ': ' . $v['authorized_orders'] . ' autorizado(s), nenhum faturado',
                    'message' => 'Backlog acumulado de ' . number_format((float) $v['backlog_value'], 2, ',', '.') . '.',
                ];
            }
        }

        return [
            'summary_status' => $summaryStatus,
            'vendor_status_matrix' => $vendorMatrix,
            'daily_status' => array_values(array_map(fn ($d) => [
                'date' => $d['date'],
                'billed_value' => Support::money($d['billed_value']),
                'billed_orders' => $d['billed_orders'],
                'authorized_value' => Support::money($d['authorized_value']),
                'authorized_orders' => $d['authorized_orders'],
                'cancelled_value' => Support::money($d['cancelled_value']),
                'cancelled_orders' => $d['cancelled_orders'],
            ], $dailyStatus)),
            'funnel' => $funnel,
            'alerts_operational' => $alertsOp,
        ];
    }
}
