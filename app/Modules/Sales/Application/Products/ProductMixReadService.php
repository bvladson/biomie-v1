<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Products;

use BIomie\Modules\Sales\Infrastructure\ProductMixReadRepository;
use BIomie\RequestContext;
use BIomie\Support;

final class ProductMixReadService
{
    public function __construct(private readonly ProductMixReadRepository $repo) {}

    /** @return array<string,mixed> */
    public function build(
        string $start,
        string $end,
        int $limit = 100,
        string $productCode = '',
        int $breakdownLimit = 200,
        string $scope = 'commercial'
    ): array
    {
        $scope = $this->normalizeScope($scope);
        $scopeLabel = $this->scopeLabel($scope);
        $summaryRaw = $this->repo->summary($start, $end, $scope);
        $productsRaw = $this->repo->products($start, $end, $limit, $scope);
        $breakdownRaw = $productCode !== '' ? $this->repo->breakdown($start, $end, $productCode, $breakdownLimit, $scope) : [];

        $invoices = (int) ($summaryRaw['invoices_count'] ?? 0);
        $items = (int) ($summaryRaw['items_count'] ?? 0);
        $productsCount = (int) ($summaryRaw['products_count'] ?? count($productsRaw));
        $revenue = (float) ($summaryRaw['revenue_total'] ?? 0);
        $mixTotal = (float) ($summaryRaw['mix_total'] ?? 0);

        $products = array_map(static function (array $row) use ($mixTotal): array {
            $revenue = (float) ($row['revenue'] ?? 0);
            $code = (string) ($row['product_code'] ?? '');
            $description = (string) ($row['description'] ?? 'Produto sem descricao');
            return [
                'product_code' => $code,
                'codigo_produto' => $code,
                'description' => $description,
                'product_name' => $description,
                'scope' => (string) ($row['scope'] ?? 'commercial'),
                'scope_label' => (string) ($row['scope_label'] ?? 'Orc-PD'),
                'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
                'revenue' => Support::money($revenue),
                'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
                'orders' => (int) ($row['orders'] ?? 0),
                'invoices_count' => (int) ($row['invoices_count'] ?? 0),
                'customers_count' => (int) ($row['customers_count'] ?? 0),
                'vendors_count' => (int) ($row['vendors_count'] ?? 0),
                'share_pct' => $mixTotal > 0 ? round(($revenue / $mixTotal) * 100, 2) : 0.0,
                'first_sale_at' => $row['first_sale_at'] ?? null,
                'last_sale_at' => $row['last_sale_at'] ?? null,
                'source' => 'biomie_notas_fiscais_itens_canonicos',
            ];
        }, $productsRaw);

        $breakdown = array_map(static function (array $row) use ($scope, $scopeLabel): array {
            return [
                'nota_key' => (string) ($row['nota_key'] ?? ''),
                'chave_nfe' => (string) ($row['chave_nfe'] ?? ''),
                'numero_nf' => (string) ($row['numero_nf'] ?? ''),
                'serie_nf' => (string) ($row['serie_nf'] ?? ''),
                'origin_order_code' => (string) ($row['origin_order_code'] ?? ''),
                'pedido_id_omie' => (string) ($row['pedido_id_omie'] ?? ''),
                'data_competencia' => (string) ($row['data_competencia'] ?? ''),
                'customer_id' => (string) ($row['customer_id'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? 'Cliente nao informado'),
                'customer_document' => (string) ($row['customer_document'] ?? ''),
                'vendor_id' => (string) ($row['vendor_id'] ?? ''),
                'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                'operation_scope' => (string) ($row['operation_scope'] ?? $scope),
                'scope_label' => (string) ($row['scope_label'] ?? $scopeLabel),
                'omie_operation_name' => (string) ($row['omie_operation_name'] ?? ''),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
                'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
                'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
                'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
            ];
        }, $breakdownRaw);

        $emptyStates = [
            'products' => $this->emptyState($invoices, $items, $productsCount),
            'top_products' => $this->emptyState($invoices, $items, $productsCount),
            'product_mix' => $this->emptyState($invoices, $items, $productsCount),
        ];

        return [
            'ok' => true,
            'mode' => 'canonical_product_mix',
            'source' => $products !== [] ? 'canonical_items' : 'empty',
            'period' => ['start' => $start, 'end' => $end, 'period_basis' => 'data_competencia'],
            'scope' => $scope,
            'scope_label' => $scopeLabel,
            'available_scopes' => [
                ['value' => 'commercial', 'label' => 'Orc-PD'],
                ['value' => 'remittance', 'label' => 'Remessas'],
                ['value' => 'all', 'label' => 'Todas'],
            ],
            'summary' => [
                'revenue' => Support::money($revenue),
                'gross_revenue' => Support::money($revenue),
                'revenue_total' => Support::money($revenue),
                'mix_total' => Support::money($mixTotal),
                'merchandise_total' => Support::money((float) ($summaryRaw['merchandise_total'] ?? $mixTotal)),
                'discounts_total' => Support::money((float) ($summaryRaw['discount_total'] ?? 0)),
                'discount_total' => Support::money((float) ($summaryRaw['discount_total'] ?? 0)),
                'orders' => $invoices,
                'invoices_count' => $invoices,
                'items_count' => $items,
                'products_count' => $productsCount,
                'avg_ticket' => $invoices > 0 ? Support::money($revenue / $invoices) : 0.0,
                'active_vendors' => (int) ($summaryRaw['vendors_count'] ?? 0),
            ],
            'products' => $products,
            'top_products' => array_slice($products, 0, 12),
            'topProducts' => array_slice($products, 0, 12),
            'product_mix' => array_slice($products, 0, 12),
            'productMix' => array_slice($products, 0, 12),
            'mix_share' => array_slice($products, 0, 12),
            'breakdown' => $breakdown,
            'empty_states' => $emptyStates,
            'diagnostics' => [
                'canonical_nfs_count' => $invoices,
                'canonical_items_count' => $items,
                'distinct_product_count' => $productsCount,
                'product_payload_count' => count($products),
                'empty_reason' => $emptyStates['products']['reason_code'] ?? null,
                'request_id' => RequestContext::id(),
            ],
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'request_id' => RequestContext::id(),
                'served_from' => 'canonical_product_mix',
                'period_basis' => 'data_competencia',
                'realized_period_basis' => 'data_competencia',
                'scope' => $scope,
                'scope_label' => $scopeLabel,
                'selected_product_code' => $productCode !== '' ? $productCode : null,
                'product_breakdown' => [
                    'status' => $productCode !== '' ? 'available' : 'idle',
                    'available' => $productCode !== '',
                    'source' => 'canonical_items',
                    'limit' => $breakdownLimit,
                ],
            ],
        ];
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        return in_array($scope, ['commercial', 'remittance', 'all'], true) ? $scope : 'commercial';
    }

    private function scopeLabel(string $scope): string
    {
        return match ($this->normalizeScope($scope)) {
            'remittance' => 'Remessas',
            'all' => 'Todas',
            default => 'Orc-PD',
        };
    }

    /** @return array<string,mixed> */
    private function emptyState(int $invoices, int $items, int $products): array
    {
        if ($invoices === 0) {
            return [
                'is_empty' => true,
                'reason_code' => 'no_authorized_nfs',
                'title' => 'Sem produtos faturados no período',
                'message' => 'Nenhuma NF autorizada foi encontrada por data_competencia no período selecionado.',
                'action' => 'Verifique o período ou a sincronização fiscal.',
            ];
        }
        if ($items === 0) {
            return [
                'is_empty' => true,
                'reason_code' => 'items_missing',
                'title' => 'Itens das NFs não encontrados',
                'message' => 'Existem NFs autorizadas no período, mas os itens canônicos não foram localizados.',
                'action' => 'Reprocessar sync fiscal de itens e materialização de produtos.',
            ];
        }
        if ($products === 0) {
            return [
                'is_empty' => true,
                'reason_code' => 'products_not_grouped',
                'title' => 'Produtos não agrupados',
                'message' => 'Existem itens fiscais, mas nenhum produto distinto foi agrupado.',
                'action' => 'Verificar codigo_produto e descricao_produto na camada canônica.',
            ];
        }
        return ['is_empty' => false, 'reason_code' => null, 'title' => null, 'message' => null, 'action' => null];
    }
}
