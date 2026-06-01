<?php

declare(strict_types=1);

/**
 * tools/dev/geo_territory_detail_audit.php
 *
 * GEO.R1.9 — Auditoria de detalhe territorial: verifica que as APIs e o
 * drawer estão preparados para mostrar NFs, CNAEs e evolução por território.
 *
 * D01  api/geo_territory_invoices.php existe e tem sintaxe válida
 * D02  api/geo_territory_cnaes.php existe e tem sintaxe válida
 * D03  api/geo_territory_evolution.php existe (GEO.R1.8.2)
 * D04  api/geo_sales_coverage_audit.php existe e tem sintaxe válida
 * D05  Drawer tem aba "Notas" (data-tab="notas")
 * D06  Drawer tem painel geoInvoicesPanel
 * D07  loadTerritoryInvoices() presente no JS
 * D08  loadTerritoryCnaes() presente no JS
 * D09  buildTerritoryParams() presente no JS (helper para territory-aware API calls)
 * D10  GeoSmartLabels presente no JS
 * D11  GeoSmartLabels.shouldShowLabel presente
 * D12  GeoSmartLabels.applyLabels presente
 * D13  CSS tem geo-label-fortaleza
 * D14  loadCoverageAuditPanel() presente no JS
 * D15  showEnrichCard usa % e 'diagnóstico' (GEO.R1.9 improvement)
 * D16  geo_territory_identity_audit.php existe
 * D17  geo_territory_detail_audit.php existe (este arquivo)
 *
 * Uso: php tools/dev/geo_territory_detail_audit.php
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

$mapJs   = readSafe($root . '/assets/js/biomie-map.js');
$mapHtml = readSafe($root . '/views/mapa_vendas.html');
$mapCss  = readSafe($root . '/assets/css/biomie-map.css');

// D01-D04 — APIs
probe($checks, $pass, $fail, 'D01', phpLint($root . '/api/geo_territory_invoices.php'),
    'api/geo_territory_invoices.php existe e tem sintaxe válida (GEO.R1.9).');
probe($checks, $pass, $fail, 'D02', phpLint($root . '/api/geo_territory_cnaes.php'),
    'api/geo_territory_cnaes.php existe e tem sintaxe válida (GEO.R1.9).');
probe($checks, $pass, $fail, 'D03', file_exists($root . '/api/geo_territory_evolution.php'),
    'api/geo_territory_evolution.php existe (GEO.R1.8.2).');
probe($checks, $pass, $fail, 'D04', phpLint($root . '/api/geo_sales_coverage_audit.php'),
    'api/geo_sales_coverage_audit.php existe e tem sintaxe válida (GEO.R1.9).');

// D05-D06 — HTML
probe($checks, $pass, $fail, 'D05', str_contains($mapHtml, 'data-tab="notas"'),
    'Drawer tem aba data-tab="notas" (GEO.R1.9).');
probe($checks, $pass, $fail, 'D06', str_contains($mapHtml, 'geoInvoicesPanel'),
    'Drawer tem painel geoInvoicesPanel (GEO.R1.9).');

// D07-D15 — JS functions
probe($checks, $pass, $fail, 'D07', str_contains($mapJs, 'loadTerritoryInvoices'),
    'loadTerritoryInvoices() presente no JS (GEO.R1.9).');
probe($checks, $pass, $fail, 'D08', str_contains($mapJs, 'loadTerritoryCnaes'),
    'loadTerritoryCnaes() presente no JS (GEO.R1.9 — fix CNAE vazio).');
probe($checks, $pass, $fail, 'D09', str_contains($mapJs, 'buildTerritoryParams'),
    'buildTerritoryParams() presente no JS (GEO.R1.9).');
probe($checks, $pass, $fail, 'D10', str_contains($mapJs, 'GeoSmartLabels'),
    'GeoSmartLabels presente no JS (GEO.R1.9).');
probe($checks, $pass, $fail, 'D11', str_contains($mapJs, 'shouldShowLabel'),
    'GeoSmartLabels.shouldShowLabel() presente (GEO.R1.9).');
probe($checks, $pass, $fail, 'D12', str_contains($mapJs, 'applyLabels'),
    'GeoSmartLabels.applyLabels() presente (GEO.R1.9).');
probe($checks, $pass, $fail, 'D13', str_contains($mapCss, 'geo-label-fortaleza'),
    'CSS tem classe geo-label-fortaleza (GEO.R1.9).');
probe($checks, $pass, $fail, 'D14', str_contains($mapJs, 'loadCoverageAuditPanel'),
    'loadCoverageAuditPanel() presente no JS (GEO.R1.9).');
probe($checks, $pass, $fail, 'D15', str_contains($mapJs, 'diagnóstico') && str_contains($mapJs, 'coverage_pct'),
    'showEnrichCard melhorado com diagnóstico de cobertura % (GEO.R1.9).');

// D16-D17 — Audit tools
probe($checks, $pass, $fail, 'D16', file_exists($root . '/tools/dev/geo_territory_identity_audit.php'),
    'geo_territory_identity_audit.php existe (GEO.R1.9).');
probe($checks, $pass, $fail, 'D17', file_exists($root . '/tools/dev/geo_territory_detail_audit.php'),
    'geo_territory_detail_audit.php existe (este arquivo — GEO.R1.9).');

// ── Relatório ──────────────────────────────────────────────────────────────
echo PHP_EOL . 'GEO.R1.9 — Territory Detail Audit' . PHP_EOL;
echo str_repeat('-', 52) . PHP_EOL;
foreach ($checks as $c) echo '[' . $c['status'] . '] ' . $c['id'] . ' — ' . $c['message'] . PHP_EOL;
echo PHP_EOL . 'Resultado: ' . $pass . ' PASS / ' . $fail . ' FAIL' . PHP_EOL;
if ($fail === 0) { echo PHP_EOL . '[PASS] PRONTO PARA DEPLOY' . PHP_EOL; exit(0); }
else { echo PHP_EOL . '[FAIL] ' . $fail . ' verificações falharam.' . PHP_EOL; exit(1); }
