<?php

declare(strict_types=1);

namespace BIomie\Support\Geo;

use BIomie\Support\NeighborhoodNormalizer;

/**
 * Utilitário oficial de normalização geo.
 *
 * Distinção central:
 *  - makeNeighborhoodKey() → chave técnica estável com UNDERSCORE (bairro_key)
 *    Ex: "Prefeito José Walter" → "prefeito_jose_walter"
 *  - normalizeNeighborhood() → chave legacy com ESPAÇO (bairro_norm)
 *    Ex: "Prefeito José Walter" → "prefeito jose walter"
 *  - displayName() → nome de exibição limpo, nunca usar como chave
 *    Ex: "Prefeito José Walter" → "Prefeito José Walter"
 */
final class GeoKeyNormalizer
{
    /**
     * Chave técnica estável para bairro_key.
     * Lowercase, sem acentos, sem cedilha, espaços/hífens → underscore.
     *
     * Exemplos:
     *   "Cocó"                  → "coco"
     *   "Prefeito José Walter"  → "prefeito_jose_walter"
     *   "Antônio Bezerra"       → "antonio_bezerra"
     *   "Conjunto Ceará"        → "conjunto_ceara"
     *   "José de Alencar"       → "jose_de_alencar"
     */
    public static function makeNeighborhoodKey(?string $bairro): string
    {
        $spaced = NeighborhoodNormalizer::normalizeKey($bairro);
        return self::spacesToUnderscores($spaced);
    }

    /**
     * Chave técnica estável para cidade_key.
     * Lowercase, sem acentos, espaços/hífens → underscore.
     *
     * Exemplos:
     *   "Fortaleza"     → "fortaleza"
     *   "São Paulo"     → "sao_paulo"
     *   "Maracanaú"     → "maracanau"
     */
    public static function makeCityKey(?string $city): string
    {
        $spaced = NeighborhoodNormalizer::normalizeCity($city);
        return self::spacesToUnderscores($spaced);
    }

    /**
     * Normaliza bairro para bairro_norm legacy (espaços, sem acentos).
     * Use makeNeighborhoodKey() para novas features.
     */
    public static function normalizeNeighborhood(?string $bairro): string
    {
        return NeighborhoodNormalizer::normalizeKey($bairro);
    }

    /**
     * Normaliza cidade para cidade_norm legacy (espaços, sem acentos).
     * Use makeCityKey() para novas features.
     */
    public static function normalizeCity(?string $city): string
    {
        return NeighborhoodNormalizer::normalizeCity($city);
    }

    /**
     * Nome de exibição limpo — apenas para UI, nunca usar como chave de lookup.
     */
    public static function displayName(?string $name): string
    {
        return NeighborhoodNormalizer::displayName($name);
    }

    /**
     * Converte bairro_norm legacy (espaços) para bairro_key (underscores).
     * Útil para migrar dados existentes sem re-normalizar do raw.
     */
    public static function normToKey(string $normalizedWithSpaces): string
    {
        return self::spacesToUnderscores($normalizedWithSpaces);
    }

    /**
     * Converte bairro_key (underscores) para bairro_norm legacy (espaços).
     * Útil para retrocompatibilidade com queries antigas.
     */
    public static function keyToNorm(string $keyWithUnderscores): string
    {
        return str_replace('_', ' ', $keyWithUnderscores);
    }

    /**
     * Produz explicação passo-a-passo da normalização (para debug).
     *
     * @return array{
     *   input: string|null,
     *   display_name: string,
     *   norm_spaces: string,
     *   bairro_key: string,
     *   cidade_key: string|null,
     *   engine: string,
     *   alias_applied: array|null
     * }
     */
    public static function explain(?string $value): array
    {
        $detail = NeighborhoodNormalizer::explain($value);
        return [
            'input'         => $value,
            'display_name'  => NeighborhoodNormalizer::displayName($value),
            'norm_spaces'   => $detail['final'],
            'bairro_key'    => self::spacesToUnderscores($detail['final']),
            'cidade_key'    => null,
            'engine'        => $detail['normalizer_engine'],
            'alias_applied' => $detail['alias_applied'],
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function spacesToUnderscores(string $value): string
    {
        $underscored = str_replace([' ', '-'], '_', $value);
        $underscored = preg_replace('/_+/', '_', $underscored) ?? $underscored;
        return trim($underscored, '_');
    }
}
