<?php

declare(strict_types=1);

namespace BIomie\Geo;

/**
 * GEO.R1 — Registro das camadas territoriais do mapa comercial BIomie.
 *
 * Regras:
 *  - fortaleza_bairros : camada original, preservada sem alteração de comportamento.
 *  - grande_fortaleza_bairros : Fortaleza + bairros de cidades adjacentes com geometria validada.
 *  - rmf_municipios    : 19 municípios da RMF por polígono municipal (sem abrir bairro).
 *  - ceara_municipios  : 184 municípios do Ceará por polígono municipal.
 *  - ceara_regioes     : 14 regiões de planejamento IPECE (camada macro, visão estratégica).
 */
final class GeoMapLayerRegistry
{
    /**
     * Definição de todas as camadas disponíveis.
     *
     * match_strategy: 'bairro_norm' | 'cidade_norm' | 'ibge_code' | 'regiao_id'
     *
     * @var array<string, array<string, mixed>>
     */
    private const LAYERS = [
        'mapa_composto_ceara' => [
            'layer_id'               => 'mapa_composto_ceara',
            'label'                  => 'Visao multiescala do Ceara',
            'description'            => 'Analise avancada que reune bairros e municipios sem sugerir comparacao direta entre escalas.',
            'geojson_path'           => '',
            'geojson_match_property' => 'join_key',
            'match_strategy'         => 'composite',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => null,
            'source'                 => 'composite_local',
            'fallback_empty_state'   => 'Territorio sem venda registrada no periodo.',
            'scope_label'            => 'territorio',
            'preserve_existing'      => false,
            'geometry_note'          => 'Camada sintetica montada no front-end.',
        ],
        'fortaleza_bairros' => [
            'layer_id'               => 'fortaleza_bairros',
            'label'                  => 'Fortaleza · Bairros',
            'description'            => 'Bairros oficiais de Fortaleza — camada original preservada.',
            'geojson_path'           => 'assets/geo/fortaleza_bairros.json',
            'geojson_match_property' => 'Nome',
            'match_strategy'         => 'bairro_norm',
            'city_scope'             => ['fortaleza'],
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => 121,
            'source'                 => 'ipplan_fortaleza',
            'fallback_empty_state'   => 'Não há dados de venda para este bairro no período selecionado.',
            'scope_label'            => 'bairro',
            'preserve_existing'      => true,
        ],
        'grande_fortaleza_bairros' => [
            'layer_id'               => 'grande_fortaleza_bairros',
            'label'                  => 'Grande Fortaleza · Bairros disponíveis',
            'description'            => 'Fortaleza + bairros oficiais de cidades adjacentes com geometria validada.',
            'geojson_path'           => 'assets/geo/rmf_bairros_ibge_2022.geojson',
            'geojson_match_property' => 'bairro_nome',
            'match_strategy'         => 'bairro_norm',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => null,
            'source'                 => 'oficial_municipal',
            'fallback_empty_state'   => 'Bairro sem venda no período. Para municípios sem geometria de bairro validada, os dados aparecem agrupados no polígono municipal.',
            'scope_label'            => 'bairro',
            'preserve_existing'      => false,
            'geometry_note'          => 'GeoJSON a ser gerado quando houver malha oficial de bairros adjacentes validada.',
        ],
        'rmf_municipios' => [
            'layer_id'               => 'rmf_municipios',
            'label'                  => 'RMF · Municípios',
            'description'            => 'Região Metropolitana de Fortaleza — 19 municípios por polígono municipal.',
            'geojson_path'           => 'assets/geo/rmf_municipios.json',
            'geojson_match_property' => 'municipio_ibge',
            'match_strategy'         => 'ibge_code',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => 19,
            'source'                 => 'ibge_rmf',
            'fallback_empty_state'   => 'Município sem venda registrada no período.',
            'scope_label'            => 'município',
            'preserve_existing'      => false,
        ],
        'metro_sobral_municipios' => [
            'layer_id'               => 'metro_sobral_municipios',
            'label'                  => 'Metropole Sobral',
            'description'            => 'Regiao Metropolitana de Sobral por municipio.',
            'geojson_path'           => 'assets/geo/metro_sobral_municipios.geojson',
            'geojson_match_property' => 'municipio_ibge',
            'match_strategy'         => 'ibge_code',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => null,
            'source'                 => 'lc_ce_168_2016',
            'fallback_empty_state'   => 'Municipio sem venda registrada no periodo.',
            'scope_label'            => 'municipio',
            'preserve_existing'      => false,
        ],
        'metro_cariri_municipios' => [
            'layer_id'               => 'metro_cariri_municipios',
            'label'                  => 'Metropole Cariri',
            'description'            => 'Regiao Metropolitana do Cariri por municipio.',
            'geojson_path'           => 'assets/geo/metro_cariri_municipios.geojson',
            'geojson_match_property' => 'municipio_ibge',
            'match_strategy'         => 'ibge_code',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => null,
            'source'                 => 'lc_ce_78_2009',
            'fallback_empty_state'   => 'Municipio sem venda registrada no periodo.',
            'scope_label'            => 'municipio',
            'preserve_existing'      => false,
        ],
        'metro_todas_municipios' => [
            'layer_id'               => 'metro_todas_municipios',
            'label'                  => 'Metropoles Sobral + Cariri',
            'description'            => 'Municipios das metropoles de Sobral e do Cariri. A Grande Fortaleza possui analise propria.',
            'geojson_path'           => 'assets/geo/metro_todas_municipios.geojson',
            'geojson_match_property' => 'municipio_ibge',
            'match_strategy'         => 'ibge_code',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => null,
            'source'                 => 'lc_ce_metropolitanas',
            'fallback_empty_state'   => 'Municipio sem venda registrada no periodo.',
            'scope_label'            => 'municipio',
            'preserve_existing'      => false,
        ],
        'ceara_municipios' => [
            'layer_id'               => 'ceara_municipios',
            'label'                  => 'Ceará · Municípios',
            'description'            => '184 municípios do Ceará — polígono municipal, sem bairros.',
            'geojson_path'           => 'assets/geo/ceara_municipios_oficial.geojson',
            'geojson_match_property' => 'municipio_ibge',
            'match_strategy'         => 'ibge_code',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => 184,
            'source'                 => 'ibge_ceara',
            'fallback_empty_state'   => 'Município sem venda registrada no período.',
            'scope_label'            => 'município',
            'preserve_existing'      => false,
            'geometry_note'          => 'ceara_mun.json atual tem apenas features de referência; importar malha IBGE completa.',
        ],
        'ceara_regioes' => [
            'layer_id'               => 'ceara_regioes',
            'label'                  => 'Ceará · Regiões IPECE',
            'description'            => '14 regiões de planejamento do IPECE — camada macro estratégica.',
            'geojson_path'           => 'assets/geo/ceara_regioes_ipece_oficial.geojson',
            'geojson_match_property' => 'regiao_id',
            'match_strategy'         => 'regiao_id',
            'city_scope'             => null,
            'requires_geometry'      => true,
            'has_geometry'           => true,
            'feature_count_expected' => 14,
            'source'                 => 'ipece_regioes',
            'fallback_empty_state'   => 'Região sem venda registrada no período.',
            'scope_label'            => 'região',
            'preserve_existing'      => false,
            'geometry_note'          => 'GeoJSON a ser importado do IPECE (arquivo georreferenciado de regiões de planejamento).',
        ],
    ];

    /** @return array<string, mixed>|null */
    public static function get(string $layerId): ?array
    {
        return self::LAYERS[$layerId] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::LAYERS;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::LAYERS);
    }

    public static function isValid(string $layerId): bool
    {
        return isset(self::LAYERS[$layerId]);
    }

    /** @return array<string, mixed> */
    public static function getOrDefault(string $layerId): array
    {
        return self::LAYERS[$layerId] ?? self::LAYERS[self::defaultLayer()];
    }

    public static function defaultLayer(): string
    {
        return 'ceara_regioes';
    }

    /** Camadas com geometria confirmada disponível. */
    public static function withGeometry(): array
    {
        return array_filter(self::LAYERS, fn($l) => $l['has_geometry'] === true);
    }

    /** Camadas que operam por bairro (vs. município ou região). */
    public static function bairroLayers(): array
    {
        return array_filter(self::LAYERS, fn($l) => $l['match_strategy'] === 'bairro_norm');
    }

    /** Retorna o GeoJSON path absoluto dado o root do projeto. */
    public static function geojsonAbsPath(string $layerId, string $projectRoot): string
    {
        $layer = self::get($layerId);
        if ($layer === null) return '';
        if (($layer['geojson_path'] ?? '') === '') return '';
        return rtrim($projectRoot, '/') . '/' . $layer['geojson_path'];
    }

    /** Verifica se o arquivo GeoJSON da camada existe. */
    public static function geojsonExists(string $layerId, string $projectRoot): bool
    {
        return is_file(self::geojsonAbsPath($layerId, $projectRoot));
    }

    /**
     * Resumo das camadas para exibição no front-end.
     *
     * @return list<array{id:string,label:string,description:string,has_geometry:bool,scope_label:string}>
     */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (self::LAYERS as $id => $def) {
            $out[] = [
                'id'          => $id,
                'label'       => $def['label'],
                'description' => $def['description'],
                'has_geometry' => $def['has_geometry'],
                'scope_label' => $def['scope_label'],
            ];
        }
        return $out;
    }
}
