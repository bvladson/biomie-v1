<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Products;

use BIomie\Modules\Sales\Infrastructure\Product360ReadRepository;
use BIomie\RequestContext;
use BIomie\Support;
use PDO;

/**
 * Orquestra a leitura do Product 360 a partir de dados locais canônicos e gold.
 * Nunca chama a API Omie em tempo de tela.
 */
final class Product360ReadService
{
    public function __construct(private readonly Product360ReadRepository $repo) {}

    /**
     * @return array<string,mixed>
     */
    public function build(
        string $productCode,
        string $start,
        string $end,
        int    $limit = 50,
        ?string $tab = null
    ): array {
        $freshnessStatus = $this->freshnessStatus(...);

        $lastCanonical    = $this->repo->lastCanonicalNfAt();
        $lastGold         = $this->repo->lastGoldMaterializedAt();
        $canonicalStatus  = $freshnessStatus($lastCanonical);
        $goldStatus       = $freshnessStatus($lastGold);
        $isStale          = $canonicalStatus === 'critical';

        // Summary is always loaded (needed for empty states + tab Resumo)
        $summaryRaw = $this->repo->summary($productCode, $start, $end);

        $invoicesCount    = (int) ($summaryRaw['invoices_count'] ?? 0);
        $realizedTotal    = (float) ($summaryRaw['realized_total'] ?? 0.0);
        $quantityTotal    = (float) ($summaryRaw['quantity_total'] ?? 0.0);
        $customersCount   = (int) ($summaryRaw['customers_count'] ?? 0);
        $vendorsCount     = (int) ($summaryRaw['vendors_count'] ?? 0);
        $itemsWithoutMerch = (int) ($summaryRaw['items_without_merchandise'] ?? 0);
        $martRowCount     = $this->repo->martRowCount($productCode);

        $merchandiseTotal = (float) ($summaryRaw['merchandise_total'] ?? $realizedTotal);
        $discountTotal    = (float) ($summaryRaw['discount_total'] ?? 0.0);
        $ticketAvg        = $invoicesCount > 0 ? $realizedTotal / $invoicesCount : 0.0;
        $avgUnitPrice     = $quantityTotal > 0 ? $realizedTotal / $quantityTotal : 0.0;

        $summary = [
            'product_code'           => $productCode,
            'product_description'    => $summaryRaw['product_description'] ?? 'Produto sem descricao',
            'realized_total'         => Support::money($realizedTotal),
            'merchandise_total'      => Support::money($merchandiseTotal),
            'quantity_total'         => (float) $quantityTotal,
            'discount_total'         => Support::money($discountTotal),
            'invoices_count'         => $invoicesCount,
            'customers_count'        => $customersCount,
            'vendors_count'          => $vendorsCount,
            'linked_orders_count'    => 0, // filled after linked_orders query
            'ticket_avg_per_invoice' => Support::money($ticketAvg),
            'avg_unit_price'         => Support::money($avgUnitPrice),
            'first_sale_at'          => $summaryRaw['first_sale_at'] ?? null,
            'last_sale_at'           => $summaryRaw['last_sale_at'] ?? null,
            'source'                 => $invoicesCount > 0 ? 'canonical' : 'empty',
            'realized_period_basis'  => 'data_competencia',
        ];

        // Load tab-specific data (or all if tab is null)
        $loadAll = $tab === null;

        $invoices      = ($loadAll || $tab === 'nfs')      ? $this->mapInvoices($this->repo->invoices($productCode, $start, $end, $limit))      : [];
        $customers     = ($loadAll || $tab === 'clientes') ? $this->mapCustomers($this->repo->customers($productCode, $start, $end, $limit))     : [];
        $vendors       = ($loadAll || $tab === 'vendedores') ? $this->mapVendors($this->repo->vendors($productCode, $start, $end, $limit), $realizedTotal) : [];
        $linkedOrders  = ($loadAll || $tab === 'pedidos')  ? $this->repo->linkedOrders($productCode, $start, $end, $limit) : [];
        $timeline      = ($loadAll || $tab === 'evolucao') ? $this->mapTimeline($this->repo->timeline($productCode, $start, $end)) : [];
        $ltv           = ($loadAll || $tab === 'ltv')      ? $this->mapLtv($this->repo->ltv($productCode), $realizedTotal)      : [];

        $summary['linked_orders_count'] = count($linkedOrders);

        $dataQuality = [
            'canonical_items_count'    => $invoicesCount,
            'authorized_invoices_count' => $invoicesCount,
            'linked_orders_count'      => count($linkedOrders),
            'mart_rows_count'          => $martRowCount,
            'source'                   => $invoicesCount > 0 ? 'canonical' : 'empty',
            'freshness_status'         => $canonicalStatus,
            'last_canonical_nf_at'     => $lastCanonical,
            'last_gold_materialized_at' => $lastGold,
            'warnings'                 => $this->buildWarnings($itemsWithoutMerch, $isStale, $invoicesCount, count($linkedOrders)),
        ];

        $emptyStates = $this->buildEmptyStates(
            $productCode,
            $invoicesCount,
            count($linkedOrders),
            $isStale,
            $martRowCount
        );

        $sourceLabel = $invoicesCount > 0 ? 'canonical' : 'empty';

        return [
            'ok'          => true,
            'source'      => $sourceLabel,
            'product'     => [
                'code'        => $productCode,
                'description' => $summary['product_description'],
            ],
            'period'      => [
                'start' => $start,
                'end'   => $end,
            ],
            'period_basis' => [
                'realized'      => 'data_competencia',
                'linked_orders' => 'data_competencia_nfe',
                'wallet'        => 'data_emissao',
            ],
            'summary'      => $summary,
            'invoices'     => $invoices,
            'customers'    => $customers,
            'vendors'      => $vendors,
            'linked_orders' => $linkedOrders,
            'timeline'     => $timeline,
            'ltv'          => $ltv,
            'data_quality' => $dataQuality,
            'empty_states' => $emptyStates,
            'meta'         => [
                'request_id'         => RequestContext::id(),
                'generated_at'       => date(DATE_ATOM),
                'realized_period_basis' => 'data_competencia',
                'product_code'       => $productCode,
                'limit'              => $limit,
                'tab'                => $tab,
            ],
        ];
    }

    // ── Mappers ───────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapInvoices(array $rows): array
    {
        return array_map(static function (array $row): array {
            return [
                'nota_key'          => (string) ($row['nota_key'] ?? ''),
                'chave_nfe'         => (string) ($row['chave_nfe'] ?? ''),
                'numero_nf'         => (string) ($row['numero_nf'] ?? ''),
                'serie_nf'          => (string) ($row['serie_nf'] ?? ''),
                'data_competencia'  => (string) ($row['data_competencia'] ?? ''),
                'customer_id'       => (string) ($row['customer_id'] ?? ''),
                'customer_name'     => (string) ($row['customer_name'] ?? ''),
                'customer_document' => (string) ($row['customer_document'] ?? ''),
                'vendor_id'         => (string) ($row['vendor_id'] ?? ''),
                'vendor_name'       => (string) ($row['vendor_name'] ?? ''),
                'quantity'          => (float) ($row['quantity'] ?? 0),
                'product_total'     => Support::money((float) ($row['product_total'] ?? 0)),
                'invoice_total'     => Support::money((float) ($row['invoice_total'] ?? 0)),
                'origin_order_code' => (string) ($row['origin_order_code'] ?? ''),
                'pedido_id_omie'    => (int) ($row['pedido_id_omie'] ?? 0),
                'status_fiscal'     => (string) ($row['status_fiscal'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapCustomers(array $rows): array
    {
        return array_map(static function (array $row): array {
            return [
                'customer_id'       => (string) ($row['customer_id'] ?? ''),
                'customer_name'     => (string) ($row['customer_name'] ?? ''),
                'customer_document' => (string) ($row['customer_document'] ?? ''),
                'revenue'           => Support::money((float) ($row['revenue'] ?? 0)),
                'quantity'          => (float) ($row['quantity'] ?? 0),
                'invoices_count'    => (int) ($row['invoices_count'] ?? 0),
                'ticket_avg'        => Support::money((float) ($row['ticket_avg'] ?? 0)),
                'first_purchase_at' => (string) ($row['first_purchase_at'] ?? ''),
                'last_purchase_at'  => (string) ($row['last_purchase_at'] ?? ''),
                'source'            => 'canonical',
            ];
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapVendors(array $rows, float $totalRevenue): array
    {
        return array_map(static function (array $row) use ($totalRevenue): array {
            $revenue = (float) ($row['revenue'] ?? 0);
            return [
                'vendor_id'         => (string) ($row['vendor_id'] ?? ''),
                'vendor_name'       => (string) ($row['vendor_name'] ?? ''),
                'revenue'           => Support::money($revenue),
                'quantity'          => (float) ($row['quantity'] ?? 0),
                'invoices_count'    => (int) ($row['invoices_count'] ?? 0),
                'customers_count'   => (int) ($row['customers_count'] ?? 0),
                'participation_pct' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 2) : 0.0,
                'first_sale_at'     => (string) ($row['first_sale_at'] ?? ''),
                'last_sale_at'      => (string) ($row['last_sale_at'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapTimeline(array $rows): array
    {
        return array_map(static function (array $row): array {
            return [
                'bucket'         => (string) ($row['bucket'] ?? ''),
                'label'          => (string) ($row['label'] ?? ''),
                'revenue'        => Support::money((float) ($row['revenue'] ?? 0)),
                'revenue_raw'    => (float) ($row['revenue'] ?? 0),
                'quantity'       => (float) ($row['quantity'] ?? 0),
                'invoices_count' => (int) ($row['invoices_count'] ?? 0),
                'customers_count' => (int) ($row['customers_count'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapLtv(array $row, float $currentRevenue): array
    {
        if ($row === []) {
            return [];
        }

        $lifetimeRevenue    = (float) ($row['lifetime_revenue'] ?? 0);
        $lifetimeCustomers  = (int)   ($row['lifetime_customers_count'] ?? 0);
        $repeatCustomers    = (int)   ($row['repeat_customers_count'] ?? 0);

        return [
            'lifetime_revenue'          => Support::money($lifetimeRevenue),
            'lifetime_revenue_raw'      => $lifetimeRevenue,
            'lifetime_quantity'         => (float) ($row['lifetime_quantity'] ?? 0),
            'lifetime_invoices_count'   => (int) ($row['lifetime_invoices_count'] ?? 0),
            'lifetime_customers_count'  => $lifetimeCustomers,
            'lifetime_first_sale_at'    => (string) ($row['lifetime_first_sale_at'] ?? ''),
            'lifetime_last_sale_at'     => (string) ($row['lifetime_last_sale_at'] ?? ''),
            'repeat_customers_count'    => $repeatCustomers,
            'repeat_customer_rate'      => $lifetimeCustomers > 0 ? round(($repeatCustomers / $lifetimeCustomers) * 100, 2) : 0.0,
            'avg_revenue_per_customer'  => Support::money($lifetimeCustomers > 0 ? $lifetimeRevenue / $lifetimeCustomers : 0.0),
            'current_period_revenue'    => Support::money($currentRevenue),
            'current_period_share_pct'  => $lifetimeRevenue > 0 ? round(($currentRevenue / $lifetimeRevenue) * 100, 2) : 0.0,
            'source'                    => 'canonical',
            'note'                      => 'LTV calculado com base no historico local sincronizado.',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function freshnessStatus(): \Closure
    {
        return static function (?string $at): string {
            if ($at === null || $at === '') {
                return 'unknown';
            }
            $age = time() - (int) strtotime($at);
            if ($age <= 86400) {
                return 'ok';
            }
            if ($age <= 259200) {
                return 'warning';
            }
            return 'critical';
        };
    }

    /**
     * @return list<string>
     */
    private function buildWarnings(int $itemsWithoutMerch, bool $isStale, int $invoicesCount, int $linkedOrdersCount): array
    {
        $warnings = [];
        if ($itemsWithoutMerch > 0) {
            $warnings[] = 'merchandise_total calculado a partir de valor_total (valor_mercadorias ausente em ' . $itemsWithoutMerch . ' item(ns)).';
        }
        if ($isStale) {
            $warnings[] = 'Origem canonica/Omie desatualizada (>72h). Dados podem estar incompletos.';
        }
        if ($invoicesCount > 0 && $linkedOrdersCount === 0) {
            $warnings[] = 'Ha NFs autorizadas mas nenhum pedido vinculado encontrado. Verifique mart_bridge_pedido_nfe_titulo.';
        }
        return $warnings;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildEmptyStates(
        string $productCode,
        int    $invoicesCount,
        int    $linkedOrdersCount,
        bool   $isStale,
        int    $martRowCount
    ): array {
        $noSales = $invoicesCount === 0;
        $noBridge = $invoicesCount > 0 && $linkedOrdersCount === 0;

        $salesState = $noSales ? [
            'is_empty'    => true,
            'reason_code' => $isStale ? 'sync_stale' : 'no_authorized_nfs',
            'title'       => $isStale ? 'Dados possivelmente desatualizados' : 'Produto sem vendas no periodo',
            'message'     => $isStale
                ? 'A sincronizacao fiscal esta desatualizada (>72h). Pode haver NFs nao sincronizadas.'
                : 'Nenhuma NF autorizada com este produto foi encontrada por data_competencia no periodo selecionado.',
            'action'      => $isStale
                ? 'Execute sincronizacao Omie e aguarde o pipeline canonico.'
                : 'Amplie o periodo ou verifique a sincronizacao fiscal.',
        ] : ['is_empty' => false, 'reason_code' => null, 'title' => null, 'message' => null, 'action' => null];

        $ordersState = $noBridge ? [
            'is_empty'    => true,
            'reason_code' => 'bridge_empty',
            'title'       => 'Pedidos vinculados nao encontrados',
            'message'     => 'Ha NFs do produto, mas a bridge pedido → NF nao encontrou vinculos.',
            'action'      => 'Reprocesse mart_bridge_pedido_nfe_titulo.',
        ] : ['is_empty' => false, 'reason_code' => null, 'title' => null, 'message' => null, 'action' => null];

        $ltvState = $martRowCount === 0 && $invoicesCount > 0 ? [
            'is_empty'    => false,
            'reason_code' => 'ltv_partial',
            'title'       => 'LTV parcial',
            'message'     => 'O LTV foi calculado com base no historico local disponivel. O sync Omie pode nao estar completo.',
            'action'      => 'Verifique freshness do sync Omie.',
        ] : ['is_empty' => false, 'reason_code' => null, 'title' => null, 'message' => null, 'action' => null];

        return [
            'sales'         => $salesState,
            'linked_orders' => $ordersState,
            'ltv'           => $ltvState,
        ];
    }
}
