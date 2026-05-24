<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

/**
 * Sort whitelist for the flight-plan search endpoint (Fase Q.8).
 *
 * SQL expressions reference the aliases produced by flight_plan_search.php:
 *   t        → targets subquery (vendor_id, nome_vendedor, meta)
 *   nf_agg   → NF aggregation subquery (realizado, nfs, pedidos, clientes)
 *
 * Base Comercial NF is the realizado metric (FiscalValueBasis pre-computed in subquery).
 * Licitação excluded via BiVisibilityScope in the NF subquery.
 */
final class FlightPlanFilters
{
    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'vendedor'        => 't.nome_vendedor',
                'realizado'       => 'COALESCE(nf_agg.realizado, 0)',
                'meta'            => 't.meta',
                'gap'             => 'GREATEST(t.meta - COALESCE(nf_agg.realizado, 0), 0)',
                'percentual_meta' => 'CASE WHEN t.meta > 0 THEN COALESCE(nf_agg.realizado, 0) / t.meta * 100 ELSE 0 END',
                'nfs'             => 'COALESCE(nf_agg.nfs, 0)',
                'pedidos'         => 'COALESCE(nf_agg.pedidos, 0)',
                'clientes'        => 'COALESCE(nf_agg.clientes, 0)',
                'ticket_medio'    => 'CASE WHEN COALESCE(nf_agg.nfs, 0) > 0 THEN COALESCE(nf_agg.realizado, 0) / COALESCE(nf_agg.nfs, 1) ELSE 0 END',
            ],
            default: 'CASE WHEN t.meta > 0 THEN COALESCE(nf_agg.realizado, 0) / t.meta * 100 ELSE 0 END',
        );
    }
}
