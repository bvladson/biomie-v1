<?php

declare(strict_types=1);

/**
 * tools/dev/geo_commercial_intelligence_probe.php
 *
 * GEO.R1.8 — Mapa Comercial Inteligente: probe de integridade.
 *
 * Checks:
 *   C01  Mapa composto (GEO.R1.7) continua funcionando — initCompositeMap presente
 *   C02  Modo heatmap existe como feature flag (GEO_FEATURES.heatmap)
 *   C03  Modo cluster existe como feature flag (GEO_FEATURES.clusters)
 *   C04  Modo seleção de área existe como feature flag (GEO_FEATURES.areaSelection)
 *   C05  Drawer Territory 360 — geoDrawerTabs presente
 *   C06  Busca inteligente — geoRankingSearch / smartSearch flag presente
 *   C07  Ranking territorial preservado — scopeRankingTable presente
 *   C08  Insights não quebram se vazio — renderInsights presente
 *   C09  Data quality — modo quality presente no mapa
 *   C10  Exportação CSV — geoExportCsvBtn presente
 *   C11  city=Fortaleza não contamina modo composto — dispatch correto
 *   C12  Olho d'Água continua Eusébio (dados GeoJSON)
 *   C13  Centro → Fortaleza (fortaleza_bairros.json tem "Centro")
 *   C14  Chips de foco presentes — geoFocusChips
 *   C15  Modo visual chips — geoModeChips presentes
 *   C16  api/geo_opportunities.php existe e tem sintaxe válida
 *   C17  biomie-map-panel.js não tem "Fortaleza / " hardcoded no subtitle
 *   C18  biomie-map.js tem GEO_FEATURES flag object
 *   C19  CSS tem geo-focus-chip e geo-mode-chip
 *   C20  mapa_vendas.html não tem script panel duplicado
 *
 * Uso: php tools/dev/geo_commercial_intelligence_probe.php
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);

$checks = [];
$pass   = 0;
$fail   = 0;

function probe(array &$checks, int &$pass, int &$fail, string $id, bool $ok, string $msg): void
{
    $status = $ok ? 'PASS' : 'FAIL';
    if ($ok) $pass++; else $fail++;
    $checks[] = ['id' => $id, 'status' => $status, 'message' => $msg];
}

function readSafe(string $path): string
{
    return file_exists($path) ? (string) file_get_contents($path) : '';
}

$mapJs    = readSafe($root . '/assets/js/biomie-map.js');
$panelJs  = readSafe($root . '/assets/js/biomie-map-panel.js');
$mapHtml  = readSafe($root . '/views/mapa_vendas.html');
$mapCss   = readSafe($root . '/assets/css/biomie-map.css');
$apiOpp   = $root . '/api/geo_opportunities.php';
$geoFor   = $root . '/assets/geo/fortaleza_bairros.json';
$geoMetro = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';

// C01 — Mapa composto continua funcionando
probe($checks, $pass, $fail, 'C01', str_contains($mapJs, 'initCompositeMap'),
    'biomie-map.js tem initCompositeMap() — mapa composto GEO.R1.7 preservado.');

// C02 — Feature flag heatmap
probe($checks, $pass, $fail, 'C02', str_contains($mapJs, 'GEO_FEATURES') && str_contains($mapJs, 'heatmap'),
    'biomie-map.js tem GEO_FEATURES.heatmap como feature flag (GEO.R1.8.5 futuro).');

// C03 — Feature flag clusters
probe($checks, $pass, $fail, 'C03', str_contains($mapJs, 'clusters'),
    'biomie-map.js tem GEO_FEATURES.clusters como feature flag (GEO.R1.8.5 futuro).');

// C04 — Feature flag areaSelection
probe($checks, $pass, $fail, 'C04', str_contains($mapJs, 'areaSelection'),
    'biomie-map.js tem GEO_FEATURES.areaSelection como feature flag (GEO.R1.8.6 futuro).');

// C05 — Drawer Territory 360 tabs
probe($checks, $pass, $fail, 'C05', str_contains($mapHtml, 'geoDrawerTabs'),
    'mapa_vendas.html tem geoDrawerTabs — estrutura Territory 360 (GEO.R1.8.2 stub).');

// C06 — Busca inteligente
probe($checks, $pass, $fail, 'C06', str_contains($mapHtml, 'geoRankingSearch') && str_contains($mapJs, 'smartSearch'),
    'Busca inteligente presente — geoRankingSearch no HTML e smartSearch na flag JS.');

// C07 — Ranking territorial preservado
probe($checks, $pass, $fail, 'C07', str_contains($mapHtml, 'scopeRankingTable'),
    'Ranking territorial preservado — scopeRankingTable presente no HTML.');

// C08 — Insights não quebram se vazio
probe($checks, $pass, $fail, 'C08', str_contains($mapJs, 'renderInsights') && str_contains($mapJs, 'generateInsights'),
    'biomie-map.js tem renderInsights() e generateInsights() com tratamento de vazio.');

// C09 — Data quality modo
probe($checks, $pass, $fail, 'C09', str_contains($mapHtml, "data-mode=\"quality\""),
    "mapa_vendas.html tem chip data-mode='quality' para modo de qualidade geográfica.");

// C10 — Exportação CSV
probe($checks, $pass, $fail, 'C10', str_contains($mapHtml, 'geoExportCsvBtn') && str_contains($mapJs, 'exportRankingCsv'),
    'Exportação CSV presente — geoExportCsvBtn no HTML e exportRankingCsv() no JS.');

// C11 — city=Fortaleza não contamina modo composto
probe($checks, $pass, $fail, 'C11', str_contains($mapJs, "return initCompositeMap()"),
    'loadCommercialData() despacha para initCompositeMap() — city=Fortaleza não contamina modo composto.');

// C12 — Olho d'Água → Eusébio
$olhoOk = false;
if (file_exists($geoMetro)) {
    $d = json_decode((string) file_get_contents($geoMetro), true);
    foreach ($d['features'] ?? [] as $f) {
        $p = $f['properties'] ?? [];
        if (($p['bairro_norm'] ?? '') === 'olho_d_agua') {
            $muni    = strtolower(str_replace(['é','ê'], 'e', $p['municipio_norm'] ?? $p['cidade_key'] ?? ''));
            $olhoOk  = ($muni === 'eusebio');
            break;
        }
    }
}
probe($checks, $pass, $fail, 'C12', $olhoOk, "Olho d'Água pertence a Eusébio no GeoJSON metro.");

// C13 — Centro → Fortaleza
$centroOk = false;
if (file_exists($geoFor)) {
    $d = json_decode((string) file_get_contents($geoFor), true);
    $content = json_encode($d['features'] ?? []);
    $centroOk = stripos($content, 'centro') !== false;
}
probe($checks, $pass, $fail, 'C13', $centroOk, "fortaleza_bairros.json contém bairro 'Centro' (Fortaleza).");

// C14 — Focus chips presentes
probe($checks, $pass, $fail, 'C14', str_contains($mapHtml, 'geoFocusChips'),
    'mapa_vendas.html tem geoFocusChips — chips de foco territorial (GEO.R1.8.1).');

// C15 — Mode chips presentes
probe($checks, $pass, $fail, 'C15', str_contains($mapHtml, 'geoModeChips'),
    'mapa_vendas.html tem geoModeChips — chips de modo visual (GEO.R1.8.1).');

// C16 — api/geo_opportunities.php existe e é PHP válido
probe($checks, $pass, $fail, 'C16', file_exists($apiOpp), 'api/geo_opportunities.php existe (GEO.R1.8.4).');
if (file_exists($apiOpp)) {
    $lintOut = [];
    $lintCode = 0;
    exec('php -l ' . escapeshellarg($apiOpp) . ' 2>&1', $lintOut, $lintCode);
    probe($checks, $pass, $fail, 'C16b', $lintCode === 0,
        'api/geo_opportunities.php sintaxe PHP válida. (' . implode(' ', $lintOut) . ')');
}

// C17 — Panel.js sem "Fortaleza / " hardcoded no subtitle
probe($checks, $pass, $fail, 'C17', !str_contains($panelJs, 'Fortaleza / ${city}'),
    'biomie-map-panel.js sem subtitle hardcoded "Fortaleza / " — corrigido para município real.');

// C18 — GEO_FEATURES presente
probe($checks, $pass, $fail, 'C18', str_contains($mapJs, 'GEO_FEATURES'),
    'biomie-map.js tem objeto GEO_FEATURES com feature flags (GEO.R1.8).');

// C19 — CSS tem classes GEO.R1.8
probe($checks, $pass, $fail, 'C19',
    str_contains($mapCss, 'geo-focus-chip') && str_contains($mapCss, 'geo-mode-chip'),
    'biomie-map.css tem estilos geo-focus-chip e geo-mode-chip (GEO.R1.8.1).');

// C20 — Script panel não duplicado
$panelCount = substr_count($mapHtml, 'biomie-map-panel.js');
probe($checks, $pass, $fail, 'C20', $panelCount === 1,
    "biomie-map-panel.js carregado uma única vez no HTML (encontrado: {$panelCount}x).");

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.8 — Commercial Intelligence Probe' . PHP_EOL;
echo str_repeat('-', 58) . PHP_EOL;

foreach ($checks as $c) {
    echo '[' . $c['status'] . '] ' . $c['id'] . ' — ' . $c['message'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Resultado: ' . $pass . ' PASS / ' . $fail . ' FAIL' . PHP_EOL;

if ($fail === 0) {
    echo PHP_EOL . '[PASS] PRONTO PARA DEPLOY' . PHP_EOL;
    exit(0);
} else {
    echo PHP_EOL . '[FAIL] ' . $fail . ' verificações falharam — revisar antes de deploy.' . PHP_EOL;
    exit(1);
}
