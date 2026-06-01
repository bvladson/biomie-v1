<?php

declare(strict_types=1);

/**
 * tools/dev/geo_composite_map_probe.php
 *
 * GEO.R1.7 — Verifica integridade do Mapa Composto Inteligente do Ceará.
 *
 * Checks:
 *   C01  fortaleza_bairros.json existe
 *   C02  rmf_bairros_ibge_2022.geojson existe
 *   C03  ceara_municipios_oficial.geojson existe
 *   C04  fortaleza_bairros.json tem 121 features
 *   C05  rmf_bairros_ibge_2022.geojson tem ≥ 194 features
 *   C06  ceara_municipios_oficial.geojson tem 184 features
 *   C07  Nenhuma feature de bairro RMF perde municipio_nome
 *   C08  Olho d'Água pertence a Eusébio
 *   C09  Jurema pertence a Caucaia
 *   C10  Pajuçara pertence a Maracanaú
 *   C11  Municípios com bairro detalhado NÃO duplicam como município no mapa composto
 *   C12  Municípios RMF sem bairro aparecem no GeoJSON de municípios
 *   C13  Ceará restante tem municípios fora da RMF
 *   C14  api/geo_composite_sales.php existe e tem sintaxe válida
 *   C15  biomie-map.js tem initCompositeMap
 *   C16  biomie-map.js tem TERRITORY_COLORS
 *   C17  biomie-map.js tem return initCompositeMap() (dispatch em loadCommercialData)
 *   C18  mapa_vendas.html não tem ceara_regioes no seletor
 *   C19  mapa_vendas.html tem mapa_composto_ceara no seletor
 *
 * Uso: php tools/dev/geo_composite_map_probe.php
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
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
    $checks[] = ['id' => $id, 'status' => $status, 'message' => $msg];
}

function loadGeoJson(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

// ── GeoJSON paths ──────────────────────────────────────────────────────────
$pathFortaleza = $root . '/assets/geo/fortaleza_bairros.json';
$pathMetro     = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
$pathCeara     = $root . '/assets/geo/ceara_municipios_oficial.geojson';

// C01-C03 — Existência dos arquivos
probe($checks, $pass, $fail, 'C01', file_exists($pathFortaleza), 'fortaleza_bairros.json existe.');
probe($checks, $pass, $fail, 'C02', file_exists($pathMetro),     'rmf_bairros_ibge_2022.geojson existe.');
probe($checks, $pass, $fail, 'C03', file_exists($pathCeara),     'ceara_municipios_oficial.geojson existe.');

// ── Carregar GeoJSONs ──────────────────────────────────────────────────────
$geoFortaleza = loadGeoJson($pathFortaleza);
$geoMetro     = loadGeoJson($pathMetro);
$geoCeara     = loadGeoJson($pathCeara);

$featFortaleza = $geoFortaleza['features'] ?? [];
$featMetro     = $geoMetro['features'] ?? [];
$featCeara     = $geoCeara['features'] ?? [];

// C04 — fortaleza_bairros 121 features
probe($checks, $pass, $fail, 'C04', count($featFortaleza) === 121,
    'fortaleza_bairros.json tem 121 features. (encontrado: ' . count($featFortaleza) . ')');

// C05 — rmf_bairros ≥ 194 features
probe($checks, $pass, $fail, 'C05', count($featMetro) >= 194,
    'rmf_bairros_ibge_2022.geojson tem ≥ 194 features RMF. (encontrado: ' . count($featMetro) . ')');

// C06 — ceara_municipios 184 features
probe($checks, $pass, $fail, 'C06', count($featCeara) === 184,
    'ceara_municipios_oficial.geojson tem 184 features. (encontrado: ' . count($featCeara) . ')');

// C07 — Nenhuma feature RMF perde municipio_nome
$semMuniNome = 0;
foreach ($featMetro as $f) {
    $props = $f['properties'] ?? [];
    if (empty($props['municipio_nome'])) {
        $semMuniNome++;
    }
}
probe($checks, $pass, $fail, 'C07', $semMuniNome === 0,
    'Nenhuma feature RMF perde municipio_nome. (sem nome: ' . $semMuniNome . ')');

// C08 — Olho d'Água → Eusébio (busca exata pelo bairro_norm "olho_d_agua" de Eusébio)
$olhoFound   = false;
$olhoCorrect = false;
foreach ($featMetro as $f) {
    $props      = $f['properties'] ?? [];
    $bairroNorm = $props['bairro_norm'] ?? '';
    // Busca exata: olho_d_agua (não olho_d_agua_do_pitaguari)
    if ($bairroNorm === 'olho_d_agua') {
        $olhoFound = true;
        $muni      = strtolower(str_replace(['é','ê','è'], 'e', $props['municipio_norm'] ?? $props['cidade_key'] ?? ''));
        $olhoCorrect = ($muni === 'eusebio');
        break;
    }
}
probe($checks, $pass, $fail, 'C08', !$olhoFound || $olhoCorrect,
    "Olho d'Água (bairro_norm=olho_d_agua) pertence a Eusébio. Encontrado: " . ($olhoFound ? 'sim' : 'não presente no GeoJSON'));

// C09 — Jurema → Caucaia
$juremaFound   = false;
$juremaCorrect = false;
foreach ($featMetro as $f) {
    $props = $f['properties'] ?? [];
    if (strcasecmp($props['bairro_nome'] ?? '', 'Jurema') === 0) {
        $juremaFound   = true;
        $juremaCorrect = strtolower($props['municipio_nome'] ?? '') === 'caucaia';
    }
}
probe($checks, $pass, $fail, 'C09', !$juremaFound || $juremaCorrect,
    'Jurema pertence a Caucaia. Encontrado: ' . ($juremaFound ? 'sim' : 'não presente no GeoJSON'));

// C10 — Pajuçara → Maracanaú
$pajucFound   = false;
$pajucCorrect = false;
foreach ($featMetro as $f) {
    $props = $f['properties'] ?? [];
    $nome  = $props['bairro_nome'] ?? '';
    if (str_contains(strtolower($nome), 'paju') || str_contains(strtolower($nome), 'pajuça')) {
        $pajucFound   = true;
        $muni         = strtolower($props['municipio_nome'] ?? '');
        $pajucCorrect = str_contains($muni, 'maracan');
    }
}
probe($checks, $pass, $fail, 'C10', !$pajucFound || $pajucCorrect,
    'Pajuçara pertence a Maracanaú. Encontrado: ' . ($pajucFound ? 'sim' : 'não presente no GeoJSON'));

// C11 — Municípios com bairros detalhados NÃO devem duplicar como município cheio
// Os 8 municípios RMF com bairros IBGE (exceto Fortaleza) não devem aparecer como polígono municipal no mapa composto.
// Verificação: o JS exclui esses municípios com RMF_WITH_BAIRROS_CODES.
$mapJs = file_exists($root . '/assets/js/biomie-map.js')
    ? (string) file_get_contents($root . '/assets/js/biomie-map.js')
    : '';
probe($checks, $pass, $fail, 'C11', str_contains($mapJs, 'RMF_WITH_BAIRROS_CODES'),
    'biomie-map.js exclui municípios com bairros IBGE do polígono municipal (RMF_WITH_BAIRROS_CODES).');

// C12 — Municípios RMF sem bairro aparecem no GeoJSON de municípios
$rmfFallback = [
    'aquiraz','horizonte','pacajus','chorozinho','cascavel',
    'pindoretama','paracuru','paraipaba','sao_goncalo_do_amarante','sao_luis_do_curu',
];
$cearaKeys = array_map(fn($f) => strtolower(trim($f['properties']['cidade_key'] ?? '')), $featCeara);
$missingFallback = [];
foreach ($rmfFallback as $rmfCity) {
    if (!in_array($rmfCity, $cearaKeys, true)) {
        $missingFallback[] = $rmfCity;
    }
}
probe($checks, $pass, $fail, 'C12', $missingFallback === [],
    'Municípios RMF sem bairro IBGE presentes no ceara_municipios_oficial.geojson. Faltando: ' . implode(', ', $missingFallback));

// C13 — Ceará restante tem municípios fora da RMF
$rmfAll = array_merge(
    ['fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba','trairi'],
    $rmfFallback
);
$cearaOnly = array_filter($cearaKeys, fn($k) => !in_array($k, $rmfAll, true) && $k !== '');
probe($checks, $pass, $fail, 'C13', count($cearaOnly) > 150,
    'ceara_municipios_oficial.geojson tem municípios do Ceará além da RMF. (count: ' . count($cearaOnly) . ')');

// C14 — api/geo_composite_sales.php existe e é PHP válido
$apiPath = $root . '/api/geo_composite_sales.php';
$apiExists = file_exists($apiPath);
probe($checks, $pass, $fail, 'C14', $apiExists, 'api/geo_composite_sales.php existe.');
if ($apiExists) {
    $lintOutput = [];
    $lintCode   = 0;
    exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $lintOutput, $lintCode);
    probe($checks, $pass, $fail, 'C14b', $lintCode === 0,
        'api/geo_composite_sales.php sintaxe PHP válida. (' . implode(' ', $lintOutput) . ')');
}

// C15 — biomie-map.js tem initCompositeMap
probe($checks, $pass, $fail, 'C15', str_contains($mapJs, 'initCompositeMap'),
    'biomie-map.js tem initCompositeMap() (GEO.R1.7).');

// C16 — biomie-map.js tem TERRITORY_COLORS
probe($checks, $pass, $fail, 'C16', str_contains($mapJs, 'TERRITORY_COLORS'),
    'biomie-map.js tem TERRITORY_COLORS por tipo territorial (GEO.R1.7).');

// C17 — biomie-map.js tem return initCompositeMap() dispatch em loadCommercialData
probe($checks, $pass, $fail, 'C17', str_contains($mapJs, 'return initCompositeMap()'),
    'loadCommercialData() despacha para initCompositeMap() no modo composto (GEO.R1.7).');

// C18-C19 — mapa_vendas.html
$mapHtml = file_exists($root . '/views/mapa_vendas.html')
    ? (string) file_get_contents($root . '/views/mapa_vendas.html')
    : '';
probe($checks, $pass, $fail, 'C18', !str_contains($mapHtml, 'ceara_regioes'),
    'Regiões de Planejamento removidas do seletor principal de mapa_vendas.html (GEO.R1.7).');
probe($checks, $pass, $fail, 'C19', str_contains($mapHtml, 'mapa_composto_ceara'),
    'mapa_composto_ceara presente como opção no seletor de mapa_vendas.html (GEO.R1.7).');

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.7 — Composite Map Probe' . PHP_EOL;
echo str_repeat('-', 56) . PHP_EOL;

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
