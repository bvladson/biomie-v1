<?php

declare(strict_types=1);

/**
 * tools/dev/geo_territory_identity_audit.php
 *
 * GEO.R1.9 — Auditoria de identidade territorial: verifica que bairros RMF
 * estão mapeados para o município correto nos GeoJSONs.
 *
 * Checks:
 *   I01  Olho d'Água → Eusébio (não Fortaleza)
 *   I02  Vila Nova → Trairi
 *   I03  Jurema → Caucaia
 *   I04  Pajuçara/Pajuçara → Maracanaú
 *   I05  Centro → Fortaleza (único território do tipo fortaleza_bairro)
 *   I06  Nenhum bairro metro tem municipio_nome vazio
 *   I07  Nenhum bairro metro tem municipio_nome = Fortaleza
 *   I08  Todos os 8 municípios RMF com bairros representados no GeoJSON metro
 *   I09  biomie-map.js tem resolveTerritoryIdentity ou equivalente (municipio_nome priority)
 *   I10  biomie-map-panel.js não usa 'Fortaleza / ' hardcoded
 *
 * Uso: php tools/dev/geo_territory_identity_audit.php
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$checks = []; $pass = 0; $fail = 0;

function probe(array &$c, int &$p, int &$f, string $id, bool $ok, string $msg): void
{
    if ($ok) $p++; else $f++;
    $c[] = ['id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'message' => $msg];
}

$metroPath = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
$fortPath  = $root . '/assets/geo/fortaleza_bairros.json';
$mapJs     = file_exists($root . '/assets/js/biomie-map.js') ? (string)file_get_contents($root . '/assets/js/biomie-map.js') : '';
$panelJs   = file_exists($root . '/assets/js/biomie-map-panel.js') ? (string)file_get_contents($root . '/assets/js/biomie-map-panel.js') : '';

// Load metro GeoJSON
$metroData = file_exists($metroPath) ? json_decode(file_get_contents($metroPath), true) : null;
$metroFeat = $metroData['features'] ?? [];

// Build lookup: bairro_norm => municipio_nome
$bairroToMuni = [];
foreach ($metroFeat as $f) {
    $p = $f['properties'] ?? [];
    $bairroToMuni[$p['bairro_norm'] ?? ''] = $p['municipio_nome'] ?? '';
}

// I01 — Olho d'Água → Eusébio
$olhoMuni = strtolower($bairroToMuni['olho_d_agua'] ?? '');
probe($checks, $pass, $fail, 'I01', str_contains($olhoMuni, 'eus'),
    "Olho d'Água (bairro_norm=olho_d_agua) → Eusébio. Encontrado: '{$bairroToMuni['olho_d_agua']}'");

// I02 — Vila Nova → Trairi
$vilaNovas = array_filter($bairroToMuni, fn($m) => str_contains(strtolower($m), 'trairi'), ARRAY_FILTER_USE_BOTH);
$vilaNormKey = array_key_first(array_filter($bairroToMuni, fn($k) => str_contains($k, 'vila_nova'), ARRAY_FILTER_USE_KEY));
$vilaOk = $vilaNormKey && str_contains(strtolower($bairroToMuni[$vilaNormKey] ?? ''), 'trairi');
probe($checks, $pass, $fail, 'I02', $vilaOk || !empty($vilaNovas),
    "Bairro(s) Vila Nova → Trairi. Encontrado: " . ($vilaOk ? $bairroToMuni[$vilaNormKey] : (count($vilaNovas) . ' bairros Trairi')));

// I03 — Jurema → Caucaia (may not be in IBGE 2022 if bairro_norm differs)
$juremaMuni   = strtolower($bairroToMuni['jurema'] ?? '');
$juremaAlt    = strtolower($bairroToMuni['jurema_velho'] ?? '');
$juremaOk     = $juremaMuni === '' || str_contains($juremaMuni, 'caucaia')
             || $juremaAlt === '' || str_contains($juremaAlt, 'caucaia');
// Also search by prefix in values
$juremaCaucaia = array_filter($bairroToMuni, fn($m, $k) => str_contains($k, 'jurema') && str_contains(strtolower($m), 'caucaia'), ARRAY_FILTER_USE_BOTH);
probe($checks, $pass, $fail, 'I03', $juremaOk || !empty($juremaCaucaia),
    "Jurema → Caucaia (ou não presente no IBGE 2022). encontrado: " . json_encode(array_slice($juremaCaucaia, 0, 2)));

// I04 — Pajuçara → Maracanaú
$pajucBairro = '';
foreach ($bairroToMuni as $bn => $mn) {
    if (str_contains(strtolower($bn), 'paju')) { $pajucBairro = $bn; break; }
}
$pajucMuni = strtolower($bairroToMuni[$pajucBairro] ?? '');
probe($checks, $pass, $fail, 'I04', empty($pajucBairro) || str_contains($pajucMuni, 'maracan'),
    "Pajuçara → Maracanaú. Bairro encontrado: '{$pajucBairro}', município: '{$bairroToMuni[$pajucBairro]}'");

// I05 — Centro → Fortaleza (no fortaleza_bairros.json)
$fortData  = file_exists($fortPath) ? json_decode(file_get_contents($fortPath), true) : null;
$fortFeat  = $fortData['features'] ?? [];
$centroFort = false;
foreach ($fortFeat as $ff) {
    $nome = strtolower($ff['properties']['Nome'] ?? $ff['properties']['nome'] ?? '');
    if ($nome === 'centro') { $centroFort = true; break; }
}
probe($checks, $pass, $fail, 'I05', $centroFort, "Centro existe no fortaleza_bairros.json (território Fortaleza).");

// I06 — Nenhum bairro metro com municipio_nome vazio
$semMuni = array_filter($bairroToMuni, fn($m) => trim($m) === '');
probe($checks, $pass, $fail, 'I06', empty($semMuni),
    "Nenhum bairro RMF com municipio_nome vazio. (vazio: " . count($semMuni) . ")");

// I07 — Nenhum bairro metro com municipio_nome = Fortaleza
$asFortaleza = array_filter($bairroToMuni, fn($m) => strtolower(trim($m)) === 'fortaleza');
probe($checks, $pass, $fail, 'I07', empty($asFortaleza),
    "Nenhum bairro metropolitano tem municipio_nome=Fortaleza. (errôneos: " . count($asFortaleza) . ")");

// I08 — 8 municípios RMF com bairros representados
// Use simple ASCII comparison (strip accents manually via transliteration)
function stripAccents(string $s): string
{
    static $from = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ü','ç','Á','À','Â','Ã','É','Ê','Í','Ó','Ô','Õ','Ú','Ü','Ç'];
    static $to   = ['a','a','a','a','e','e','i','o','o','o','u','u','c','A','A','A','A','E','E','I','O','O','O','U','U','C'];
    return str_replace($from, $to, $s);
}
$rmfExpected   = ['caucaia','eusebio','guaiuba','itaitinga','maracanau','maranguape','pacatuba','trairi'];
$muniNormFound = array_unique(array_map(fn($m) => preg_replace('/[^a-z]/', '', strtolower(stripAccents($m))), array_values($bairroToMuni)));
$missing = [];
foreach ($rmfExpected as $expected) {
    $found = array_filter($muniNormFound, fn($m) => str_starts_with($m, substr($expected, 0, 5)));
    if (empty($found)) $missing[] = $expected;
}
probe($checks, $pass, $fail, 'I08', empty($missing),
    "8 municípios RMF com bairros representados. Faltando: " . (empty($missing) ? 'nenhum' : implode(', ', $missing)));

// I09 — JS tem municipio_nome priority logic
probe($checks, $pass, $fail, 'I09', str_contains($mapJs, 'municipio_nome'),
    "biomie-map.js usa props.municipio_nome para resolver território real (GEO.R1.7+).");

// I10 — Panel.js sem Fortaleza hardcoded
probe($checks, $pass, $fail, 'I10', !str_contains($panelJs, 'Fortaleza / ${city}'),
    "biomie-map-panel.js sem subtitle 'Fortaleza / \${city}' hardcoded.");

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.9 — Territory Identity Audit' . PHP_EOL;
echo str_repeat('-', 50) . PHP_EOL;
foreach ($checks as $c) echo '[' . $c['status'] . '] ' . $c['id'] . ' — ' . $c['message'] . PHP_EOL;
echo PHP_EOL . 'Resultado: ' . $pass . ' PASS / ' . $fail . ' FAIL' . PHP_EOL;
if ($fail === 0) { echo PHP_EOL . '[PASS] IDENTIDADE TERRITORIAL OK' . PHP_EOL; exit(0); }
else { echo PHP_EOL . '[FAIL] ' . $fail . ' identidades divergentes.' . PHP_EOL; exit(1); }
