<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\DataPlatform\Silver\Readers\CanonicalEventReadRepository;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\Modules\Fiscal\Infrastructure\FiscalReadRepository;
use BIomie\Modules\Sales\Infrastructure\SalesReadRepository;
use Closure;

/**
 * Fachada temporaria para leituras canonicas.
 *
 * A implementacao foi separada por dominio, mas os metodos publicos antigos
 * permanecem aqui para preservar contratos internos durante a Fase 5.
 */
final class CanonicalReadRepository
{
    private ?SalesReadRepository $salesReadRepository = null;

    private ?FiscalReadRepository $fiscalReadRepository = null;

    private ?FinanceReadRepository $financeReadRepository = null;

    private ?CanonicalEventReadRepository $eventReadRepository = null;

    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return array<string, array{codigo_omie:string,nome:string,email:?string,comissao:mixed}>
     */
    public function getVendorMap(): array
    {
        return $this->salesReadRepository()->getVendorMap();
    }

    /**
     * @return list<array{vendor_id:string,vendor_name:string,email:?string}>
     */
    public function listCommercialVendorRoster(): array
    {
        return $this->salesReadRepository()->listCommercialVendorRoster();
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float,discount_total:float}
     */
    public function summarizeInvoicedCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->summarizeInvoicedCommercial($start, $end);
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float}
     */
    public function summarizeCancelledCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->summarizeCancelledCommercial($start, $end);
    }

    /**
     * @return array{orders_count:int,merchandise_total:float,order_total:float}
     */
    public function summarizeOrderPipelineCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->summarizeOrderPipelineCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicedByVendorCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->aggregateInvoicedByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledByVendorCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->aggregateCancelledByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateOrderPipelineByVendorCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->aggregateOrderPipelineByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoicedCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->fiscalReadRepository()->dailyInvoicedCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->fiscalReadRepository()->dailyCancelledCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicedOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->fiscalReadRepository()->listInvoicedOrdersForDisplayCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPipelineOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->salesReadRepository()->listPipelineOrdersForDisplayCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialTitlesByPeriod(string $start, string $end): array
    {
        return $this->financeReadRepository()->listFinancialTitlesByPeriod($start, $end);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getDomainEntityMap(): array
    {
        return [
            'pedido_comercial' => [
                'domain'      => 'commercial',
                'table'       => 'biomie_pedidos_canonicos',
                'natural_key' => 'codigo_pedido',
                'provider_key'=> 'pedido_id_omie',
                'note'        => 'Entidade comercial de origem; nao deve ser promovida por evidencias financeiras.',
            ],
            'evento_pedido' => [
                'domain'      => 'commercial',
                'table'       => 'biomie_eventos_canonicos',
                'natural_key' => 'entity_code + event_type + event_at_omie',
                'provider_key'=> 'omie_entity_id',
                'note'        => 'Linha do tempo comercial-fiscal oriunda de etapas do pedido.',
            ],
            'nota_fiscal' => [
                'domain'      => 'fiscal',
                'table'       => 'biomie_notas_fiscais_canonicas',
                'natural_key' => 'chave_nfe',
                'provider_key'=> 'nota_key',
                'note'        => 'Documento fiscal oficial para receita fiscal e cancelamentos.',
            ],
            'documento_xml' => [
                'domain'      => 'fiscal',
                'table'       => 'biomie_documentos_xml_canonicos',
                'natural_key' => 'xml_key',
                'provider_key'=> 'nota_id_omie',
                'note'        => 'Artefato documental fiscal separado da nota, inclusive quando o XML ainda nao foi coletado.',
            ],
            'titulo_financeiro' => [
                'domain'      => 'finance',
                'table'       => 'biomie_titulos_financeiros_canonicos',
                'natural_key' => 'titulo_key',
                'provider_key'=> 'titulo_id_omie',
                'note'        => 'Representa contas a receber e baixas sem corrigir comercial ou fiscal sem trilha de origem.',
            ],
            'snapshot_indicadores' => [
                'domain'      => 'gold',
                'table'       => 'biomie_competencia_snapshots',
                'natural_key' => 'competencia',
                'provider_key'=> null,
                'note'        => 'Camada de apresentacao consolidada; nao substitui as evidencias dos dominios base.',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCanonicalEventsByPeriod(string $start, string $end, ?string $entityName = null): array
    {
        return $this->eventReadRepository()->listByPeriod($start, $end, $entityName);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrders(string $start, string $end, ?string $status = null): array
    {
        return $this->listOrdersCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersCommercial(string $start, string $end, ?string $status = null): array
    {
        return $this->salesReadRepository()->listOrdersCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrderRowsForReconciliation(string $start, string $end): array
    {
        return $this->salesReadRepository()->listOrderRowsForReconciliation($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoiceRowsForReconciliation(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listInvoiceRowsForReconciliation($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendor(string $start, string $end): array
    {
        return $this->aggregateByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendorCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->aggregateByVendorCommercial($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarize(string $start, string $end): array
    {
        return $this->summarizeCommercial($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->summarizeCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyByStatus(string $start, string $end): array
    {
        return $this->dailyByStatusCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyByStatusCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->dailyByStatusCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersForDisplay(string $start, string $end, ?string $status = null): array
    {
        return $this->listOrdersForDisplayCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersForDisplayCommercial(string $start, string $end, ?string $status = null): array
    {
        return $this->salesReadRepository()->listOrdersForDisplayCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listInvoicesCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listCancelledInvoicesCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFiscalDocumentsByIssueDate(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listFiscalDocumentsByIssueDate($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->listPendingOrdersCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listInvoicesForDisplayCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTopInvoicesForDisplayCommercial(string $start, string $end, int $limit = 8): array
    {
        return $this->fiscalReadRepository()->listTopInvoicesForDisplayCommercial($start, $end, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listCancelledInvoicesForDisplayCommercial($start, $end);
    }

    /**
     * Fase H — NFs de remessa para exibição na aba secundária de NF do Período.
     * Retorna [] pré-migration H3.
     *
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayRemittance(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->listInvoicesForDisplayRemittance($start, $end);
    }

    public function operationScopeAvailable(): bool
    {
        return $this->fiscalReadRepository()->operationScopeAvailable();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersForDisplayCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->listPendingOrdersForDisplayCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function productDocumentBreakdownCommercial(
        string $start,
        string $end,
        ?string $productCode = null,
        int $limit = 200,
    ): array {
        return $this->salesReadRepository()->productDocumentBreakdownCommercial($start, $end, $productCode, $limit);
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->summarizeInvoicesCommercial($start, $end);
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizePendingCommercial(string $start, string $end): array
    {
        return $this->salesReadRepository()->summarizePendingCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicesByVendorCommercial(string $start, string $end): array
    {
        return $this->fiscalReadRepository()->aggregateInvoicesByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->salesReadRepository()->topProductsCommercial($start, $end, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topClientsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->salesReadRepository()->topClientsCommercial($start, $end, $limit);
    }

    private function salesReadRepository(): SalesReadRepository
    {
        return $this->salesReadRepository ??= new SalesReadRepository($this->pdoResolver);
    }

    private function fiscalReadRepository(): FiscalReadRepository
    {
        return $this->fiscalReadRepository ??= new FiscalReadRepository($this->pdoResolver);
    }

    private function financeReadRepository(): FinanceReadRepository
    {
        return $this->financeReadRepository ??= new FinanceReadRepository($this->pdoResolver);
    }

    private function eventReadRepository(): CanonicalEventReadRepository
    {
        return $this->eventReadRepository ??= new CanonicalEventReadRepository($this->pdoResolver);
    }
}
