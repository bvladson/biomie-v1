<?php

declare(strict_types=1);

namespace BIomie\Canonical\Support;

final class CanonicalHashing
{
    public function __construct(
        private readonly CanonicalJsonCodec $jsonCodec,
    ) {
    }

    /**
     * @param array<string,mixed> $order
     */
    public function hashOrder(array $order): string
    {
        $relevant = [
            'status_comercial' => $order['status_comercial'] ?? '',
            'valor_total' => $order['valor_total'] ?? 0,
            'data_faturamento' => $order['data_faturamento'] ?? '',
            'data_cancelamento' => $order['data_cancelamento'] ?? '',
            'codigo_vendedor_omie' => $order['codigo_vendedor_omie'] ?? '',
            'documento_cliente' => $order['documento_cliente'] ?? '',
            'observacoes' => $order['observacoes'] ?? '',
            // Agenda de Orçamentos (Fase 2): datas reais do pedido participam do
            // hash para que a ressync repopule linhas "unchanged" e capture mudança
            // de inclusão/alteração/previsão via o caminho de upsert incremental.
            'data_inclusao_omie' => $order['data_inclusao_omie'] ?? '',
            'data_alteracao_omie' => $order['data_alteracao_omie'] ?? '',
            'data_previsao_faturamento' => $order['data_previsao_faturamento'] ?? '',
        ];

        return hash('sha256', json_encode($relevant));
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function hashInvoice(array $invoice): string
    {
        $relevant = [
            'nota_key' => $invoice['nota_key'] ?? '',
            'nota_id_omie' => $invoice['nota_id_omie'] ?? null,
            'chave_nfe' => $invoice['chave_nfe'] ?? '',
            'numero_nf' => $invoice['numero_nf'] ?? '',
            'serie_nf' => $invoice['serie_nf'] ?? '',
            'pedido_id_omie' => $invoice['pedido_id_omie'] ?? null,
            'projeto_id_omie' => $invoice['projeto_id_omie'] ?? null,
            'pedido_operacao_omie' => $invoice['pedido_operacao_omie'] ?? null,
            'business_channel' => $invoice['business_channel'] ?? '',
            'business_unit_scope' => $invoice['business_unit_scope'] ?? '',
            'is_biomie_commercial_visible' => $invoice['is_biomie_commercial_visible'] ?? null,
            'visibility_rule_source' => $invoice['visibility_rule_source'] ?? '',
            'channel_confidence' => $invoice['channel_confidence'] ?? '',
            'channel_rule_id' => $invoice['channel_rule_id'] ?? null,
            'codigo_pedido_origem' => $invoice['codigo_pedido_origem'] ?? '',
            'status_fiscal' => $invoice['status_fiscal'] ?? 'nao_autorizada',
            'data_emissao' => $invoice['data_emissao'] ?? '',
            'data_cancelamento' => $invoice['data_cancelamento'] ?? '',
            'data_competencia' => $invoice['data_competencia'] ?? '',
            'valor_total' => $invoice['valor_total'] ?? 0,
            'valor_mercadorias' => $invoice['valor_mercadorias'] ?? 0,
            'valor_descontos' => $invoice['valor_descontos'] ?? 0,
            'valor_ipi' => $invoice['valor_ipi'] ?? 0,
            'valor_frete' => $invoice['valor_frete'] ?? 0,
            'valor_outras_despesas' => $invoice['valor_outras_despesas'] ?? 0,
            'valor_seguro' => $invoice['valor_seguro'] ?? 0,
            'codigo_vendedor_omie' => $invoice['codigo_vendedor_omie'] ?? '',
            'nome_vendedor' => $invoice['nome_vendedor'] ?? '',
            'codigo_cliente_omie' => $invoice['codigo_cliente_omie'] ?? null,
            'nome_cliente' => $invoice['nome_cliente'] ?? '',
            'documento_cliente' => $invoice['documento_cliente'] ?? '',
            'observacoes' => $invoice['observacoes'] ?? '',
            'operation_scope' => $invoice['operation_scope'] ?? '',
            'operation_scope_source' => $invoice['operation_scope_source'] ?? '',
            'operation_scope_confidence' => $invoice['operation_scope_confidence'] ?? '',
            'operation_scope_rule' => $invoice['operation_scope_rule'] ?? '',
            'items' => $invoice['items'] ?? [],
        ];

        return hash('sha256', json_encode($relevant));
    }

    /**
     * @param array<string,mixed> $title
     */
    public function hashFinancialTitle(array $title): string
    {
        $relevant = [
            'titulo_key' => $title['titulo_key'] ?? '',
            'status_titulo' => $title['status_titulo'] ?? '',
            'numero_documento' => $title['numero_documento'] ?? '',
            'documento_origem_tipo' => $title['documento_origem_tipo'] ?? '',
            'documento_origem_ref' => $title['documento_origem_ref'] ?? '',
            'nota_key' => $title['nota_key'] ?? '',
            'chave_nfe' => $title['chave_nfe'] ?? '',
            'pedido_id_omie' => $title['pedido_id_omie'] ?? '',
            'data_emissao' => $title['data_emissao'] ?? '',
            'data_vencimento' => $title['data_vencimento'] ?? '',
            'data_liquidacao' => $title['data_liquidacao'] ?? '',
            'data_competencia' => $title['data_competencia'] ?? '',
            'valor_pago' => $title['valor_pago'] ?? 0,
            'valor_liquido' => $title['valor_liquido'] ?? 0,
            'valor_aberto' => $title['valor_aberto'] ?? 0,
        ];

        return hash('sha256', $this->jsonCodec->encode($relevant));
    }

    /**
     * @param array<string,mixed> $document
     */
    public function hashXmlDocument(array $document): string
    {
        $relevant = [
            'xml_key' => $document['xml_key'] ?? '',
            'nota_key' => $document['nota_key'] ?? '',
            'chave_nfe' => $document['chave_nfe'] ?? '',
            'status_documento' => $document['status_documento'] ?? '',
            'data_emissao' => $document['data_emissao'] ?? '',
            'data_cancelamento' => $document['data_cancelamento'] ?? '',
            'xml_payload' => $document['xml_payload'] ?? null,
            'metadata' => $document['metadata'] ?? [],
        ];

        return hash('sha256', $this->jsonCodec->encode($relevant));
    }

    /**
     * @param array<string,mixed> $event
     */
    public function hashCanonicalEvent(array $event, string $sourcePayloadHash): string
    {
        $relevant = [
            'entity_name' => (string) ($event['entity_name'] ?? ''),
            'omie_entity_id' => (string) ($event['omie_entity_id'] ?? ''),
            'entity_code' => (string) ($event['entity_code'] ?? ''),
            'integration_code' => (string) ($event['integration_code'] ?? ''),
            'event_type' => (string) ($event['event_type'] ?? ''),
            'event_at_omie' => (string) ($event['event_at_omie'] ?? ''),
            'event_order' => (int) ($event['event_order'] ?? 0),
            'stage_code' => (string) ($event['stage_code'] ?? ''),
            'document_key' => (string) ($event['document_key'] ?? ''),
            'document_number' => (string) ($event['document_number'] ?? ''),
            'document_series' => (string) ($event['document_series'] ?? ''),
            'source_payload_hash' => $sourcePayloadHash,
        ];

        return hash('sha256', $this->jsonCodec->encode($relevant));
    }
}
