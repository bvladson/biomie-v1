<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Readers;

use BIomie\Support\CustomerDisplayResolver;
use BIomie\Support\SellerDisplayResolver;

final class CanonicalDisplayMapper
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function mapOrderRows(array $rows, string $defaultClient = 'Cliente nao informado'): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $grossTotal  = (float) ($row['valor_total'] ?? 0);
            $merchandise = (float) ($row['valor_mercadorias'] ?? 0);
            $discount    = (float) ($row['valor_descontos'] ?? 0);
            $customer = CustomerDisplayResolver::resolve($row + [
                'pedido_cliente_nome' => $row['nome_cliente'] ?? null,
                'pedido_documento_cliente' => $row['documento_cliente'] ?? null,
            ]);
            $seller = SellerDisplayResolver::resolve($row + [
                'pedido_codVend' => $row['codigo_vendedor_omie'] ?? null,
                'pedido_vendedor_nome' => $row['nome_vendedor'] ?? null,
            ]);

            $orders[] = [
                'codigo'             => (string) ($row['codigo_pedido'] ?? ''),
                'codigo_integracao'  => (string) ($row['codigo_integracao'] ?? ''),
                'cliente'            => $customer['display_name'] !== '' ? $customer['display_name'] : $defaultClient,
                'cliente_documento'  => (string) ($customer['document_masked'] ?? ''),
                'cliente_resolution_source' => $customer['source'],
                'cliente_resolution_quality' => $customer['quality'],
                'cliente_missing_reason' => $customer['missing_reason'],
                'cliente_dim_sincronizado_em' => (string) ($row['cliente_dim_sincronizado_em'] ?? ''),
                'vendor_id'          => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name'        => $seller['display_name'],
                'vendor_resolution_source' => $seller['source'],
                'vendor_resolution_quality' => $seller['quality'],
                'vendor_missing_reason' => $seller['missing_reason'],
                'issue_date'         => (string) ($row['data_emissao'] ?? ''),
                'billing_date'       => (string) ($row['data_faturamento'] ?? ''),
                'stage'              => (string) ($row['etapa_omie'] ?? $row['status_pedido_omie'] ?? ''),
                'status_group'       => (string) ($row['status_comercial'] ?? 'outro'),
                'items_qty'          => (int) ($row['quantidade_itens'] ?? 0),
                'gross_total'        => $grossTotal,
                'merchandise_total'  => $merchandise,
                'discount_total'     => $discount,
                'total'              => $grossTotal,
            ];
        }

        return $orders;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function mapInvoiceRows(array $rows): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $grossTotal  = (float) ($row['valor_total'] ?? 0);
            $merchandise = (float) ($row['valor_mercadorias'] ?? 0);
            $discount    = (float) ($row['valor_descontos'] ?? 0);
            $number      = trim((string) ($row['numero_nf'] ?? ''));
            $serie       = trim((string) ($row['serie_nf'] ?? ''));
            $codigo      = $number !== ''
                ? trim($number . ($serie !== '' ? '/' . $serie : ''))
                : (string) ($row['chave_nfe'] ?? 'NF sem numero');

            $statusFiscal = (string) ($row['status_fiscal'] ?? 'autorizada');
            $issueDate    = (string) ($row['data_emissao'] ?? '');
            $cancelDate   = (string) ($row['data_cancelamento'] ?? '');
            $statusGroup  = $statusFiscal === 'cancelada' ? 'cancelado' : 'faturado';
            $customer = CustomerDisplayResolver::resolve($row + [
                'nf_dest_cRazao' => $row['nome_cliente'] ?? null,
                'nf_dest_cnpj_cpf' => $row['documento_cliente'] ?? null,
            ]);
            $seller = SellerDisplayResolver::resolve($row + [
                'pedido_codVend' => $row['codigo_vendedor_omie'] ?? null,
                'vendedor_nome' => $row['vendedor_dim_nome'] ?? null,
                'pedido_vendedor_nome' => $row['nome_vendedor'] ?? null,
            ]);

            $orders[] = [
                'codigo'             => $codigo,
                'codigo_integracao'  => (string) ($row['codigo_pedido_origem'] ?? ($row['chave_nfe'] ?? '')),
                'cliente'            => $customer['display_name'],
                'cliente_documento'  => (string) ($customer['document_masked'] ?? ''),
                'cliente_resolution_source' => $customer['source'],
                'cliente_resolution_quality' => $customer['quality'],
                'cliente_missing_reason' => $customer['missing_reason'],
                'cliente_dim_sincronizado_em' => (string) ($row['cliente_dim_sincronizado_em'] ?? ''),
                'vendor_id'          => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name'        => $seller['display_name'],
                'vendor_resolution_source' => $seller['source'],
                'vendor_resolution_quality' => $seller['quality'],
                'vendor_missing_reason' => $seller['missing_reason'],
                'issue_date'         => $issueDate,
                'billing_date'       => $issueDate,
                'stage'              => $statusGroup === 'cancelado' ? 'NF CANCELADA' : 'NF AUTORIZADA',
                'status_group'       => $statusGroup,
                'items_qty'          => (int) ($row['quantidade_itens'] ?? 0),
                'gross_total'        => $grossTotal,
                'merchandise_total'  => $merchandise,
                'discount_total'     => $discount,
                'total'              => $grossTotal,
                'status_date'        => $statusGroup === 'cancelado'
                    ? ($cancelDate !== '' ? $cancelDate : $issueDate)
                    : $issueDate,
                'document_key'       => (string) ($row['chave_nfe'] ?? ''),
                'origin_order_code'  => (string) ($row['codigo_pedido_origem'] ?? ''),
            ];
        }

        return $orders;
    }
}
