<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

/**
 * Sort whitelist for NF/invoice queries.
 * Canonical KPI: Base Comercial NF (valor_total − frete − outras − seguro).
 * IPI stays inside the base. Remessas are excluded by scope filter.
 */
final class InvoiceFilters
{
    /**
     * @param string $baseComercialExpr 3-tier SQL expression from commercialBaseSql()
     */
    public static function sortWhitelist(string $baseComercialExpr = 'nf.valor_total'): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'nf'             => 'CAST(nf.numero_nf AS UNSIGNED)',
                'emissao'        => 'nf.data_emissao',
                'competencia'    => 'nf.data_competencia',
                'cliente'        => 'nf.nome_cliente',
                'vendedor'       => 'COALESCE(v.nome, nf.nome_vendedor)',
                'mercadoria'     => 'nf.valor_mercadorias',
                'desconto'       => 'nf.valor_descontos',
                'base_comercial' => $baseComercialExpr,
                'valor'          => $baseComercialExpr,  // backward-compat alias
                'pedido'         => 'nf.codigo_pedido_origem',
                'tipo'           => 'nf.operation_scope',
            ],
            default: 'nf.data_emissao',
        );
    }
}
