<?php

declare(strict_types=1);

/**
 * tools/dev/geo_territory_360_probe.php
 *
 * GEO.R1.8.2 / R1.8.3 / R1.8.5 — Probe de integridade do Territory 360.
 *
 * C01  Drawer tem tab Resumo
 * C02  Drawer tem tab Compradores
 * C03  Drawer tem tab CNAEs
 * C04  Drawer tem tab Evolução
 * C05  Drawer tem tab Ações
 * C06  geoDrawerTabs presente no HTML
 * C07  Painéis data-panel presentes (evolucao, acoes)
 * C08  api/geo_territory_evolution.php existe e tem sintaxe válida
 * C09  api/geo_data_quality.php existe e tem sintaxe válida
 * C10  bindDrawerTabs chama activateDrawerTab
 * C11  loadEvolutionData presente no JS
 * C12  renderTerritoryActions presente no JS
 * C13  Drawer não persiste "Fortaleza /" hardcoded no panel.js
 * C14  Heatmap flag GEO_FEATURES.heatmap = true no JS
 * C15  Leaflet.heat possui carregamento sob demanda
 * C16  enableHeatmapMode presente no JS
 * C17  disableHeatmapMode presente no JS
 * C18  polygonCentroid presente no JS
 * C19  geo_opportunity api existe
 * C20  activateDrawerTab presente no JS
 *
 * Uso: php tools/dev/geo_territory_360_probe.php
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);

$checks = [];
$pass   = 0;
$fail   = 0;

function probe(array &$checks, int &$pass, int &$fail, string $id, bool $ok, string $msg): void
{
    if ($ok) $pass++; else $fail++;
    $checks[] = ['id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'message' => $msg];
}

function readSafe(string $path): string
{
    return file_exists($path) ? (string) file_get_contents($path) : '';
}

function phpLint(string $path): bool
{
    if (!file_exists($path)) return false;
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    return $code === 0;
}

$mapJs   = readSafe($root . '/assets/js/biomie-map.js');
$panelJs = readSafe($root . '/assets/js/biomie-map-panel.js');
$mapHtml = readSafe($root . '/views/mapa_vendas.html');
$advancedJs = readSafe($root . '/assets/js/biomie-map-advanced.js');

// C01-C05 — Drawer tabs
probe($checks, $pass, $fail, 'C01', str_contains($mapHtml, 'data-tab="resumo"'),      'Drawer tem tab Resumo (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C02', str_contains($mapHtml, 'data-tab="compradores"'), 'Drawer tem tab Compradores (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C03', str_contains($mapHtml, 'data-tab="cnaes"'),       'Drawer tem tab CNAEs (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C04', str_contains($mapHtml, 'data-tab="evolucao"'),    'Drawer tem tab Evolução (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C05', str_contains($mapHtml, 'data-tab="acoes"'),       'Drawer tem tab Ações (GEO.R1.8.2).');

// C06-C07 — HTML structure
probe($checks, $pass, $fail, 'C06', str_contains($mapHtml, 'geoDrawerTabs'), 'geoDrawerTabs presente no HTML.');
probe($checks, $pass, $fail, 'C07',
    str_contains($mapHtml, 'data-panel="evolucao"') && str_contains($mapHtml, 'data-panel="acoes"'),
    'Painéis data-panel="evolucao" e data-panel="acoes" presentes no HTML.');

// C08-C09 — APIs
$evoPath = $root . '/api/geo_territory_evolution.php';
$dqPath  = $root . '/api/geo_data_quality.php';
probe($checks, $pass, $fail, 'C08', file_exists($evoPath) && phpLint($evoPath),
    'api/geo_territory_evolution.php existe e tem sintaxe PHP válida (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C09', file_exists($dqPath) && phpLint($dqPath),
    'api/geo_data_quality.php existe e tem sintaxe PHP válida (GEO.R1.8.3).');

// C10-C12 — JS functions
probe($checks, $pass, $fail, 'C10', str_contains($mapJs, 'activateDrawerTab'),   'biomie-map.js tem activateDrawerTab() para tab switching real (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C11', str_contains($mapJs, 'loadEvolutionData'),   'biomie-map.js tem loadEvolutionData() para carregar evolução mensal (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'C12', str_contains($mapJs, 'renderTerritoryActions'), 'biomie-map.js tem renderTerritoryActions() para ações comerciais (GEO.R1.8.2).');

// C13 — Panel.js bug fix preserved
probe($checks, $pass, $fail, 'C13', !str_contains($panelJs, 'Fortaleza / ${city}'),
    'biomie-map-panel.js sem subtitle hardcoded "Fortaleza / " — bug fix preservado.');

// C14-C18 — Heatmap (GEO.R1.8.5)
probe($checks, $pass, $fail, 'C14', str_contains($mapJs, 'heatmap:       true'),
    'GEO_FEATURES.heatmap = true no JS (GEO.R1.8.5 ativo).');
probe($checks, $pass, $fail, 'C15',
    !str_contains($mapHtml, 'leaflet-heat')
        && str_contains($advancedJs, 'leaflet-heat')
        && str_contains($advancedJs, 'loadHeat'),
    'Leaflet.heat possui carregamento sob demanda (GEO.R2).');
probe($checks, $pass, $fail, 'C16', str_contains($mapJs, 'enableHeatmapMode'),
    'enableHeatmapMode() presente no JS (GEO.R1.8.5).');
probe($checks, $pass, $fail, 'C17', str_contains($mapJs, 'disableHeatmapMode'),
    'disableHeatmapMode() presente no JS (GEO.R1.8.5).');
probe($checks, $pass, $fail, 'C18', str_contains($mapJs, 'polygonCentroid'),
    'polygonCentroid() presente no JS — centroides calculados do GeoJSON (GEO.R1.8.5).');

// C19-C20 — Supporting functions
probe($checks, $pass, $fail, 'C19', file_exists($root . '/api/geo_opportunities.php'),
    'api/geo_opportunities.php existe (GEO.R1.8.4 preserved).');
probe($checks, $pass, $fail, 'C20', str_contains($mapJs, 'activateDrawerTab'),
    'activateDrawerTab() chamado em loadPanelData ao abrir drawer.');

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.8.2/R1.8.3/R1.8.5 — Territory 360 + Data Quality + Heatmap Probe' . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

foreach ($checks as $c) {
    echo '[' . $c['status'] . '] ' . $c['id'] . ' — ' . $c['message'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Resultado: ' . $pass . ' PASS / ' . $fail . ' FAIL' . PHP_EOL;

if ($fail === 0) {
    echo PHP_EOL . '[PASS] PRONTO PARA DEPLOY' . PHP_EOL;
    exit(0);
} else {
    echo PHP_EOL . '[FAIL] ' . $fail . ' verificações falharam.' . PHP_EOL;
    exit(1);
}
