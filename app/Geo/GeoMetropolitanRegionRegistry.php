<?php

declare(strict_types=1);

namespace BIomie\Geo;

/**
 * GEO.R1.5 — Registro oficial das Regiões Metropolitanas do Ceará.
 *
 * Fontes legais:
 *  - RMF    : LC Estadual 14/1973 + atualizações + IPECE
 *  - Sobral : LC Estadual 97/2010 + atualizações
 *  - Cariri : LC Estadual 78/2009 + atualizações
 */
final class GeoMetropolitanRegionRegistry
{
    private const REGIONS = [
        'metro_fortaleza' => [
            'id'          => 'metro_fortaleza',
            'nome'        => 'Região Metropolitana de Fortaleza',
            'nome_curto'  => 'Metrópole Fortaleza',
            'norm'        => 'metro_fortaleza',
            'source'      => 'LC_CE_14_1973_atualizada',
            'source_year' => 2022,
            'municipios_ibge' => [
                2304400, // Fortaleza
                2303709, // Caucaia
                2307650, // Maracanaú
                2307700, // Maranguape
                2304285, // Eusébio
                2301000, // Aquiraz
                2305233, // Horizonte
                2309607, // Pacajus
                2309706, // Pacatuba
                2306256, // Itaitinga
                2304954, // Guaiúba
                2303956, // Chorozinho
                2303501, // Cascavel
                2310852, // Pindoretama
                2310209, // Paracuru
                2310258, // Paraipaba
                2312403, // São Gonçalo do Amarante
                2312601, // São Luís do Curu
                2313500, // Trairi
            ],
        ],
        'metro_sobral' => [
            'id'          => 'metro_sobral',
            'nome'        => 'Região Metropolitana de Sobral',
            'nome_curto'  => 'Metrópole Sobral',
            'norm'        => 'metro_sobral',
            'source'      => 'LC_CE_168_2016',
            'source_year' => 2016,
            'municipios_ibge' => [
                2313005, // Sobral (sede)
                2300200, // Alcântaras
                2303105, // Cariré
                2303808, // Coreaú
                2304905, // Forquilha
                2305001, // Frecheirinha
                2304657, // Graça
                2305100, // Groaíras
                2307601, // Massapê
                2307809, // Meruoca
                2308351, // Moraújo
                2309003, // Mucambo
                2309904, // Pacujá
                2310951, // Pires Ferreira
                2311603, // Reriutaba
                2312007, // Santana do Acaraú
                2312809, // Senador Sá
                2313906, // Uruoca
            ],
        ],
        'metro_cariri' => [
            'id'          => 'metro_cariri',
            'nome'        => 'Região Metropolitana do Cariri',
            'nome_curto'  => 'Metrópole Cariri',
            'norm'        => 'metro_cariri',
            'source'      => 'LC_CE_78_2009',
            'source_year' => 2009,
            'municipios_ibge' => [
                2307304, // Juazeiro do Norte (sede)
                2304202, // Crato
                2301901, // Barbalha
                2303303, // Caririaçu
                2304301, // Farias Brito
                2306801, // Jardim
                2308104, // Missão Velha
                2309300, // Nova Olinda
                2312205, // Santana do Cariri
            ],
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::REGIONS;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        return self::REGIONS[$id] ?? null;
    }

    /** @return list<int> IBGE codes for a region */
    public static function municipiosCodes(string $regionId): array
    {
        return self::REGIONS[$regionId]['municipios_ibge'] ?? [];
    }

    /** @return list<int> all unique IBGE codes across all metropolitan regions */
    public static function allCodes(): array
    {
        $codes = [];
        foreach (self::REGIONS as $region) {
            foreach ($region['municipios_ibge'] as $code) {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }

    /** Returns the metro_region_id for a given IBGE municipality code, or null */
    public static function findRegionForMunicipio(int $ibgeCode): ?string
    {
        foreach (self::REGIONS as $regionId => $region) {
            if (in_array($ibgeCode, $region['municipios_ibge'], true)) {
                return $regionId;
            }
        }
        return null;
    }
}
