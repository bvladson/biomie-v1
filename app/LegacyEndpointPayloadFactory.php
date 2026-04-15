<?php

declare(strict_types=1);

namespace BIomie;

final class LegacyEndpointPayloadFactory
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function overview(array $snapshot, DatasetFreshnessService $freshness): array
    {
        $snapshot['source'] = $snapshot['source'] ?? 'legacy';
        return $freshness->attach('overview', $snapshot);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function orders(array $snapshot, DatasetFreshnessService $freshness, string $statusFilter = ''): array
    {
        $orders = is_array($snapshot['top_orders'] ?? null) ? $snapshot['top_orders'] : [];
        $vendorLookup = [];
        foreach (($snapshot['vendor_ranking'] ?? []) as $vendor) {
            foreach (self::buildVendorLookupKeys($vendor['vendor_id'] ?? '') as $key) {
                $vendorLookup[$key] = (string) ($vendor['vendor_name'] ?? '');
            }
        }

        foreach ($orders as $index => $row) {
            $vendorName = trim((string) ($row['vendor_name'] ?? ''));
            if ($vendorName === '' || strcasecmp($vendorName, 'Sem vendedor') === 0) {
                foreach (self::buildVendorLookupKeys($row['vendor_id'] ?? '') as $key) {
                    if (!isset($vendorLookup[$key]) || trim((string) $vendorLookup[$key]) === '') {
                        continue;
                    }
                    $orders[$index]['vendor_name'] = $vendorLookup[$key];
                    break;
                }
            }
        }

        if ($statusFilter !== '') {
            $allowedStatuses = array_map('trim', explode(',', strtolower($statusFilter)));
            $orders = array_values(array_filter($orders, static function (array $row) use ($allowedStatuses): bool {
                $stage = strtolower(trim((string) ($row['stage'] ?? '')));
                if ($stage === '') {
                    return in_array('sem_status', $allowedStatuses, true);
                }
                foreach ($allowedStatuses as $filter) {
                    if (str_contains($stage, $filter)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        if ($orders !== ($snapshot['top_orders'] ?? [])) {
            $summary = self::rebuildOrdersSummary($summary, $orders);
        }

        return $freshness->attach('orders', [
            'ok' => true,
            'mode' => $snapshot['mode'] ?? 'live',
            'source' => 'legacy',
            'period' => $snapshot['period'] ?? [],
            'summary' => $summary,
            'orders' => $orders,
            'pending_orders' => [],
            'cancelled_orders' => [],
            'meta' => $snapshot['meta'] ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @return array<string,mixed>
     */
    public static function vendors(
        array $snapshot,
        array $range,
        string $source,
        ?string $canonicalError = null,
    ): array {
        $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
        if ($canonicalError !== null) {
            $meta['canonical_error'] = $canonicalError;
        }

        return [
            'ok' => true,
            'mode' => (string) ($snapshot['mode'] ?? 'live'),
            'source' => $source,
            'period' => is_array($snapshot['period'] ?? null) ? $snapshot['period'] : [
                'start' => $range['start'],
                'end' => $range['end'],
                'label' => $range['label'],
            ],
            'summary' => is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [],
            'summary_status' => is_array($snapshot['summary_status'] ?? null) ? $snapshot['summary_status'] : [],
            'vendors' => is_array($snapshot['vendor_ranking'] ?? null) ? $snapshot['vendor_ranking'] : [],
            'vendor_status_matrix' => is_array($snapshot['vendor_status_matrix'] ?? null) ? $snapshot['vendor_status_matrix'] : [],
            'daily_status' => is_array($snapshot['daily_status'] ?? null) ? $snapshot['daily_status'] : [],
            'funnel' => is_array($snapshot['funnel'] ?? null) ? $snapshot['funnel'] : [],
            'alerts_operational' => is_array($snapshot['alerts_operational'] ?? null) ? $snapshot['alerts_operational'] : [],
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function financeOverview(array $snapshot, DatasetFreshnessService $freshness): array
    {
        return $freshness->attach('finance_overview', [
            'ok' => true,
            'mode' => $snapshot['mode'] ?? 'live-finance',
            'source' => $snapshot['source'] ?? 'historical-snapshot',
            'period' => $snapshot['period'] ?? [],
            'summary' => $snapshot['summary'] ?? [],
            'received_rows' => $snapshot['received_rows'] ?? [],
            'open_titles' => $snapshot['open_titles'] ?? [],
            'overdue_titles' => $snapshot['overdue_titles'] ?? [],
            'by_account' => $snapshot['by_account'] ?? [],
            'by_vendor' => $snapshot['by_vendor'] ?? [],
            'by_client' => $snapshot['by_client'] ?? [],
            'budget' => $snapshot['budget'] ?? [],
            'trend' => $snapshot['trend'] ?? [],
            'aging' => $snapshot['aging'] ?? [],
            'status_breakdown' => $snapshot['status_breakdown'] ?? [],
            'document_types' => $snapshot['document_types'] ?? [],
            'payment_methods' => $snapshot['payment_methods'] ?? [],
            'meta' => $snapshot['meta'] ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function financeTitles(array $snapshot, DatasetFreshnessService $freshness, string $bucket): array
    {
        $bucket = strtolower(trim($bucket));
        $map = [
            'open' => 'open_titles',
            'overdue' => 'overdue_titles',
            'received' => 'received_rows',
        ];
        $key = $map[$bucket] ?? 'open_titles';

        return $freshness->attach('finance_titles', [
            'ok' => true,
            'mode' => $snapshot['mode'] ?? 'live-finance',
            'source' => $snapshot['source'] ?? 'historical-snapshot',
            'period' => $snapshot['period'] ?? [],
            'bucket' => $bucket,
            'rows' => $snapshot[$key] ?? [],
            'meta' => $snapshot['meta'] ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function reconciliation(array $snapshot, DatasetFreshnessService $freshness, string $start, string $end): array
    {
        $summary = is_array($snapshot['summary_status'] ?? null) ? $snapshot['summary_status'] : [];
        $vendors = [];

        foreach (($snapshot['vendor_status_matrix'] ?? []) as $row) {
            $vendors[] = [
                'codigo_vendedor' => (string) ($row['vendor_id'] ?? ''),
                'nome' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                'faturado' => Support::money((float) ($row['billed_value'] ?? 0)),
                'faturado_qtd' => (int) ($row['billed_orders'] ?? 0),
                'autorizado' => Support::money((float) ($row['authorized_value'] ?? 0)),
                'autorizado_qtd' => (int) ($row['authorized_orders'] ?? 0),
                'cancelado' => Support::money((float) ($row['cancelled_value'] ?? 0)),
                'cancelado_qtd' => (int) ($row['cancelled_orders'] ?? 0),
                'total_pedidos' => (int) ($row['billed_orders'] ?? 0)
                    + (int) ($row['authorized_orders'] ?? 0)
                    + (int) ($row['cancelled_orders'] ?? 0),
                'total_geral' => Support::money(
                    (float) ($row['billed_value'] ?? 0)
                    + (float) ($row['authorized_value'] ?? 0)
                    + (float) ($row['cancelled_value'] ?? 0)
                ),
                'ultima_sync' => $snapshot['meta']['generated_at'] ?? null,
            ];
        }

        return $freshness->attach('reconciliation', [
            'ok' => true,
            'source' => 'snapshot_fiscal',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'totals' => [
                'faturado' => Support::money((float) ($summary['billed_value'] ?? 0)),
                'autorizado' => Support::money((float) ($summary['authorized_value'] ?? 0)),
                'cancelado' => Support::money((float) ($summary['cancelled_value'] ?? 0)),
                'realizado' => Support::money((float) ($summary['billed_value'] ?? 0)),
                'pedidos_faturados' => (int) ($summary['billed_orders'] ?? 0),
                'pedidos_autorizados' => (int) ($summary['authorized_orders'] ?? 0),
                'pedidos_cancelados' => (int) ($summary['cancelled_orders'] ?? 0),
            ],
            'vendors' => $vendors,
            'divergences_count' => 0,
            'divergences' => [],
            'last_sync' => $snapshot['meta']['generated_at'] ?? null,
            'generated_at' => date(DATE_ATOM),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function buildVendorLookupKeys(mixed $vendorId): array
    {
        $raw = trim((string) $vendorId);
        if ($raw === '') {
            return [];
        }

        $keys = [$raw];
        $digits = Support::digitsOnly($raw);
        if ($digits !== '') {
            $keys[] = $digits;
            $keys[] = ltrim($digits, '0') ?: '0';
        }

        return array_values(array_unique(array_filter($keys, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $orders
     * @return array<string,mixed>
     */
    private static function rebuildOrdersSummary(array $summary, array $orders): array
    {
        $gross = 0.0;
        $merchandise = 0.0;
        $discounts = 0.0;
        $vendors = [];

        foreach ($orders as $row) {
            $gross += Support::toFloat($row['gross_total'] ?? $row['total'] ?? 0);
            $merchandise += Support::toFloat($row['merchandise_total'] ?? 0);
            $discounts += Support::toFloat($row['discount_total'] ?? 0);
            $vendorName = trim((string) ($row['vendor_name'] ?? ''));
            if ($vendorName !== '' && strcasecmp($vendorName, 'Sem vendedor') !== 0) {
                $vendors[strtolower($vendorName)] = true;
            }
        }

        $ordersCount = count($orders);
        $summary['revenue'] = Support::money($gross);
        $summary['gross_revenue'] = Support::money($gross);
        $summary['merchandise_total'] = Support::money($merchandise);
        $summary['discounts_total'] = Support::money($discounts);
        $summary['net_after_discount'] = Support::money($merchandise - $discounts);
        $summary['orders'] = $ordersCount;
        $summary['avg_ticket'] = Support::money($ordersCount > 0 ? $gross / $ordersCount : 0);
        $summary['active_vendors'] = count($vendors);

        return $summary;
    }
}
