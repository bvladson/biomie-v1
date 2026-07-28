<?php

/**
 * D53 — Company Storage Isolation Guard
 *
 * Validates that:
 * 1. Each active company has its own storage/companies/{slug}/ directory.
 * 2. No tenant-scoped Omie cache files remain in the global storage/cache/ directory.
 *
 * Usage: php bin/d53_company_storage_guard.php [--fix]
 *   --fix  Delete legacy tenant cache files found in storage/cache/
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BIomie\Bootstrap;

Bootstrap::init(dirname(__DIR__));

$fix = in_array('--fix', $argv ?? [], true);

$root       = Bootstrap::rootPath();
$cacheGlobal = $root . '/storage/cache';
$storageBase = $root . '/storage/companies';

$companies = [
    'mag_filial',
    'expert',
    'uni_design',
    'mag_matriz',
    'ergooffice',
];

$pass    = true;
$details = [];

echo "\n";
echo "D53 COMPANY STORAGE ISOLATION\n";
echo str_repeat('═', 50) . "\n\n";

/* ── 1. Company storage directories ── */
echo "Company storage directories:\n";
foreach ($companies as $slug) {
    $dir = $storageBase . '/' . $slug;
    if (is_dir($dir)) {
        echo "  ✓ {$slug}\n";
    } else {
        echo "  ✗ {$slug}  (missing: storage/companies/{$slug}/)\n";
        // Not a hard failure — directory is created on first write.
    }
}

/* ── 2. Global cache leakage check ── */
echo "\nGlobal cache leakage (storage/cache/):\n";

$leakPatterns = [
    'company_*_canal_*.json'  => 'Tenant cache (old format)',
    'omie_cb_*.json'          => 'Omie circuit-breaker (old location)',
    'omie_response_*.json'    => 'Omie response cache (old location)',
];

$totalLeaks = 0;

foreach ($leakPatterns as $glob => $label) {
    $files = glob($cacheGlobal . '/' . $glob) ?: [];
    // Filter out company_1_canal_varejo (legacy main company — acceptable during transition)
    $leaked = array_values(array_filter(
        $files,
        static fn (string $f): bool => !str_contains(basename($f), 'company_1_canal_varejo')
    ));

    if ($leaked === []) {
        echo "  ✓ {$glob}: 0 files\n";
        continue;
    }

    $totalLeaks += count($leaked);
    $pass = false;
    echo "  ✗ {$glob}: " . count($leaked) . " file(s)\n";

    foreach ($leaked as $f) {
        $rel = str_replace($root . '/', '', $f);
        echo "      {$rel}\n";
        if ($fix) {
            @unlink($f);
            @unlink($f . '.tmp');
        }
    }
}

/* ── 3. Rate-limit dir check ── */
$rlDir = $cacheGlobal . '/omie-rate-limit';
if (is_dir($rlDir)) {
    $rlFiles = glob($rlDir . '/omie_rl_*.json') ?: [];
    if ($rlFiles !== []) {
        echo "\n  ℹ omie-rate-limit/ still in global cache (" . count($rlFiles) . " files).\n";
        echo "    These are shared rate-limit state files; they migrate automatically\n";
        echo "    when syncs run through OmieClientFactory after this update.\n";
    }
}

/* ── Result ── */
echo "\n" . str_repeat('─', 50) . "\n";

if ($pass) {
    echo "Final: PASS\n";
    echo "Company storage isolation enforced — no tenant cache leaked to global dir.\n";
} else {
    echo "Final: FAIL\n";
    echo "Reason: Found " . $totalLeaks . " tenant cache file(s) outside CompanyStorage.\n";
    if (!$fix) {
        echo "Run with --fix to delete leaked files:\n";
        echo "  php bin/d53_company_storage_guard.php --fix\n";
    } else {
        echo "Leaked files deleted. Re-run to confirm clean state.\n";
    }
}

echo "\n";
exit($pass ? 0 : 1);
