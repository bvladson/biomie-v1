<?php

declare(strict_types=1);

/**
 * tools/dev/geo_current_map_audit.php — GEO.R1 Fase 0
 *
 * Auditoria read-only do mapa comercial atual do BIomie.
 * Documenta: arquivos, APIs, GeoJSONs, fonte de dados, filtros, match de bairro.
 * Não altera nenhum arquivo.
 *
 * Uso:
 *   php tools/dev/geo_current_map_audit.php [--pretty] [--json]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts     = getopt('', ['pretty', 'json']);
$pretty   = array_key_exists('pretty', $opts);
$jsonMode = array_key_exists('json',   $opts);

$root = dirname(__DIR__, 2);

$findings = [];

// ── 1. Arquivos do mapa ───────────────────────────────────────────────────────

$mapFiles = [
    'mapa_vendas.php'                              => 'Controlador PHP do mapa',
    'views/mapa_vendas.html'                       => 'Template HTML do mapa',
    'assets/js/biomie-map.js'                      => 'Renderização Leaflet principal',
    'assets/js/biomie-map-panel.js'                => 'Painel lateral / drawer do bairro',
    'assets/js/geo-normalizer.js'                  => 'Aliases e normalização de bairro (JS)',
    'assets/css/biomie-map.css'                    => 'CSS do mapa',
    'api/geo_summary.php'                          => 'API principal do mapa (mart_compradores_por_geo)',
    'api/geo_neighborhood_detail.php'              => 'API de detalhe de bairro (Geo 360)',
    'api/geo_materialize.php'                      => 'API de materialização do mart geo',
    'api/sales_by_neighborhood.php'                => 'API de vendas por bairro',
    'api/top_buyers_by_geo.php'                    => 'API de top compradores por bairro',
    'api/sales_by_cnae.php'                        => 'API de vendas por CNAE geo',
    'app/Support/NeighborhoodNormalizer.php'       => 'Normalizer de bairro (PHP)',
    'app/Support/GeoMapSupport.php'                => 'Utilitários geo (empty state, normalização)',
    'app/Support/GeoTraceDiagnostics.php'          => 'Diagnósticos de trace geo',
    'app/Support/MapFilterSupport.php'             => 'Filtros do mapa (período, cidade, bairro)',
];

$fileResults = [];
foreach ($mapFiles as $rel => $desc) {
    $exists = is_file($root . '/' . $rel);
    $size   = $exists ? filesize($root . '/' . $rel) : null;
    $fileResults[] = [
        'path'   => $rel,
        'exists' => $exists,
        'size_b' => $size,
        'desc'   => $desc,
    ];
}
$findings['map_files'] = $fileResults;

// ── 2. GeoJSONs atuais ───────────────────────────────────────────────────────

$geoDir = $root . '/assets/geo';
$geoFiles = [];
if (is_dir($geoDir)) {
    foreach (glob($geoDir . '/*.json') ?: [] as $f) {
        $rel  = 'assets/geo/' . basename($f);
        $size = filesize($f);
        $content = @file_get_contents($f);
        $parsed  = $content ? json_decode($content, true) : null;
        $featureCount = is_array($parsed) ? count($parsed['features'] ?? []) : null;
        $firstProps   = $featureCount > 0 ? array_keys($parsed['features'][0]['properties'] ?? []) : [];
        $geomType     = $featureCount > 0 ? ($parsed['features'][0]['geometry']['type'] ?? 'unknown') : 'n/a';
        $geoFiles[] = [
            'path'            => $rel,
            'size_kb'         => round($size / 1024, 1),
            'feature_count'   => $featureCount,
            'geometry_type'   => $geomType,
            'property_keys'   => $firstProps,
            'valid_json'      => $parsed !== null,
        ];
    }
}
$findings['geojson_files'] = $geoFiles;

// ── 3. Tabelas do mart geo ────────────────────────────────────────────────────

$tableChecks = [];
try {
    $config = new Config();
    $pdo    = Database::isConfigured($config, 'flight_plan_db')
        ? Database::connect($config, 'flight_plan_db')
        : Database::connect($config);

    $geoTables = [
        'mart_compradores_por_geo'   => 'Mart gold — fonte principal do mapa',
        'biomie_bairros_fortaleza_dim' => 'Dimensão de bairros oficiais de Fortaleza',
        'biomie_clientes_dim'         => 'Dimensão de clientes (bairro, cidade, CNAE)',
        'biomie_geo_municipios_ce'    => 'Municípios do Ceará (GEO.R1)',
        'biomie_geo_regioes_ce'       => 'Regiões IPECE do Ceará (GEO.R1)',
        'biomie_geo_bairros_rmf'      => 'Bairros das cidades adjacentes da RMF (GEO.R1)',
        'biomie_geo_bairro_aliases'   => 'Aliases de bairros por município (GEO.R1)',
    ];

    foreach ($geoTables as $table => $desc) {
        try {
            $exists = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' LIMIT 1")->fetchColumn();
            $rows   = null;
            $cols   = [];
            if ($exists) {
                $rows = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                $colStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION");
                $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            $tableChecks[$table] = [
                'exists'  => $exists,
                'rows'    => $rows,
                'columns' => $cols,
                'desc'    => $desc,
            ];
        } catch (\Throwable) {
            $tableChecks[$table] = ['exists' => false, 'rows' => null, 'columns' => [], 'desc' => $desc, 'error' => true];
        }
    }

    // Sample mart_compradores_por_geo columns for match analysis
    if ($tableChecks['mart_compradores_por_geo']['exists'] ?? false) {
        try {
            $sample = $pdo->query(
                "SELECT DISTINCT cidade_norm, COUNT(*) ct FROM mart_compradores_por_geo
                 WHERE cidade_norm IS NOT NULL AND cidade_norm != ''
                 GROUP BY cidade_norm ORDER BY ct DESC LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);
            $findings['mart_cities_sample'] = $sample;

            $bairroSample = $pdo->query(
                "SELECT bairro_norm, SUM(faturamento) fat FROM mart_compradores_por_geo
                 WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                 GROUP BY bairro_norm ORDER BY fat DESC LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);
            $findings['mart_bairros_top10'] = $bairroSample;
        } catch (\Throwable) {}
    }
} catch (\Throwable $e) {
    $findings['db_error'] = $e->getMessage();
}

$findings['mart_tables'] = $tableChecks;

// ── 4. Parâmetros atuais da API geo_summary ───────────────────────────────────

$apiFile = $root . '/api/geo_summary.php';
if (is_file($apiFile)) {
    $src = (string) file_get_contents($apiFile);
    $findings['geo_summary_analysis'] = [
        'has_layer_param'        => str_contains($src, "\$_GET['layer']") || str_contains($src, 'layer'),
        'has_city_filter'        => str_contains($src, 'cidade_norm') || str_contains($src, 'city'),
        'hardcodes_fortaleza'    => str_contains($src, "'Fortaleza'") || str_contains($src, '"Fortaleza"'),
        'uses_mart_compradores'  => str_contains($src, 'mart_compradores_por_geo'),
        'uses_canonical_nfs'     => str_contains($src, 'biomie_notas_fiscais_canonicas'),
        'has_scope_param'        => str_contains($src, 'scope'),
        'has_bivisibility'       => str_contains($src, 'BiVisibilityScope') || str_contains($src, 'bivis'),
        'source_table'           => 'mart_compradores_por_geo',
        'note'                   => 'API atual filtra por cidade_norm = Fortaleza (default), sem suporte a layer',
    ];
}

// ── 5. biomie-map.js — configuração atual ────────────────────────────────────

$jsFile = $root . '/assets/js/biomie-map.js';
if (is_file($jsFile)) {
    $js = (string) file_get_contents($jsFile);
    preg_match('/GEOJSON_URL\s*=\s*[\'"]([^\'"]+)[\'"]/', $js, $m);
    $findings['biomie_map_js_config'] = [
        'geojson_url_hardcoded'  => $m[1] ?? 'not found',
        'has_layer_selector'     => str_contains($js, 'layerSelector') || str_contains($js, 'layer_id'),
        'has_ceara_layer'        => str_contains($js, 'ceara'),
        'has_rmf_layer'          => str_contains($js, 'rmf'),
        'uses_leaflet'           => str_contains($js, 'L.map') || str_contains($js, 'L.geoJSON'),
        'has_quantile_scale'     => str_contains($js, 'quantile') || str_contains($js, 'scale'),
        'has_normalizer'         => str_contains($js, 'normalizeNeighborhoodKey'),
        'line_count'             => substr_count($js, "\n"),
        'note'                   => 'GEOJSON_URL hardcoded; sem suporte a múltiplas camadas',
    ];
}

// ── 6. Filtros existentes na view ─────────────────────────────────────────────

$viewFile = $root . '/views/mapa_vendas.html';
if (is_file($viewFile)) {
    $html = (string) file_get_contents($viewFile);
    $findings['view_filters'] = [
        'has_date_filter'        => str_contains($html, 'dateStart') && str_contains($html, 'dateEnd'),
        'has_city_filter'        => str_contains($html, 'cityFilter'),
        'has_neighborhood_filter' => str_contains($html, 'neighborhoodFilter'),
        'has_cnae_filter'        => str_contains($html, 'cnaeFilter'),
        'has_vendor_filter'      => str_contains($html, 'vendedorFilter'),
        'has_layer_selector'     => str_contains($html, 'layerSelector') || str_contains($html, 'layer-selector'),
        'has_scope_selector'     => str_contains($html, 'scopeSelector') || str_contains($html, 'scope-selector'),
        'has_ranking_panel'      => str_contains($html, 'scopeRankingTable') || str_contains($html, 'geo-ranking'),
        'has_drawer'             => str_contains($html, 'geoNeighborhoodDrawer'),
        'has_geo360_btn'         => str_contains($html, 'geo360Btn'),
        'title_hardcoded'        => 'Vendas por bairro em Fortaleza',
        'note'                   => 'Filtros atuais: data, cidade, bairro, CNAE, vendedor. Sem seletor de camada ou escopo comercial',
    ];
}

// ── 7. Lacunas para GEO.R1 ───────────────────────────────────────────────────

$findings['gaps_for_geo_r1'] = [
    'missing_geojsons'         => ['rmf_municipios.json', 'ceara_regioes.json', 'grande_fortaleza_bairros.json'],
    'ceara_mun_incomplete'     => 'ceara_mun.json tem apenas 5 features (stub); precisaria 184 para Ceará completo',
    'geo_summary_fortaleza_only' => 'api/geo_summary.php filtra cidade_norm=Fortaleza; sem layer param',
    'no_layer_registry'        => 'GeoMapLayerRegistry.php inexistente',
    'no_territory_resolver'    => 'GeoTerritoryResolver.php inexistente',
    'no_sales_layer_builder'   => 'GeoSalesLayerBuilder.php inexistente',
    'no_geo_sales_layer_api'   => 'api/geo_sales_layer.php inexistente',
    'no_geo_territory_detail'  => 'api/geo_territory_detail.php inexistente',
    'no_geo_municipios_table'  => 'biomie_geo_municipios_ce inexistente',
    'no_scope_selector'        => 'UI sem seletor Orç-PD/Remessa/Licitação/Todas',
    'no_layer_selector'        => 'UI sem seletor de camada territorial',
    'fortaleza_intact'         => 'PRESERVAR: fortaleza_bairros.json e lógica atual de Fortaleza intactos',
];

// ── Output ────────────────────────────────────────────────────────────────────

if ($jsonMode) {
    echo json_encode(['geo_current_map_audit' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit(0);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  GEO.R1 — Auditoria do Mapa Atual (Fase 0)\n";
    echo $hr . PHP_EOL . PHP_EOL;

    echo "  ARQUIVOS DO MAPA\n";
    foreach ($fileResults as $f) {
        $badge = $f['exists'] ? "\033[32m OK \033[0m" : "\033[31mMISS\033[0m";
        $sz    = $f['size_b'] !== null ? ' (' . round($f['size_b']/1024,1) . ' KB)' : '';
        echo "  [{$badge}]  {$f['path']}{$sz}\n";
    }
    echo PHP_EOL;

    echo "  GeoJSONs\n";
    foreach ($geoFiles as $g) {
        echo "  [OK]  {$g['path']} — {$g['size_kb']} KB — {$g['feature_count']} features — {$g['geometry_type']}\n";
        if (!empty($g['property_keys'])) {
            echo "         Props: " . implode(', ', $g['property_keys']) . "\n";
        }
    }
    echo PHP_EOL;

    echo "  TABELAS\n";
    foreach ($tableChecks as $table => $t) {
        $badge = $t['exists'] ? "\033[32m OK \033[0m" : "\033[33mMISS\033[0m";
        $rows  = $t['rows'] !== null ? " — {$t['rows']} linhas" : '';
        echo "  [{$badge}]  {$table}{$rows}\n";
    }
    echo PHP_EOL;

    if (!empty($findings['mart_cities_sample'])) {
        echo "  CIDADES NO MART (top 10)\n";
        foreach ($findings['mart_cities_sample'] as $r) {
            echo "    " . ($r['cidade_norm'] ?? '?') . " — " . ($r['ct'] ?? 0) . " linhas\n";
        }
        echo PHP_EOL;
    }

    echo "  GEO_SUMMARY API\n";
    foreach ($findings['geo_summary_analysis'] ?? [] as $k => $v) {
        $disp = is_bool($v) ? ($v ? 'sim' : 'nao') : (string)$v;
        echo "    {$k}: {$disp}\n";
    }
    echo PHP_EOL;

    echo "  LACUNAS PARA GEO.R1\n";
    foreach ($findings['gaps_for_geo_r1'] as $k => $v) {
        $disp = is_array($v) ? implode(', ', $v) : (string)$v;
        echo "    [{$k}]: {$disp}\n";
    }

    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    echo "  Audit read-only concluído. Nenhum arquivo alterado.\n" . PHP_EOL;
} else {
    echo json_encode(['ok' => true, 'audit' => 'geo_current_map', 'gaps' => count($findings['gaps_for_geo_r1'])]) . PHP_EOL;
}

exit(0);
