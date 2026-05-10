<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\Support;

final class OverviewDiagnosticsBuilder
{
    /**
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function buildTopOrdersIdentityDiagnostics(array $orders, array $meta = []): array
    {
        $customerSources = [
            'cliente_dim.razao_social' => 0,
            'nf_destinatario.cRazao' => 0,
            'cliente_dim.nome_fantasia' => 0,
            'pedido.cliente_nome' => 0,
            'cliente_dim.contato' => 0,
            'document_fallback' => 0,
            'missing' => 0,
        ];
        $sellerReasons = [
            'seller_code_missing' => 0,
            'seller_code_not_found' => 0,
        ];
        $documentsMissing = 0;
        $sellersResolved = 0;
        $clientSyncDates = [];

        foreach ($orders as $order) {
            $customerSource = (string) ($order['cliente_resolution_source'] ?? 'missing');
            if (!array_key_exists($customerSource, $customerSources)) {
                $customerSources[$customerSource] = 0;
            }
            $customerSources[$customerSource]++;

            if (trim((string) ($order['cliente_documento'] ?? '')) === '') {
                $documentsMissing++;
            }

            $sellerName = trim((string) ($order['vendor_name'] ?? ''));
            $sellerReason = (string) ($order['vendor_missing_reason'] ?? '');
            if ($sellerName !== '' && strcasecmp($sellerName, 'Sem vendedor') !== 0 && $sellerReason === '') {
                $sellersResolved++;
            } elseif ($sellerReason !== '') {
                if (!array_key_exists($sellerReason, $sellerReasons)) {
                    $sellerReasons[$sellerReason] = 0;
                }
                $sellerReasons[$sellerReason]++;
            }

            $clientSyncedAt = trim((string) ($order['cliente_dim_sincronizado_em'] ?? ''));
            if ($clientSyncedAt !== '') {
                $clientSyncDates[] = $clientSyncedAt;
            }
        }

        rsort($clientSyncDates);

        return [
            'total_notes_analyzed' => (int) ($meta['total_notes_analyzed'] ?? count($orders)),
            'sample_notes_analyzed' => count($orders),
            'customers' => [
                'resolved_by_dimension' => $customerSources['cliente_dim.razao_social'] ?? 0,
                'resolved_by_nf_recipient' => $customerSources['nf_destinatario.cRazao'] ?? 0,
                'resolved_by_fantasy_name' => $customerSources['cliente_dim.nome_fantasia'] ?? 0,
                'resolved_by_order' => $customerSources['pedido.cliente_nome'] ?? 0,
                'resolved_by_contact' => $customerSources['cliente_dim.contato'] ?? 0,
                'resolved_by_document' => $customerSources['document_fallback'] ?? 0,
                'not_informed' => $customerSources['missing'] ?? 0,
                'document_missing' => $documentsMissing,
                'sources' => $customerSources,
                'dimension_last_synced_at' => $clientSyncDates[0] ?? null,
            ],
            'sellers' => [
                'resolved' => $sellersResolved,
                'without_code' => $sellerReasons['seller_code_missing'] ?? 0,
                'code_not_found' => $sellerReasons['seller_code_not_found'] ?? 0,
                'reasons' => $sellerReasons,
            ],
            'source_table' => (string) ($meta['source_table'] ?? 'biomie_notas_fiscais_canonicas'),
            'last_materialized_at' => $meta['last_materialized_at'] ?? null,
            'recommended_action' => 'Sincronizar clientes/vendedores e reprocessar a camada canonica quando houver muitos vinculos ausentes.',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function canonicalHasSignal(array $payload): bool
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $revenue = Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
        $orders = (int) ($summary['orders'] ?? 0);
        $pendingOrders = (int) ($summary['pending_orders'] ?? 0);
        $activeVendors = (int) ($summary['active_vendors'] ?? 0);

        return $revenue > 0
            || $orders > 0
            || $pendingOrders > 0
            || $activeVendors > 0
            || $this->rowsContainSignal($payload['daily_sales'] ?? [], ['revenue', 'gross_revenue', 'orders'])
            || $this->rowsContainSignal($payload['vendor_ranking'] ?? [], ['revenue', 'gross_revenue', 'orders']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function historicalHasStrongSignal(array $payload): bool
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $revenue = Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
        $orders = (int) ($summary['orders'] ?? 0);
        $activeVendors = (int) ($summary['active_vendors'] ?? 0);
        $activeClients = (int) ($summary['active_clients'] ?? 0);

        return $revenue > 0
            || $orders > 0
            || $activeVendors > 0
            || $activeClients > 0
            || $this->rowsContainSignal($payload['daily_sales'] ?? [], ['revenue', 'gross_revenue', 'orders'])
            || $this->rowsContainSignal($payload['vendor_ranking'] ?? [], ['revenue', 'gross_revenue', 'orders']);
    }

    /**
     * @param mixed $rows
     * @param list<string> $keys
     */
    public function rowsContainSignal(mixed $rows, array $keys): bool
    {
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                if (Support::toFloat($row[$key] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
