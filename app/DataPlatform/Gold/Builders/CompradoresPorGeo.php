<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

/**
 * SQL expression helpers shared by GeoBuyerMartBuilder and GeoAggregateBuilder.
 *
 * Both builders write to mart_compradores_por_geo. Using the same expressions
 * guarantees that whichever builder runs last, the bairro resolution is identical.
 * The alias parameters allow each caller to use its own table aliases.
 */
final class CompradoresPorGeo
{
    /**
     * COALESCE expression for bairro_norm that respects the full fallback chain:
     *   1. bairro_oficial_nome_fortaleza (official Fortaleza mesh match)
     *   2. geo_dim.bairro_norm           (normalized from Omie)
     *   3. clientes_dim.bairro_normalizado (if column exists)
     *   4. LOWER(TRIM(clientes_dim.bairro)) (raw, if column exists)
     *   5. '' (empty sentinel — client truly has no bairro)
     */
    public static function bairroNormSql(
        bool   $hasBairroOficial,
        bool   $hasBairroNormDim = false,
        bool   $hasBairroRawDim  = false,
        string $geoAlias         = 'g',
        string $dimAlias         = 'cd'
    ): string {
        $parts = [];
        if ($hasBairroOficial) {
            $parts[] = "NULLIF({$geoAlias}.bairro_oficial_nome_fortaleza, '')";
        }
        $parts[] = "NULLIF({$geoAlias}.bairro_norm, '')";
        if ($hasBairroNormDim) {
            $parts[] = "NULLIF({$dimAlias}.bairro_normalizado, '')";
        } elseif ($hasBairroRawDim) {
            $parts[] = "NULLIF(LOWER(TRIM({$dimAlias}.bairro)), '')";
        }
        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    /**
     * COALESCE expression for cidade_norm. The geo dimension is preferred, but
     * clientes_dim is a valid fallback because Omie enrichment can populate it
     * before the geo skeleton/normalization stage catches up.
     */
    public static function cidadeNormSql(
        bool   $hasCidadeNormDim = false,
        bool   $hasCidadeRawDim  = false,
        string $geoAlias         = 'g',
        string $dimAlias         = 'cd'
    ): string {
        $parts = ["NULLIF({$geoAlias}.cidade_norm, '')"];
        if ($hasCidadeNormDim) {
            $parts[] = "NULLIF({$dimAlias}.cidade_normalizada, '')";
        } elseif ($hasCidadeRawDim) {
            $parts[] = "NULLIF({$dimAlias}.cidade, '')";
        }
        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    public static function ufSql(
        bool   $hasUfDim = false,
        string $geoAlias = 'g',
        string $dimAlias = 'cd',
        string $fallback = ''
    ): string {
        $parts = ["NULLIF({$geoAlias}.uf, '')"];
        if ($hasUfDim) {
            $parts[] = "NULLIF({$dimAlias}.uf, '')";
        }
        $parts[] = "'" . str_replace("'", "''", $fallback) . "'";
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * CASE expression for geo_quality:
     *   alto      — official Fortaleza mesh match (bairro_oficial_nome_fortaleza set)
     *   medio     — bairro_norm set but no official match
     *   baixo     — only cidade_norm (no bairro data)
     *   sem_dados — no geo data at all
     */
    public static function geoQualitySql(
        bool $hasBairroOficial,
        string $geoAlias = 'g',
        ?string $bairroExpr = null,
        ?string $cidadeExpr = null
    ): string
    {
        $bairroCheck = $bairroExpr !== null
            ? "TRIM(COALESCE(({$bairroExpr}), '')) <> ''"
            : "{$geoAlias}.bairro_norm IS NOT NULL AND TRIM({$geoAlias}.bairro_norm) <> ''";
        $cidadeCheck = $cidadeExpr !== null
            ? "TRIM(COALESCE(({$cidadeExpr}), '')) <> ''"
            : "{$geoAlias}.cidade_norm IS NOT NULL AND TRIM({$geoAlias}.cidade_norm) <> ''";

        if ($hasBairroOficial) {
            return "CASE
                WHEN {$geoAlias}.bairro_oficial_nome_fortaleza IS NOT NULL
                     AND TRIM({$geoAlias}.bairro_oficial_nome_fortaleza) <> '' THEN 'alto'
                WHEN {$bairroCheck} THEN 'medio'
                WHEN {$cidadeCheck} THEN 'baixo'
                ELSE 'sem_dados'
            END";
        }
        return "CASE
            WHEN {$bairroCheck} THEN 'medio'
            WHEN {$cidadeCheck} THEN 'baixo'
            ELSE 'sem_dados'
        END";
    }

    /**
     * CASE expression for geo_scope (coarse — RMF refinement requires PHP-side lookup):
     *   bairro_fortaleza — cidade_norm = 'fortaleza'
     *   municipio_ce     — uf = 'ce' but not Fortaleza
     *   uf_brasil        — uf exists but not CE
     *   sem_dados        — no geo info
     */
    public static function geoScopeSql(string $geoAlias = 'g', ?string $cidadeExpr = null, ?string $ufExpr = null): string
    {
        $citySql = $cidadeExpr !== null
            ? "LOWER(TRIM(COALESCE(({$cidadeExpr}), '')))"
            : "LOWER(TRIM(COALESCE({$geoAlias}.cidade_norm, '')))";
        $ufSql = $ufExpr !== null
            ? "LOWER(TRIM(COALESCE(({$ufExpr}), '')))"
            : "LOWER(TRIM(COALESCE({$geoAlias}.uf, '')))";
        $hasUfSql = $ufExpr !== null
            ? "TRIM(COALESCE(({$ufExpr}), '')) <> ''"
            : "{$geoAlias}.uf IS NOT NULL AND TRIM({$geoAlias}.uf) <> ''";

        return "CASE
            WHEN {$citySql} = 'fortaleza' THEN 'bairro_fortaleza'
            WHEN {$ufSql} = 'ce' THEN 'municipio_ce'
            WHEN {$hasUfSql} THEN 'uf_brasil'
            ELSE 'sem_dados'
        END";
    }

    /**
     * ON DUPLICATE KEY UPDATE clause for geo_quality.
     * Preserves the best quality already stored; only upgrades, never downgrades.
     */
    public static function geoQualityUpsertSql(): string
    {
        return "CASE
            WHEN VALUES(geo_quality) = 'alto' THEN 'alto'
            WHEN geo_quality = 'alto' THEN 'alto'
            WHEN VALUES(geo_quality) = 'medio' THEN 'medio'
            WHEN geo_quality = 'medio' THEN 'medio'
            WHEN VALUES(geo_quality) = 'baixo' THEN 'baixo'
            ELSE COALESCE(geo_quality, VALUES(geo_quality), 'sem_dados')
        END";
    }

    /**
     * ON DUPLICATE KEY UPDATE clause for geo_scope.
     * Keeps a non-empty scope; only replaces 'sem_dados'.
     */
    public static function geoScopeUpsertSql(): string
    {
        return "CASE
            WHEN VALUES(geo_scope) IS NOT NULL AND VALUES(geo_scope) <> 'sem_dados' THEN VALUES(geo_scope)
            WHEN geo_scope IS NOT NULL AND geo_scope <> 'sem_dados' THEN geo_scope
            ELSE COALESCE(VALUES(geo_scope), geo_scope, 'sem_dados')
        END";
    }
}
