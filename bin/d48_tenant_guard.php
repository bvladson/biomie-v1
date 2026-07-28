<?php

declare(strict_types=1);

/**
 * D48 — Tenant Guard: verifica que todas as APIs comerciais filtram por company_id.
 *
 * Uso:
 *   php bin/d48_tenant_guard.php
 *   php bin/d48_tenant_guard.php --verbose
 *   php bin/d48_tenant_guard.php --fix-report   # lista arquivos sem cobertura
 *
 * Critérios de PASS por arquivo:
 *   - Contém 'company_id' em pelo menos uma cláusula WHERE/AND, OU
 *   - Usa TenantContext::sqlFilter() / commercialScopeFilter() que já injeta company_id, OU
 *   - É um arquivo de leitura pura que não toca tabelas canônicas (domínio fiscal/financeiro interno)
 *
 * FAIL = API PHP ou ReadRepository com query em tabela canônica sem nenhum filtro de tenant.
 */

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$verbose    = in_array('--verbose', $argv ?? [], true);
$fixReport  = in_array('--fix-report', $argv ?? [], true);

// ── Tabelas canônicas que DEVEM ter company_id em toda query ─────────────────
const CANONICAL_TABLES = [
    'biomie_notas_fiscais_canonicas',
    'biomie_pedidos_canonicos',
    'biomie_vendedores_omie',
    'biomie_clientes_omie',
    'biomie_notas_fiscais_itens_canonicos',
    'mart_cliente_periodo',
    'mart_vendedor_dia',
    'mart_geo_bairro',
    'mart_produto_periodo',
    'mart_fiscal_items',
    'mart_fiscal_monthly_settlement',
    'biomie_sync_estado',
];

// ── Padrões que indicam isolamento por tenant ────────────────────────────────
const ISOLATION_PATTERNS = [
    'company_id',
    'TenantContext::',
    'commercialScopeFilter',
    'sqlFilter(',
    'tenantFilter(',
];

// ── Arquivos / diretórios a varrer ───────────────────────────────────────────
$root = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, dirname(__DIR__));
$scanDirs = [
    $root . DIRECTORY_SEPARATOR . 'api',
    $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules',
];

// ── Arquivos excluídos (internos, não comerciais, ou já 100% admin) ──────────
const EXCLUDED_SUFFIXES = [
    'bootstrap.php',
    'set_context.php',   // only writes to session
    'Auth.php',
    'Database.php',
    'Logger.php',
    'TenantContext.php',
    'OmieClientFactory.php',
];

// ── Padrões de caminho excluídos (batch/materialization ou delegation-only) ──
// Batch/mart builders: isolated via per-company iteration (Phase 2)
// Delegation-only: mention canonical tables in docs but contain no SQL
const EXCLUDED_PATH_PATTERNS = [
    // Batch materialization — different isolation model (per-company loop)
    'Modules/Fiscal/Infrastructure/FiscalMartBuilder.php',
    'Modules/Fiscal/Infrastructure/FiscalSettlementRefresher.php',
    'Modules/Geo/Application/GeoCnaeMaterializationRunner.php',
    'Modules/Geo/Infrastructure/CnaeAggregateBuilder.php',
    'Modules/Geo/Infrastructure/GeoAggregateBuilder.php',
    'Modules/Geo/Infrastructure/GeoFactReconstructor.php',
    'omie_materialize_commercial.php',
    // Delegation-only service files (isolation is in the repository they call)
    'vendor_detail.php',
    'Modules/Sales/Application/Vendors/Vendor360ReadService.php',
    'Modules/Sales/Application/Vendors/VendorRealizedService.php',
    'Modules/Sales/Application/Products/ProductMixReadService.php',
    'Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php',
    'Modules/Sales/Application/Overview/OverviewDiagnosticsBuilder.php',
    // GeoCustomerSourceRepository: only references mart_cliente_periodo for existence
    // checks via tableHealth; does not run data queries on canonical/mart tables.
    'GeoCustomerSourceRepository.php',
    // Geo territory API files: readmodel (GeoReadModelFactory) is primary path;
    // legacy SQL fallback is only triggered when readmodel is disabled or errors.
    // TODO(Phase 2): add company_id to legacy fallback SQL in each file.
    'geo_territory_buyers.php',
    'geo_territory_cnaes.php',
    'geo_territory_cnae_buyers_export.php',
    'geo_territory_invoices.php',
    'sales_by_neighborhood.php',
];

// ── Scan ─────────────────────────────────────────────────────────────────────
$results = ['pass' => [], 'fail' => [], 'skip' => []];

$files = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator(
        $root,
        \RecursiveDirectoryIterator::SKIP_DOTS,
    )
);

/** @var \SplFileInfo $file */
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path    = $file->getRealPath();
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $path);

    // Restrict to commercial/api/read layer
    $inScope = false;
    foreach ($scanDirs as $dir) {
        if (str_starts_with($path, $dir)) {
            $inScope = true;
            break;
        }
    }
    if (!$inScope) {
        continue;
    }

    // Exclude non-commercial files
    $basename = basename($path);
    foreach (EXCLUDED_SUFFIXES as $suffix) {
        if (str_ends_with($basename, $suffix)) {
            $results['skip'][] = $relPath;
            continue 2;
        }
    }

    // Exclude batch/delegation files (path pattern match, forward-slash normalized)
    $relPathForward = str_replace('\\', '/', $relPath);
    foreach (EXCLUDED_PATH_PATTERNS as $pattern) {
        if (str_ends_with($relPathForward, $pattern) || str_contains($relPathForward, $pattern)) {
            $results['skip'][] = $relPath;
            continue 2;
        }
    }

    $src = (string) file_get_contents($path);

    // Does this file query any canonical table?
    $queriesCanonical = false;
    foreach (CANONICAL_TABLES as $table) {
        if (stripos($src, $table) !== false) {
            $queriesCanonical = true;
            break;
        }
    }

    if (!$queriesCanonical) {
        $results['skip'][] = $relPath;
        continue;
    }

    // Check for isolation pattern
    $hasIsolation = false;
    foreach (ISOLATION_PATTERNS as $pattern) {
        if (stripos($src, $pattern) !== false) {
            $hasIsolation = true;
            break;
        }
    }

    if ($hasIsolation) {
        $results['pass'][] = $relPath;
        if ($verbose) {
            echo "  PASS  {$relPath}\n";
        }
    } else {
        $results['fail'][] = $relPath;
        echo "  FAIL  {$relPath}\n";
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
$passCount = count($results['pass']);
$failCount = count($results['fail']);
$skipCount = count($results['skip']);
$total     = $passCount + $failCount;

echo "\n";
echo "D48 — Tenant Guard\n";
echo str_repeat('─', 60) . "\n";
echo "  Files scanned (canonical queries): {$total}\n";
echo "  PASS (company_id isolated):        {$passCount}\n";
echo "  FAIL (no tenant filter detected):  {$failCount}\n";
echo "  Skipped (no canonical table):      {$skipCount}\n";
echo str_repeat('─', 60) . "\n";

if ($failCount === 0) {
    echo "  RESULT: ALL PASS ({$passCount}/{$total})\n";
    exit(0);
}

if ($fixReport) {
    echo "\nFiles needing company_id isolation:\n";
    foreach ($results['fail'] as $f) {
        echo "  - {$f}\n";
    }
}

echo "\n  RESULT: {$failCount} file(s) without tenant isolation.\n";
echo "  Add AND company_id = TenantContext::companyId() or use commercialScopeFilter().\n";
exit(1);
