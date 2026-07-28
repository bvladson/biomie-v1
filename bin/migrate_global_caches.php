<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Bootstrap.php';

use BIomie\Logger;

\BIomie\Bootstrap::init();

$cacheDir = \BIomie\Bootstrap::rootPath('storage/cache');
$filesToMigrate = [
    'board_consolidated_v1_u1_2026-06-01_2026-06-27.json',
    'board_consolidated_v1_u1_2026-06-01_2026-06-27.lock',
    'board_consolidated_v1_u1_2026-06-26_2026-06-26.json',
    'board_consolidated_v1_u1_2026-06-26_2026-06-26.lock',
    'finance_collection_governance_base_6e58882f436773274d9b33cd3f76b544e4415d0bf454261a118ffda704322380.json',
    'snapshot_fiscal_v2_2026-05-01_2026-05-31.json',
    'snapshot_fiscal_v2_2026-06-01_2026-06-11.json',
];

$migrated = 0;
foreach ($filesToMigrate as $file) {
    $path = $cacheDir . '/' . $file;
    if (file_exists($path)) {
        // As old caches without prefixes belong to company 1 in retail
        $newPath = $cacheDir . '/company_1_canal_varejo_' . $file;
        if (rename($path, $newPath)) {
            echo "Migrated: {$file} -> company_1_canal_varejo_{$file}\n";
            $migrated++;
        } else {
            echo "Failed to migrate: {$file}\n";
        }
    } else {
        echo "File not found (already migrated?): {$file}\n";
    }
}

echo "Total migrated: {$migrated}\n";
