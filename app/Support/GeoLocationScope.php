<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * SQL predicates for geographic scope.
 *
 * A missing city never belongs to a named city. Those records remain in the
 * sem_localizacao bucket until enrichment resolves the address.
 */
final class GeoLocationScope
{
    public static function cityPredicate(string $alias, string $parameter = ':city'): string
    {
        return "LOWER(TRIM(COALESCE({$alias}.cidade_norm, ''))) = {$parameter}";
    }

    public static function missingLocationPredicate(string $alias): string
    {
        return "(
            {$alias}.cidade_norm IS NULL
            OR TRIM({$alias}.cidade_norm) = ''
            OR {$alias}.bairro_norm IS NULL
            OR TRIM({$alias}.bairro_norm) = ''
            OR LOWER(TRIM({$alias}.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro')
        )";
    }

    public static function missingNeighborhoodInCityPredicate(string $alias, string $parameter = ':city'): string
    {
        return '(' . self::cityPredicate($alias, $parameter) . " AND (
            {$alias}.bairro_norm IS NULL
            OR TRIM({$alias}.bairro_norm) = ''
            OR LOWER(TRIM({$alias}.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro')
        ))";
    }
}
