<?php
declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\CanonicalRepository;
use BIomie\Support;

class VendorRealizedService
{
    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly VendorIdentityResolver $resolver
    ) {}

    public function summarizeByVendor(string $start, string $end): array
    {
        // Fonte: fallback biomie_notas_fiscais_canonicas + bridge
        $invoices = $this->canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null);
        $summary = [];

        foreach ($invoices as $row) {
            $status = mb_strtolower(trim((string)($row['status_comercial'] ?? 'faturado')));
            // Exclude cancelled/denied if not already handled
            if ($status === 'cancelado' || $status === 'denegado' || $status === 'inutilizado') {
                continue;
            }

            $vId = trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vName = trim((string)($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''));
            $ref = $vId !== '' ? $vId : $vName;

            $identity = $this->resolver->resolve($ref);
            $key = $identity['vendor_id'];

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'vendor_key' => $key,
                    'vendor_id' => $identity['vendor_id'],
                    'vendor_name' => $identity['vendor_name'],
                    'realized' => 0.0,
                    'merchandise_total' => 0.0,
                    'invoices_count' => 0,
                    'orders_count' => 0,
                    'customers_count' => 0,
                    'ticket' => 0.0,
                    'source' => 'canonical_fallback',
                    'date_field' => 'data_competencia|data_emissao',
                    'value_field' => 'total',
                    'cancelled_excluded' => true,
                    'diagnostics' => ['identity' => $identity],
                    '_customer_set' => []
                ];
            }

            $total = Support::toFloat($row['total'] ?? 0);
            $merchandise = Support::toFloat($row['merchandise_total'] ?? $row['mercadorias'] ?? 0);
            
            $summary[$key]['realized'] += $total;
            $summary[$key]['merchandise_total'] += $merchandise;
            $summary[$key]['invoices_count']++;
            $summary[$key]['orders_count'] += (int)($row['pedidos'] ?? 1);
            
            $cid = trim((string)($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? ''));
            if ($cid !== '') {
                $summary[$key]['_customer_set'][$cid] = true;
            }
        }

        foreach ($summary as &$s) {
            $s['customers_count'] = count($s['_customer_set']);
            unset($s['_customer_set']);
            if ($s['invoices_count'] > 0) {
                $s['ticket'] = $s['realized'] / $s['invoices_count'];
            }
        }

        return array_values($summary);
    }

    public function summarizeOneVendor(string $start, string $end, array $vendorIdentity): array
    {
        $all = $this->summarizeByVendor($start, $end);
        foreach ($all as $v) {
            if ($v['vendor_id'] === $vendorIdentity['vendor_id']) {
                return $v;
            }
        }
        return [
            'vendor_key' => $vendorIdentity['vendor_id'],
            'vendor_id' => $vendorIdentity['vendor_id'],
            'vendor_name' => $vendorIdentity['vendor_name'],
            'realized' => 0.0,
            'merchandise_total' => 0.0,
            'invoices_count' => 0,
            'orders_count' => 0,
            'customers_count' => 0,
            'ticket' => 0.0,
            'source' => 'canonical_fallback',
            'date_field' => 'data_competencia|data_emissao',
            'value_field' => 'total',
            'cancelled_excluded' => true,
            'diagnostics' => ['identity' => $vendorIdentity],
        ];
    }

    public function explainVendor(string $start, string $end, array $vendorIdentity): array
    {
        $invoices = $this->canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null);
        $composition = [];
        $total = 0.0;
        $totalMerc = 0.0;

        foreach ($invoices as $row) {
            $status = mb_strtolower(trim((string)($row['status_comercial'] ?? 'faturado')));
            if ($status === 'cancelado' || $status === 'denegado' || $status === 'inutilizado') {
                continue;
            }

            $vId = trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vName = trim((string)($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''));
            $ref = $vId !== '' ? $vId : $vName;

            $identity = $this->resolver->resolve($ref);
            if ($identity['vendor_id'] === $vendorIdentity['vendor_id']) {
                $val = Support::toFloat($row['total'] ?? 0);
                $merc = Support::toFloat($row['merchandise_total'] ?? $row['mercadorias'] ?? 0);
                
                $composition[] = [
                    'nf' => $row['numero_nfe'] ?? $row['numero_documento'] ?? '',
                    'emissao' => $row['data_emissao'] ?? $row['issue_date'] ?? '',
                    'competencia' => $row['data_competencia'] ?? $row['issue_date'] ?? '',
                    'cliente' => $row['cliente'] ?? $row['customer_name'] ?? '',
                    'pedido' => $row['numero_pedido'] ?? '',
                    'valor_usado' => $val,
                    'valor_mercadoria' => $merc,
                    'desconto' => Support::toFloat($row['desconto'] ?? $row['discount_total'] ?? 0),
                    'status' => $status,
                    'fonte' => 'canonical_fallback',
                    'incluida' => 'sim',
                    'motivo' => 'nf_autorizada'
                ];
                $total += $val;
                $totalMerc += $merc;
            }
        }

        return [
            'vendor' => $vendorIdentity,
            'total_realized' => $total,
            'total_merchandise' => $totalMerc,
            'invoices' => $composition
        ];
    }
}
