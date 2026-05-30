<?php

declare(strict_types=1);

/**
 * api/geo_sales_layer.php — GEO.R1
 *
 * API unificada de vendas por camada territorial.
 * Parâmetros:
 *   layer   = fortaleza_bairros|grande_fortaleza_bairros|rmf_municipios|ceara_municipios|ceara_regioes
 *   start   = YYYY-MM-DD
 *   end     = YYYY-MM-DD
 *   scope   = orc_pd|remessa|licitacao|todas
 *   vendor  = filtro vendedor (substring)
 *   metric  = receita|nfs|clientes|ticket_medio
 *   mode    = data|geojson|ranking|summary|layers
 *
 * Modos:
 *   data    : dados agregados por território (padrão)
 *   geojson : FeatureCollection com dados mesclados no GeoJSON
 *   ranking : ranking ordenado por métrica
 *   summary : KPIs gerais da camada
 *   layers  : lista de camadas disponíveis
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Geo\GeoMapLayerRegistry;
use BIomie\Geo\GeoSalesLayerBuilder;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $mode   = trim((string) ($_GET['mode']   ?? 'data'));
    $layer  = trim((string) ($_GET['layer']  ?? GeoMapLayerRegistry::defaultLayer()));
    $metric = trim((string) ($_GET['metric'] ?? 'receita'));

    // Modo layers — lista de camadas disponíveis (não precisa de filtros)
    if ($mode === 'layers') {
        Http::json([
            'ok'     => true,
            'layers' => GeoMapLayerRegistry::forFrontend(),
            'default' => GeoMapLayerRegistry::defaultLayer(),
            'meta'  => ['request_id' => RequestContext::id()],
        ]);
    }

    // Validar layer
    if (!GeoMapLayerRegistry::isValid($layer)) {
        Http::json(['ok' => false, 'error' => 'invalid_layer', 'message' => "Camada desconhecida: {$layer}. Camadas válidas: " . implode(', ', GeoMapLayerRegistry::ids())], 400);
    }

    $layerDef = GeoMapLayerRegistry::get($layer);

    // Filtros
    $f      = MapFilterSupport::fromRequest($_GET);
    $start  = $f['date_from'];
    $end    = $f['date_to'];
    $scope  = in_array((string)($_GET['scope'] ?? 'orc_pd'), ['orc_pd','remessa','licitacao','todas'], true)
        ? (string) ($_GET['scope'] ?? 'orc_pd')
        : 'orc_pd';
    $vendor = trim((string) ($_GET['vendor'] ?? ''));

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);

    if (!\BIomie\Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json(['ok' => false, 'error' => 'mart_missing', 'message' => 'mart_compradores_por_geo ausente. Execute geo:materialize.'], 503);
    }

    $builder = new GeoSalesLayerBuilder($pdo);
    $filters = ['start' => $start, 'end' => $end, 'scope' => $scope, 'vendor' => $vendor];
    $layerData = $builder->buildLayer($layer, $filters);
    $summary   = $builder->buildSummary($layerData);

    // ── Modo ranking ──────────────────────────────────────────────────────────
    if ($mode === 'ranking') {
        $validMetrics = ['receita','nfs','clientes','ticket_medio'];
        $safeMetric   = in_array($metric, $validMetrics, true) ? $metric : 'receita';
        $ranking      = $builder->buildRanking($layerData, $safeMetric, 100);
        Http::json([
            'ok'      => true,
            'layer'   => $layer,
            'period'  => ['start' => $start, 'end' => $end],
            'scope'   => $scope,
            'metric'  => $safeMetric,
            'ranking' => $ranking,
            'summary' => $summary,
            'meta'    => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
        ]);
    }

    // ── Modo summary ──────────────────────────────────────────────────────────
    if ($mode === 'summary') {
        Http::json([
            'ok'      => true,
            'layer'   => $layer,
            'period'  => ['start' => $start, 'end' => $end],
            'scope'   => $scope,
            'summary' => $summary,
            'layer_def' => [
                'label'       => $layerDef['label'] ?? '',
                'description' => $layerDef['description'] ?? '',
                'has_geometry' => $layerDef['has_geometry'] ?? false,
                'scope_label' => $layerDef['scope_label'] ?? '',
            ],
            'meta'    => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
        ]);
    }

    // ── Modo geojson — mescla dados no GeoJSON ────────────────────────────────
    if ($mode === 'geojson') {
        $projectRoot = dirname(__DIR__);
        $geojsonPath = GeoMapLayerRegistry::geojsonAbsPath($layer, $projectRoot);

        if (!is_file($geojsonPath)) {
            Http::json([
                'ok'          => false,
                'error'       => 'geojson_missing',
                'layer'       => $layer,
                'geojson_path' => $layerDef['geojson_path'] ?? '',
                'message'     => 'Arquivo GeoJSON da camada não encontrado. ' . ($layerDef['geometry_note'] ?? 'Importar arquivo oficial.'),
                'empty_state' => $layerDef['fallback_empty_state'] ?? 'Geometria não disponível para esta camada.',
                'summary'     => $summary,
                'ranking'     => $builder->buildRanking($layerData, 'receita', 20),
                'meta'        => ['request_id' => RequestContext::id()],
            ], 200); // 200 para não quebrar o front
        }

        $raw = @file_get_contents($geojsonPath);
        if ($raw === false) {
            Http::json(['ok' => false, 'error' => 'geojson_read_error'], 500);
        }

        /** @var array<string, mixed>|null $geojson */
        $geojson = json_decode($raw, true);
        if (!is_array($geojson) || !isset($geojson['features'])) {
            Http::json(['ok' => false, 'error' => 'geojson_invalid'], 500);
        }

        $matchProp = (string) ($layerDef['geojson_match_property'] ?? 'Nome');

        // Mesclar dados comerciais em cada feature
        $features   = $geojson['features'] ?? [];
        $matchedCt  = 0;
        $unmatchedCt = 0;

        foreach ($features as $i => $feature) {
            $props = $feature['properties'] ?? [];
            $rawPropVal = (string) ($props[$matchProp] ?? '');

            // Gerar territory_key conforme a estratégia da camada
            $matchStrategy = (string) ($layerDef['match_strategy'] ?? 'bairro_norm');
            $tKey = match ($matchStrategy) {
                'ibge_code' => $rawPropVal,
                'regiao_id' => $rawPropVal,
                default     => \BIomie\Geo\GeoTerritoryResolver::normalizeNeighborhood($rawPropVal),
            };

            $data = $layerData[$tKey] ?? null;

            if ($data !== null) {
                $matchedCt++;
                $features[$i]['properties'] = array_merge($props, [
                    'receita'          => $data['receita'],
                    'nfs'              => $data['nfs'],
                    'clientes'         => $data['clientes'],
                    'ticket_medio'     => $data['ticket_medio'],
                    'share_pct'        => $data['share_pct'],
                    'territory_label'  => $data['territory_label'],
                    'territory_key'    => $tKey,
                    'has_sales'        => true,
                ]);
            } else {
                $unmatchedCt++;
                $features[$i]['properties'] = array_merge($props, [
                    'receita'   => 0, 'nfs' => 0, 'clientes' => 0,
                    'ticket_medio' => 0, 'share_pct' => 0.0,
                    'territory_key' => $tKey,
                    'has_sales' => false,
                ]);
            }
        }
        $geojson['features'] = $features;

        Http::json([
            'ok'       => true,
            'layer'    => $layer,
            'period'   => ['start' => $start, 'end' => $end],
            'scope'    => $scope,
            'geojson'  => $geojson,
            'summary'  => $summary,
            'ranking'  => $builder->buildRanking($layerData, $metric === 'ticket_medio' ? 'ticket_medio' : 'receita', 50),
            'diagnostics' => [
                'features_total'   => count($features),
                'features_matched' => $matchedCt,
                'features_unmatched' => $unmatchedCt,
                'territories_with_sales' => $summary['with_sales'],
                'match_property'   => $matchProp,
                'match_strategy'   => $matchStrategy,
            ],
            'meta' => [
                'request_id'  => RequestContext::id(),
                'generated_at' => date(DATE_ATOM),
                'source_table' => 'mart_compradores_por_geo',
            ],
        ]);
    }

    // ── Modo data (padrão) ────────────────────────────────────────────────────
    Http::json([
        'ok'         => true,
        'layer'      => $layer,
        'period'     => ['start' => $start, 'end' => $end],
        'scope'      => $scope,
        'summary'    => $summary,
        'territories' => array_values($layerData),
        'ranking'    => $builder->buildRanking($layerData, in_array($metric, ['receita','nfs','clientes','ticket_medio'], true) ? $metric : 'receita', 50),
        'layer_def'  => [
            'label'        => $layerDef['label']        ?? '',
            'description'  => $layerDef['description']  ?? '',
            'has_geometry' => $layerDef['has_geometry']  ?? false,
            'scope_label'  => $layerDef['scope_label']   ?? '',
            'geojson_path' => $layerDef['geojson_path']  ?? '',
            'geometry_note' => $layerDef['geometry_note'] ?? null,
        ],
        'empty_state' => empty($layerData) ? ($layerDef['fallback_empty_state'] ?? 'Sem dados para esta camada no período.') : null,
        'meta' => [
            'request_id'   => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => 'mart_compradores_por_geo',
        ],
    ]);

} catch (\Throwable $e) {
    global $logger;
    $logger?->error('geo_sales_layer error', ['msg' => $e->getMessage()]);
    Http::json(['ok' => false, 'error' => 'api_error', 'message' => 'Erro ao carregar camada geográfica.', 'request_id' => RequestContext::id()], 500);
}
