<?php

declare(strict_types=1);

namespace BIomie\FiscalSync;

use BIomie\Support;

final class FiscalCanonicalMapper
{
    public function __construct(
        private readonly FiscalSupport $support,
        private readonly FiscalPartyResolver $partyResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,string> $vendorMap
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array<string,mixed>|null
     */
    public function normalizeInvoiceToCanonical(
        array $raw,
        bool $cancelled,
        string $source,
        array $vendorMap,
        array $orderFlags = []
    ): ?array {
        if (!$cancelled && !$this->support->isAuthorizedInvoiceFromOmie($raw, $orderFlags)) {
            return null;
        }

        $noteId = (int) Support::getByPaths($raw, ['compl.nIdNF', 'nIdNF'], 0);
        $issueDate = Support::formatDate((string) Support::getByPaths($raw, ['ide.dEmi', 'dEmi'], ''));
        $cancelDate = Support::formatDate((string) Support::getByPaths($raw, ['ide.dCan', 'dCan'], ''));
        $number = trim((string) Support::getByPaths($raw, ['ide.nNF', 'nNF'], ''));
        $serie = trim((string) Support::getByPaths($raw, ['ide.serie', 'serie'], ''));
        $accessKey = trim((string) Support::getByPaths($raw, ['compl.cChaveNFe', 'cChaveNFe'], ''));
        $vendorId = trim((string) Support::getByPaths($raw, ['pedido.nIdVendedor'], ''));
        $vendorName = $this->partyResolver->resolveVendorName($vendorId, $raw, $vendorMap);
        $clientCode = (int) Support::getByPaths($raw, ['nfDestInt.nCodCli', 'nIdCliente'], 0);
        $orderOrigin = trim((string) Support::getByPaths($raw, ['pedido.cNumPedido', 'compl.nIdPedido'], ''));
        $orderId = (int) Support::getByPaths($raw, ['compl.nIdPedido'], 0);
        $merchandiseTotal = Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vProd', 'total.vProd'], 0));
        $discountTotal = Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vDesc', 'total.vDesc'], 0));
        $grossTotal = Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vNF', 'total.vNF'], 0));

        if ($grossTotal <= 0.0) {
            $grossTotal = max($merchandiseTotal - $discountTotal, 0.0);
        }

        $noteKey = $this->buildInvoiceKey($noteId, $accessKey, $number, $serie, $issueDate, $clientCode);
        if ($noteKey === '' || ($issueDate === null && $cancelDate === null)) {
            return null;
        }

        $items = [];
        foreach ((array) ($raw['det'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = [
                'sequencia_item' => $index + 1,
                'codigo_produto' => (string) Support::getByPaths($item, ['prod.cProd', 'nfProdInt.nCodProd'], ''),
                'descricao_produto' => (string) Support::getByPaths($item, ['prod.xProd', 'prod.xProdOrig'], 'Produto sem descricao'),
                'quantidade' => Support::toFloat(Support::getByPaths($item, ['prod.qCom', 'prod.qTrib'], 0)),
                'valor_total' => Support::toFloat(Support::getByPaths($item, ['prod.vProd', 'prod.vTotItem'], 0)),
                'valor_desconto' => Support::toFloat(Support::getByPaths($item, ['prod.vDesc'], 0)),
            ];
        }

        return [
            'nota_key' => $noteKey,
            'nota_id_omie' => $noteId > 0 ? $noteId : null,
            'chave_nfe' => $accessKey !== '' ? $accessKey : null,
            'numero_nf' => $number,
            'serie_nf' => $serie !== '' ? $serie : null,
            'pedido_id_omie' => $orderId > 0 ? $orderId : null,
            'codigo_pedido_origem' => $orderOrigin !== '' ? $orderOrigin : null,
            'codigo_vendedor_omie' => $vendorId,
            'nome_vendedor' => $vendorName,
            'codigo_cliente_omie' => $clientCode > 0 ? $clientCode : null,
            'nome_cliente' => (string) Support::getByPaths($raw, ['nfDestInt.cRazao'], 'Cliente nao informado'),
            'documento_cliente' => (string) Support::getByPaths($raw, ['nfDestInt.cnpj_cpf', 'cnpj_cpf'], ''),
            'status_fiscal' => $cancelled ? 'cancelada' : 'autorizada',
            'data_emissao' => $issueDate,
            'data_cancelamento' => $cancelDate,
            'data_competencia' => $cancelled ? ($cancelDate ?? $issueDate) : $issueDate,
            'valor_total' => $grossTotal,
            'valor_mercadorias' => $merchandiseTotal,
            'valor_descontos' => $discountTotal,
            'quantidade_itens' => count($items),
            'items' => $items,
            'fonte_sync' => $source,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array<string,mixed>> $clientMap
     * @param array<string,string> $vendorMap
     * @return array<string,mixed>|null
     */
    public function normalizePendingOrderToCanonical(
        array $raw,
        array $clientMap,
        string $source,
        array $vendorMap
    ): ?array {
        $orderId = (int) Support::getByPaths($raw, [
            'cabecalho.codigo_pedido',
            'codigo_pedido',
        ], 0);

        if ($orderId <= 0) {
            return null;
        }

        $codigoPedido = (string) Support::getByPaths($raw, [
            'cabecalho.numero_pedido',
            'codigo_pedido',
            'cabecalho.codigo_pedido_integracao',
        ], '');

        $codigoIntegracao = (string) Support::getByPaths($raw, [
            'cabecalho.codigo_pedido_integracao',
            'codigo_pedido_integracao',
        ], '');

        $vendorId = $this->partyResolver->extractVendorId($raw);
        $vendorName = $this->partyResolver->resolveVendorName($vendorId, $raw, $vendorMap);

        $clientCode = $this->partyResolver->extractClientCode($raw);
        $clientData = $clientMap[(string) $clientCode] ?? [];
        $clientName = $this->partyResolver->resolveClientName($raw, $clientData);
        $clientDoc = $this->partyResolver->resolveClientDocument($raw, $clientData);

        $etapa = (string) Support::getByPaths($raw, ['cabecalho.etapa'], '');
        $statusPedido = (string) Support::getByPaths($raw, ['status_pedido', 'situacao'], '');
        $statusComercial = $this->support->classifyStatus($etapa, $statusPedido);
        if (!in_array($statusComercial, ['autorizado', 'outro'], true)) {
            return null;
        }

        $dataEmissao = Support::formatDate((string) Support::getByPaths($raw, [
            'cabecalho.data_previsao',
            'infoCadastro.dInc',
            'data_previsao',
        ], ''));
        if ($dataEmissao === null) {
            return null;
        }

        $valorTotal = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_total_pedido', 'valor_total_pedido'], 0));
        $valorMercadorias = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_mercadorias', 'valor_mercadorias'], 0));
        $valorDescontos = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_descontos', 'valor_descontos', 'valor_desconto_pedido'], 0));

        if ($valorMercadorias <= 0 || $valorTotal <= 0) {
            foreach ((array) ($raw['det'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $valorMercadorias += Support::toFloat(Support::getByPaths($item, ['produto.valor_mercadoria', 'valor_mercadorias', 'valor_total'], 0));
                $valorDescontos += Support::toFloat(Support::getByPaths($item, ['produto.valor_desconto', 'valor_desconto'], 0));
            }
            if ($valorTotal <= 0) {
                $valorTotal = max($valorMercadorias - $valorDescontos, 0.0);
            }
        }

        $qtdItens = (int) Support::getByPaths($raw, ['cabecalho.quantidade_itens', 'quantidade_itens'], 0);
        if ($qtdItens <= 0) {
            $qtdItens = count((array) ($raw['det'] ?? []));
        }

        return [
            'pedido_id_omie' => $orderId,
            'codigo_pedido' => $codigoPedido,
            'codigo_integracao' => $codigoIntegracao !== '' ? $codigoIntegracao : null,
            'codigo_vendedor_omie' => $vendorId,
            'nome_vendedor' => $vendorName,
            'codigo_cliente_omie' => $clientCode > 0 ? $clientCode : null,
            'nome_cliente' => $clientName,
            'documento_cliente' => $clientDoc !== '' ? $clientDoc : null,
            'status_comercial' => $statusComercial,
            'etapa_omie' => $etapa !== '' ? $etapa : null,
            'status_pedido_omie' => $statusPedido !== '' ? $statusPedido : null,
            'data_emissao' => $dataEmissao,
            'data_faturamento' => null,
            'data_cancelamento' => null,
            'valor_total' => $valorTotal,
            'valor_mercadorias' => $valorMercadorias,
            'valor_descontos' => $valorDescontos,
            'quantidade_itens' => $qtdItens,
            'fonte_sync' => $source,
        ];
    }

    private function buildInvoiceKey(int $noteId, string $accessKey, string $number, string $serie, ?string $issueDate, int $clientCode): string
    {
        if ($noteId > 0) {
            return 'omie:' . $noteId;
        }

        if ($accessKey !== '') {
            return 'key:' . $accessKey;
        }

        $parts = array_filter([
            $number !== '' ? $number : null,
            $serie !== '' ? $serie : null,
            $issueDate,
            $clientCode > 0 ? (string) $clientCode : null,
        ]);

        return $parts === [] ? '' : 'doc:' . implode(':', $parts);
    }
}
