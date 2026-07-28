<?php

declare(strict_types=1);

namespace BIomie\SalesAnalytics;

use BIomie\Support;

final class SalesDatasetNormalizer
{
    public function __construct(
        private readonly SalesSupport $support,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return list<array<string,mixed>>
     */
    public function normalizeInvoices(array $invoices, bool $cancelled, array $orderFlags = []): array
    {
        $normalized = [];

        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }

            if (!$cancelled && !$this->support->isAuthorizedInvoiceFromOmie($invoice, $orderFlags)) {
                continue;
            }

            $issueDate = Support::formatDate((string) Support::getByPaths($invoice, ['ide.dEmi', 'dEmi'], '')) ?? '';
            $cancelDate = Support::formatDate((string) Support::getByPaths($invoice, ['ide.dCan', 'dCan'], '')) ?? '';
            $vendorId = trim((string) Support::getByPaths($invoice, ['pedido.nIdVendedor'], ''));
            $noteNumber = trim((string) Support::getByPaths($invoice, ['ide.nNF', 'nNF'], ''));
            $serie = trim((string) Support::getByPaths($invoice, ['ide.serie', 'serie'], ''));
            $accessKey = trim((string) Support::getByPaths($invoice, ['compl.cChaveNFe', 'cChaveNFe'], ''));
            $originOrder = trim((string) Support::getByPaths($invoice, ['pedido.cNumPedido', 'compl.nIdPedido'], ''));
            $merchandiseTotal = Support::toFloat(Support::getByPaths($invoice, ['total.ICMSTot.vProd', 'total.vProd'], 0));
            $discountTotal = Support::toFloat(Support::getByPaths($invoice, ['total.ICMSTot.vDesc', 'total.vDesc'], 0));
            $grossTotal = Support::toFloat(Support::getByPaths($invoice, ['total.ICMSTot.vNF', 'total.vNF'], 0));

            if ($grossTotal <= 0.0) {
                $grossTotal = max($merchandiseTotal - $discountTotal, 0.0);
            }

            $items = [];
            foreach ((array) ($invoice['det'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $items[] = [
                    'product_code' => (string) Support::getByPaths($item, ['prod.cProd', 'nfProdInt.nCodProd'], ''),
                    'description' => (string) Support::getByPaths($item, ['prod.xProd', 'prod.xProdOrig'], 'Produto sem descricao'),
                    'quantity' => Support::toFloat(Support::getByPaths($item, ['prod.qCom', 'prod.qTrib'], 0)),
                    'total' => Support::toFloat(Support::getByPaths($item, ['prod.vProd', 'prod.vTotItem'], 0)),
                    'discount_total' => Support::toFloat(Support::getByPaths($item, ['prod.vDesc'], 0)),
                ];
            }

            $normalized[] = [
                'codigo' => $noteNumber !== '' ? trim($noteNumber . ($serie !== '' ? '/' . $serie : '')) : ($accessKey !== '' ? $accessKey : 'NF sem numero'),
                'codigo_integracao' => $originOrder !== '' ? 'Pedido ' . $originOrder : ($accessKey !== '' ? $accessKey : ''),
                'customer_code' => (string) Support::getByPaths($invoice, ['nfDestInt.nCodCli', 'nIdCliente'], ''),
                'cliente' => (string) Support::getByPaths($invoice, ['nfDestInt.cRazao'], 'Cliente nao informado'),
                'customer_document' => (string) Support::getByPaths($invoice, ['nfDestInt.cnpj_cpf', 'cnpj_cpf'], ''),
                'cliente_documento' => (string) Support::getByPaths($invoice, ['nfDestInt.cnpj_cpf', 'cnpj_cpf'], ''),
                'vendor_id' => $vendorId,
                'vendor_name' => '',
                'issue_date' => $issueDate,
                'billing_date' => $issueDate,
                'stage' => $cancelled ? 'NF CANCELADA' : 'NF AUTORIZADA',
                'status_group' => $cancelled ? 'cancelado' : 'faturado',
                'status_raw' => $cancelled ? 'C' : 'N',
                'status_date' => $cancelled ? ($cancelDate !== '' ? $cancelDate : $issueDate) : $issueDate,
                'days_in_stage' => 0,
                'is_backlog' => false,
                'is_converted' => !$cancelled,
                'is_lost' => $cancelled,
                'items_qty' => count($items),
                'gross_total' => $grossTotal,
                'merchandise_total' => $merchandiseTotal,
                'discount_total' => $discountTotal,
                'total' => $grossTotal,
                'items' => $items,
                'source_entity' => 'fiscal_invoice',
                'document_key' => $accessKey,
                'origin_order_code' => $originOrder,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @param array<string,array<string,mixed>> $clientMap
     * @return list<array<string,mixed>>
     */
    public function normalizeOrders(array $orders, array $clientMap = []): array
    {
        $normalized = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $items = [];
            $itemDiscountSum = 0.0;
            $itemMerchandiseSum = 0.0;
            foreach (($order['det'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemDiscount = Support::toFloat(Support::getByPaths($item, ['produto.valor_desconto', 'valor_desconto'], 0));
                $itemMerchandise = Support::toFloat(Support::getByPaths($item, ['produto.valor_mercadoria', 'valor_mercadorias', 'valor_total'], 0));
                $itemDiscountSum += $itemDiscount;
                $itemMerchandiseSum += $itemMerchandise;
                $items[] = [
                    'product_code' => (string) Support::getByPaths($item, ['produto.codigo_produto', 'produto.codigo'], ''),
                    'description' => (string) Support::getByPaths($item, ['produto.descricao', 'descricao'], 'Produto sem descrição'),
                    'quantity' => Support::toFloat(Support::getByPaths($item, ['produto.quantidade', 'quantidade'], 0)),
                    'discount' => $itemDiscount,
                    'total' => Support::toFloat(Support::getByPaths($item, ['produto.valor_total', 'produto.valor_mercadoria', 'valor_total'], 0)),
                ];
            }

            $customerCode = $this->support->extractClientCode($order);
            $customerData = $clientMap[(string) $customerCode] ?? [];
            $issueDate = Support::formatDate((string) Support::getByPaths($order, [
                'cabecalho.data_previsao',
                'cabecalho.data_previsao_faturamento',
                'infoCadastro.dInc',
                'data_previsao',
            ], ''));
            $billingDate = Support::formatDate((string) Support::getByPaths($order, [
                'infoCadastro.dFat',
                'cabecalho.data_faturamento',
                'infoCadastro.data_faturamento',
                'faturamento.data_faturamento',
                'data_faturamento',
            ], ''));
            if ($billingDate === null) {
                $billingDate = Support::formatDate((string) Support::findFirstByKeys($order, ['dFat', 'data_faturamento', 'dataFaturamento'], ''));
            }
            if ($billingDate === null) {
                $billingDate = $issueDate;
            }

            $vendorId = Support::getByPaths($order, [
                'informacoes_adicionais.codVend',
                'cabecalho.codVend',
                'vendedor.codigo',
                'vendedor.codVend',
                'codVend',
                'nVendedor',
            ], null);
            if ($vendorId === null || $vendorId === '') {
                $vendorId = Support::findFirstByKeys($order, ['codVend', 'nVendedor', 'codigo_vendedor', 'codigo_vendedor_omie'], '');
            }

            $vendorName = trim((string) Support::getByPaths($order, [
                'informacoes_adicionais.nome_vendedor',
                'vendedor.nome',
                'nome_vendedor',
                'Vendedor',
            ], ''));
            if ($vendorName === '') {
                $vendorName = trim((string) Support::findFirstByKeys($order, ['Vendedor', 'nome_vendedor'], ''));
            }

            $customerName = trim((string) Support::getByPaths($customerData, ['razao_social', 'nome_fantasia'], ''));
            if ($customerName === '') {
                $customerName = trim((string) Support::getByPaths($order, [
                    'cliente.razao_social',
                    'cliente.nome_fantasia',
                    'cabecalho.nome_cliente',
                    'nome_fantasia',
                    'razao_social',
                    'Cliente (Razão Social)',
                    'Cliente (Nome Fantasia)',
                ], ''));
            }
            if ($customerName === '') {
                $customerName = trim((string) Support::findFirstByKeys($order, ['razao_social', 'nome_fantasia', 'Cliente (Razão Social)', 'Cliente (Nome Fantasia)'], ''));
            }
            if ($customerName === '') {
                $customerName = 'Cliente não informado';
            }

            $customerDocument = trim((string) Support::getByPaths($customerData, ['cnpj_cpf'], ''));
            if ($customerDocument === '') {
                $customerDocument = trim((string) Support::getByPaths($order, [
                    'cliente.cnpj_cpf',
                    'cliente.cpf_cnpj',
                    'cnpj_cpf',
                    'CNPJ/CPF',
                ], ''));
            }
            if ($customerDocument === '') {
                $customerDocument = trim((string) Support::findFirstByKeys($order, ['cnpj_cpf', 'cpf_cnpj', 'CNPJ/CPF'], ''));
            }

            $grossTotal = Support::toFloat(Support::getByPaths($order, ['total_pedido.valor_total_pedido', 'valor_total_pedido'], 0));
            $merchandiseTotal = Support::toFloat(Support::getByPaths($order, ['total_pedido.valor_mercadorias', 'valor_mercadorias'], 0));
            if ($merchandiseTotal <= 0 && $itemMerchandiseSum > 0) {
                $merchandiseTotal = $itemMerchandiseSum;
            }
            $discountTotal = Support::toFloat(Support::getByPaths($order, ['total_pedido.valor_descontos', 'valor_descontos', 'valor_desconto_pedido'], 0));
            if ($discountTotal <= 0 && $itemDiscountSum > 0) {
                $discountTotal = $itemDiscountSum;
            }
            if ($grossTotal <= 0) {
                $grossTotal = max($merchandiseTotal - $discountTotal, 0.0);
            }

            $etapa = (string) Support::getByPaths($order, ['cabecalho.etapa'], '');
            $statusPedido = (string) Support::getByPaths($order, ['status_pedido', 'situacao'], '');
            $stage = $etapa !== '' ? $etapa : $statusPedido;
            $statusGroup = $this->support->classifyOrderStatusGroup($order, $stage, $statusPedido);

            $statusDate = $billingDate ?? $issueDate ?? '';
            if ($statusGroup === 'cancelado') {
                $cancelDate = Support::formatDate((string) Support::getByPaths($order, [
                    'infoCadastro.dCan', 'data_cancelamento',
                ], ''));
                if ($cancelDate !== null) {
                    $statusDate = $cancelDate;
                }
            }

            $daysInStage = 0;
            if ($statusDate !== '' && $statusGroup !== 'faturado') {
                $diff = (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($statusDate));
                $daysInStage = (int) $diff->days;
            }

            $normalized[] = [
                'codigo' => (string) Support::getByPaths($order, ['cabecalho.numero_pedido', 'codigo_pedido', 'codigo', 'cabecalho.codigo_pedido_integracao'], ''),
                'codigo_integracao' => (string) Support::getByPaths($order, ['cabecalho.codigo_pedido_integracao', 'codigo_pedido_integracao'], ''),
                'customer_code' => (string) $customerCode,
                'cliente' => $customerName,
                'customer_document' => $customerDocument,
                'vendor_id' => (int) $vendorId,
                'vendor_name' => $vendorName,
                'issue_date' => $issueDate ?? '',
                'billing_date' => $billingDate ?? '',
                'stage' => $stage !== '' ? $stage : 'FATURADO',
                'status_group' => $statusGroup,
                'status_raw' => $stage,
                'status_date' => $statusDate,
                'days_in_stage' => $daysInStage,
                'is_backlog' => $statusGroup === 'autorizado' && $daysInStage > 0,
                'is_converted' => $statusGroup === 'faturado',
                'is_lost' => $statusGroup === 'cancelado',
                'items_qty' => (int) Support::getByPaths($order, ['cabecalho.quantidade_itens', 'quantidade_itens'], count($items)),
                'gross_total' => $grossTotal,
                'merchandise_total' => $merchandiseTotal,
                'discount_total' => $discountTotal,
                'total' => $grossTotal,
                'items' => $items,
                'source_entity' => 'sales_order',
            ];
        }

        return $normalized;
    }
}
