<?php

declare(strict_types=1);

/**
 * tools/dev/geo_r191_smoke_probe.php
 *
 * GEO.R1.9.1 — Smoke probe: verifica cross-links NF ↔ mapa ↔ pedidos
 * e casos de teste fixos (Aracati, Centro, Olho d'Água, Sobral).
 *
 * S01  api/geo_invoice_detail.php existe e tem sintaxe válida
 * S02  api/company_cnae_ranking.php existe e tem sintaxe válida
 * S03  renderInvoicesPanel tem "Ver em pedidos" link no JS
 * S04  renderInvoicesPanel tem expand de itens (geo-nf-expand-btn)
 * S05  renderInvoicesPanel tem geo_invoice_detail.php call
 * S06  nf-periodo-table.js tem "Ver no mapa" link (mapa_vendas.php)
 * S07  nf-periodo-table.js header tem coluna Mapa (8 colunas)
 * S08  pedidos.html tem pedidosMapLink
 * S09  pedidos.html tem sync do período no link do mapa
 * S10  index.html tem indexGeoCnaeStrip
 * S11  index.html carrega company_cnae_ranking.php no JS inline
 * S12  Caso Aracati: municipio Aracati existe no ceara_municipios.geojson
 * S13  Caso Centro: bairro Centro existe no fortaleza_bairros.json
 * S14  Caso Olho d'Água: pertence a Eusébio (não Fortaleza)
 * S15  Caso Sobral: município Sobral existe no ceara_municipios.geojson
 * S16  Mapa composto GEO.R1.7 preservado (initCompositeMap presente)
 * S17  loadTerritoryInvoices lazy-load na aba 'notas'
 * S18  loadTerritoryCnaes lazy-load na aba 'cnaes'
 * S19  Drawer tem 6 abas (resumo, compradores, notas, cnaes, evolucao, acoes)
 * S20  Sem erros de lint nas novas APIs
 *
 * Uso: php tools/dev/geo_r191_smoke_probe.php
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

$mapJs      = readSafe($root . '/assets/js/biomie-map.js');
$nfTableJs  = readSafe($root . '/assets/js/modules/commercial/nf-periodo-table.js');
$pedidosHtml= readSafe($root . '/views/pedidos.html');
$indexHtml  = readSafe($root . '/views/index.html');
$mapHtml    = readSafe($root . '/views/mapa_vendas.html');

// S01-S02 — APIs
probe($checks, $pass, $fail, 'S01', phpLint($root . '/api/geo_invoice_detail.php'),
    'api/geo_invoice_detail.php existe e tem sintaxe válida (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S02', phpLint($root . '/api/company_cnae_ranking.php'),
    'api/company_cnae_ranking.php existe e tem sintaxe válida (GEO.R1.9.1).');

// S03-S05 — renderInvoicesPanel cross-links
probe($checks, $pass, $fail, 'S03', str_contains($mapJs, 'Ver em pedidos'),
    'renderInvoicesPanel tem "Ver em pedidos" link (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S04', str_contains($mapJs, 'geo-nf-expand-btn'),
    'renderInvoicesPanel tem botão de expand de itens (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S05', str_contains($mapJs, 'geo_invoice_detail.php'),
    'renderInvoicesPanel chama geo_invoice_detail.php para itens (GEO.R1.9.1).');

// S06-S07 — nf-periodo-table "Ver no mapa"
probe($checks, $pass, $fail, 'S06', str_contains($nfTableJs, 'mapa_vendas.php'),
    'nf-periodo-table.js tem link mapa_vendas.php em cada linha (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S07', str_contains($nfTableJs, 'colspan="8"') || substr_count($nfTableJs, '<th') >= 8,
    'nf-periodo-table.js tem 8 colunas incluindo coluna Mapa (GEO.R1.9.1).');

// S08-S09 — pedidos.html
probe($checks, $pass, $fail, 'S08', str_contains($pedidosHtml, 'pedidosMapLink'),
    'pedidos.html tem pedidosMapLink (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S09', str_contains($pedidosHtml, 'mapa_vendas.php') && str_contains($pedidosHtml, 'date_from'),
    'pedidos.html sincroniza período no link do mapa (GEO.R1.9.1).');

// S10-S11 — index.html
probe($checks, $pass, $fail, 'S10', str_contains($indexHtml, 'indexGeoCnaeStrip'),
    'index.html tem bloco indexGeoCnaeStrip (GEO.R1.9.1).');
probe($checks, $pass, $fail, 'S11', str_contains($indexHtml, 'company_cnae_ranking.php'),
    'index.html carrega company_cnae_ranking.php no script inline (GEO.R1.9.1).');

// S12-S15 — Casos de teste fixos (GeoJSON)
$geoCeara = json_decode(readSafe($root . '/assets/geo/ceara_municipios_oficial.geojson'), true);
$geoFort  = json_decode(readSafe($root . '/assets/geo/fortaleza_bairros.json'), true);
$geoMetro = json_decode(readSafe($root . '/assets/geo/rmf_bairros_ibge_2022.geojson'), true);

$cearaKeys   = array_map(fn($f) => strtolower(trim($f['properties']['cidade_key'] ?? $f['properties']['municipio_norm'] ?? '')), $geoCeara['features'] ?? []);
$fortNomes   = array_map(fn($f) => strtolower(trim($f['properties']['Nome'] ?? $f['properties']['nome'] ?? '')), $geoFort['features'] ?? []);
$metroLookup = [];
foreach ($geoMetro['features'] ?? [] as $mf) {
    $p = $mf['properties'] ?? [];
    $metroLookup[$p['bairro_norm'] ?? ''] = strtolower($p['municipio_nome'] ?? '');
}

probe($checks, $pass, $fail, 'S12', in_array('aracati', $cearaKeys, true),
    "Aracati existe em ceara_municipios_oficial.geojson (caso de teste fixo).");
probe($checks, $pass, $fail, 'S13', in_array('centro', $fortNomes, true),
    "Centro existe em fortaleza_bairros.json (caso de teste fixo).");
probe($checks, $pass, $fail, 'S14',
    isset($metroLookup['olho_d_agua']) && str_contains($metroLookup['olho_d_agua'], 'eus'),
    "Olho d'Água pertence a Eusébio no GeoJSON metro (caso de teste fixo).");
probe($checks, $pass, $fail, 'S15', in_array('sobral', $cearaKeys, true),
    "Sobral existe em ceara_municipios_oficial.geojson (caso de teste fixo).");

// S16 — Mapa composto GEO.R1.7 preservado
probe($checks, $pass, $fail, 'S16', str_contains($mapJs, 'initCompositeMap') && str_contains($mapJs, 'return initCompositeMap()'),
    'Mapa composto GEO.R1.7 preservado (initCompositeMap + dispatch).');

// S17-S19 — Drawer lazy tabs
probe($checks, $pass, $fail, 'S17', str_contains($mapJs, "'notas'") && str_contains($mapJs, 'loadTerritoryInvoices'),
    "Aba 'notas' carrega loadTerritoryInvoices() (GEO.R1.9).");
probe($checks, $pass, $fail, 'S18', str_contains($mapJs, "'cnaes'") && str_contains($mapJs, 'loadTerritoryCnaes'),
    "Aba 'cnaes' carrega loadTerritoryCnaes() — fix CNAE vazio (GEO.R1.9).");

$tabsExpected = ['resumo','compradores','notas','cnaes','evolucao','acoes'];
$tabsOk = true;
foreach ($tabsExpected as $tab) {
    if (!str_contains($mapHtml, "data-tab=\"{$tab}\"")) { $tabsOk = false; break; }
}
probe($checks, $pass, $fail, 'S19', $tabsOk,
    'Drawer tem 6 abas: resumo, compradores, notas, cnaes, evolucao, acoes.');

// S20 — PHP lint das novas APIs
$newApis = [
    'api/geo_invoice_detail.php',
    'api/company_cnae_ranking.php',
    'api/geo_territory_invoices.php',
    'api/geo_territory_cnaes.php',
    'api/geo_sales_coverage_audit.php',
];
$lintFail = [];
foreach ($newApis as $api) {
    if (!phpLint($root . '/' . $api)) $lintFail[] = $api;
}
probe($checks, $pass, $fail, 'S20', empty($lintFail),
    'Todas as novas APIs têm sintaxe PHP válida. Falhou: ' . (empty($lintFail) ? 'nenhuma' : implode(', ', $lintFail)));

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.9.1 — Cross-Link Smoke Probe' . PHP_EOL;
echo str_repeat('-', 54) . PHP_EOL;
foreach ($checks as $c) echo '[' . $c['status'] . '] ' . $c['id'] . ' — ' . $c['message'] . PHP_EOL;
echo PHP_EOL . 'Resultado: ' . $pass . ' PASS / ' . $fail . ' FAIL' . PHP_EOL;
if ($fail === 0) { echo PHP_EOL . '[PASS] PRONTO PARA DEPLOY' . PHP_EOL; exit(0); }
else { echo PHP_EOL . '[FAIL] ' . $fail . ' verificações falharam.' . PHP_EOL; exit(1); }
