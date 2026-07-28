<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

/**
 * Sort whitelist and allowed values for the reconciliation search endpoint (Fase Q.9).
 *
 * Column aliases reference the outer UNION subquery produced by reconciliation_search.php:
 *   nf_emissao, pedido_id_omie, codigo_pedido, nota_key, numero_nf, serie_nf,
 *   titulo_key, titulo_num, status_titulo, data_vencimento, data_liquidacao,
 *   codigo_vendedor_omie, nome_vendedor, codigo_cliente_omie, nome_cliente,
 *   base_comercial, valor_titulo, valor_aberto, status_conciliacao, diferenca
 *
 * Base Comercial NF (FiscalValueBasis) is used for the base_comercial column.
 * Licitação excluded via BiVisibilityScope on the NF subquery.
 */
final class ReconciliationFilters
{
    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'emissao'            => 'nf_emissao',
                'pedido'             => 'codigo_pedido',
                'nf'                 => 'numero_nf',
                'titulo'             => 'titulo_num',
                'cliente'            => 'nome_cliente',
                'vendedor'           => 'nome_vendedor',
                'base_comercial'     => 'base_comercial',
                'valor_titulo'       => 'valor_titulo',
                'diferenca'          => 'diferenca',
                'status_conciliacao' => 'status_conciliacao',
                'vencimento'         => 'data_vencimento',
                'status_titulo'      => 'status_titulo',
            ],
            default: 'nf_emissao',
        );
    }

    /** @return list<string> */
    public static function allowedStatusConciliacao(): array
    {
        return ['ok', 'pedido_sem_nf', 'nf_sem_pedido', 'nf_sem_titulo', 'titulo_sem_nf', 'valor_divergente', 'titulo_baixa_confianca', 'cancelado', 'pendente_manual'];
    }

    /** @return list<string> */
    public static function allowedStatusTitulo(): array
    {
        return ['liquidado', 'recebido', 'pago', 'aberto', 'vencido'];
    }
}
