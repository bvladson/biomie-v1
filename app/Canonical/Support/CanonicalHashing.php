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
            'status_fiscal' => $invoice['status_fiscal'] ?? 'autorizada',
            'data_emissao' => $invoice['data_emissao'] ?? '',
            'data_cancelamento' => $invoice['data_cancelamento'] ?? '',
            'data_competencia' => $invoice['data_competencia'] ?? '',
            'valor_total' => $invoice['valor_total'] ?? 0,
            'valor_mercadorias' => $invoice['valor_mercadorias'] ?? 0,
            'valor_descontos' => $invoice['valor_descontos'] ?? 0,
            'codigo_vendedor_omie' => $invoice['codigo_vendedor_omie'] ?? '',
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
