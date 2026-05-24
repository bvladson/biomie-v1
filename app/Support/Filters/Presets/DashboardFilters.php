<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;
use BIomie\Support\FiscalValueBasis;

/**
 * Sort whitelist for the commercial dashboard (Fase Q.7).
 * Base: Base Comercial NF (valor_total − frete − outras − seguro).
 * IPI stays inside the base. Licitação excluded via BiVisibilityScope.
 */
final class DashboardFilters
{
    public static function sortWhitelist(string $alias = 'nf'): SortWhitelist
    {
        $baseSql = FiscalValueBasis::preComputedBaseSql($alias);

        return new SortWhitelist(
            map: [
                'emissao'        => "COALESCE({$alias}.data_competencia, {$alias}.data_emissao)",
                'base_comercial' => $baseSql,
                'nf'             => "CAST({$alias}.numero_nf AS UNSIGNED)",
                'cliente'        => "{$alias}.nome_cliente",
                'vendedor'       => "{$alias}.nome_vendedor",
                'ticket_medio'   => $baseSql,
                'quantidade'     => $baseSql,
                'nfs'            => $baseSql,
            ],
            default: "COALESCE({$alias}.data_competencia, {$alias}.data_emissao)",
        );
    }
}
