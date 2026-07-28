<?php

declare(strict_types=1);

/**
 * tools/dev/geo_area_comparison_probe.php
 *
 * GEO.R1.8.6 — Probe de integridade para seleção de área e comparação.
 *
 * C01  GEO_FEATURES.areaSelection = true no JS
 * C02  GEO_FEATURES.comparison = true no JS
 * C03  api/geo_area_selection.php existe e tem sintaxe válida
 * C04  api/geo_compare_territories.php existe e tem sintaxe válida
 * C05  initAreaSelection() presente no JS
 * C06  processAreaSelection() presente no JS
 * C07  clearAreaResult() presente no JS
 * C08  exportAreaCsv() presente no JS
 * C09  addToComparison() presente no JS
 * C10  renderComparisonGrid() presente no JS
 * C11  exportComparisonCsv() presente no JS
 * C12  clearComparison() presente no JS
 * C13  geoComparisonBar presente no HTML
 * C14  geoComparisonOverlay presente no HTML
 * C15  geoComparisonPanel presente no HTML
 * C16  geoAddToComparisonBtn presente no HTML (drawer)
 * C17  geoAreaResultPanel presente no HTML
 * C18  geoExportAreaCsvBtn presente no HTML
 * C19  geoExportComparisonCsvBtn presente no HTML
 * C20  Leaflet.draw possui carregamento sob demanda
 * C21  Mapa composto intacto — initCompositeMap ainda presente
 * C22  Guard GEO.R1.7 preservado — return initCompositeMap() presente
 *
 * Uso: php tools/dev/geo_area_comparison_probe.php
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
$mapHtml = readSafe($root . '/views/mapa_vendas.html');
$advancedJs = readSafe($root . '/assets/js/biomie-map-advanced.js');
$apiArea = $root . '/api/geo_area_selection.php';
$apiComp = $root . '/api/geo_compare_territories.php';

// C01-C02 — Feature flags
probe($checks, $pass, $fail, 'C01', str_contains($mapJs, 'areaSelection: true'),
    'GEO_FEATURES.areaSelection = true no JS (GEO.R1.8.6 ativo).');
probe($checks, $pass, $fail, 'C02', str_contains($mapJs, 'comparison:    true'),
    'GEO_FEATURES.comparison = true no JS (GEO.R1.8.6 ativo).');

// C03-C04 — APIs
probe($checks, $pass, $fail, 'C03', file_exists($apiArea) && phpLint($apiArea),
    'api/geo_area_selection.php existe e tem sintaxe PHP válida (GEO.R1.8.6).');
probe($checks, $pass, $fail, 'C04', file_exists($apiComp) && phpLint($apiComp),
    'api/geo_compare_territories.php existe e tem sintaxe PHP válida (GEO.R1.8.6).');

// C05-C08 — Area selection JS functions
probe($checks, $pass, $fail, 'C05', str_contains($mapJs, 'initAreaSelection'),
    'initAreaSelection() presente no JS.');
probe($checks, $pass, $fail, 'C06', str_contains($mapJs, 'processAreaSelection'),
    'processAreaSelection() presente no JS.');
probe($checks, $pass, $fail, 'C07', str_contains($mapJs, 'clearAreaResult'),
    'clearAreaResult() presente no JS.');
probe($checks, $pass, $fail, 'C08', str_contains($mapJs, 'exportAreaCsv'),
    'exportAreaCsv() presente no JS.');

// C09-C12 — Comparison JS functions
probe($checks, $pass, $fail, 'C09', str_contains($mapJs, 'addToComparison'),
    'addToComparison() presente no JS.');
probe($checks, $pass, $fail, 'C10', str_contains($mapJs, 'renderComparisonGrid'),
    'renderComparisonGrid() presente no JS.');
probe($checks, $pass, $fail, 'C11', str_contains($mapJs, 'exportComparisonCsv'),
    'exportComparisonCsv() presente no JS.');
probe($checks, $pass, $fail, 'C12', str_contains($mapJs, 'clearComparison'),
    'clearComparison() presente no JS.');

// C13-C19 — HTML elements
probe($checks, $pass, $fail, 'C13', str_contains($mapHtml, 'geoComparisonBar'),
    'geoComparisonBar presente no HTML.');
probe($checks, $pass, $fail, 'C14', str_contains($mapHtml, 'geoComparisonOverlay'),
    'geoComparisonOverlay presente no HTML.');
probe($checks, $pass, $fail, 'C15', str_contains($mapHtml, 'geoComparisonPanel'),
    'geoComparisonPanel presente no HTML.');
probe($checks, $pass, $fail, 'C16', str_contains($mapHtml, 'geoAddToComparisonBtn'),
    'geoAddToComparisonBtn no HTML (botão no drawer).');
probe($checks, $pass, $fail, 'C17', str_contains($mapHtml, 'geoAreaResultPanel'),
    'geoAreaResultPanel presente no HTML.');
probe($checks, $pass, $fail, 'C18', str_contains($mapHtml, 'geoExportAreaCsvBtn'),
    'geoExportAreaCsvBtn presente no HTML.');
probe($checks, $pass, $fail, 'C19', str_contains($mapHtml, 'geoExportComparisonCsvBtn'),
    'geoExportComparisonCsvBtn presente no HTML.');

// C20 — Leaflet.draw
probe($checks, $pass, $fail, 'C20',
    !str_contains($mapHtml, 'leaflet.draw')
        && str_contains($advancedJs, 'leaflet.draw')
        && str_contains($advancedJs, 'loadDraw'),
    'Leaflet.draw possui carregamento sob demanda (GEO.R2).');

// C21-C22 — Composite map guard (GEO.R1.7 não quebrou)
probe($checks, $pass, $fail, 'C21', str_contains($mapJs, 'initCompositeMap'),
    'initCompositeMap() presente — mapa composto GEO.R1.7 preservado.');
probe($checks, $pass, $fail, 'C22', str_contains($mapJs, 'return initCompositeMap()'),
    'return initCompositeMap() presente — despacho em loadCommercialData preservado.');

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.8.6 — Area Selection + Territory Comparison Probe' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

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
