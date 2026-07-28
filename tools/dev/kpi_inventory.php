<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Support/CliGuard.php';

use BIomie\Support\CliGuard;

CliGuard::requireCli();

/**
 * KPI Inventory — scans app/, api/, docs/, tests/ for metric-related terms.
 *
 * Usage:
 *   php tools/dev/kpi_inventory.php
 *   php tools/dev/kpi_inventory.php --json
 *
 * Output: JSON with term occurrences and possible metric locations.
 */

$terms = [
    'source_of_truth',
    'last_success_at',
    'last_reconcile_at',
    'receita',
    'faturamento',
    'ranking',
    'inadimplencia',
    'aging',
    'top_clients',
    'topProducts',
    'top_produtos',
    'clientes_recorrentes',
    'clientes_ativos',
    'geo',
    'cnae',
    'shadow',
    'freshness',
    'valor_total',
    'valor_nfe',
    'data_competencia',
    'status_fiscal',
    'mart_fiscal_nfe',
    'mart_cliente_periodo',
    'mart_vendedor_dia',
    'biomie_pipeline_jobs',
    'receita_fiscal',
    'receita_comercial',
    'pedidos_faturados',
    'inadimplencia_total',
    'contas_a_receber',
    'compradores_por_geo',
    'vendas_por_cnae',
];

$scanDirs  = [__DIR__ . '/../../app', __DIR__ . '/../../api', __DIR__ . '/../../docs', __DIR__ . '/../../tests'];
$fileTypes = ['php', 'md', 'json'];

function scanForTerm(string $term, array $dirs, array $types): array
{
    $files = [];
    $count = 0;

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $types, true)) {
                continue;
            }
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }
            $occurrences = substr_count(strtolower($content), strtolower($term));
            if ($occurrences > 0) {
                $count += $occurrences;
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getRealPath(), strlen(realpath(__DIR__ . '/../../')) + 1));
                $files[]   = $relative;
            }
        }
    }

    return ['count' => $count, 'files' => array_values(array_unique($files))];
}

function detectPossibleMetrics(array $dirs): array
{
    $patterns = [
        '/["\']receita[_\w]*["\']/',
        '/["\']faturamento[_\w]*["\']/',
        '/["\']ranking[_\w]*["\']/',
        '/["\']inadimplencia[_\w]*["\']/',
        '/["\']clientes_[a-z_]+["\']/',
        '/["\']vendas_[a-z_]+["\']/',
        '/["\']notas_[a-z_]+["\']/',
        '/["\']compradores_[a-z_]+["\']/',
        '/["\']ticket_[a-z_]+["\']/',
        '/["\']coverage_[a-z_]+["\']/',
        '/["\']shadow_[a-z_]+["\']/',
        '/["\']jobs_[a-z_]+["\']/',
        '/["\']webhooks_[a-z_]+["\']/',
        '/KPI_[A-Z_]+/',
    ];

    $found = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }
            foreach ($patterns as $pat) {
                if (preg_match_all($pat, $content, $m)) {
                    foreach ($m[0] as $match) {
                        $clean = trim($match, '"\'');
                        $found[$clean] = ($found[$clean] ?? 0) + 1;
                    }
                }
            }
        }
    }

    arsort($found);
    return array_slice($found, 0, 50, true);
}

$terms_result = [];
foreach ($terms as $term) {
    $terms_result[$term] = scanForTerm($term, $scanDirs, $fileTypes);
}

$possible_metrics = detectPossibleMetrics($scanDirs);

$result = [
    'ok'               => true,
    'scanned_dirs'     => ['app', 'api', 'docs', 'tests'],
    'terms_searched'   => count($terms),
    'terms'            => $terms_result,
    'possible_metrics' => $possible_metrics,
];

$jsonFlag = in_array('--json', $argv ?? [], true);
if ($jsonFlag) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "KPI Inventory\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($terms_result as $term => $data) {
        if ($data['count'] > 0) {
            printf("  %-35s  %d occurrences in %d files\n", $term, $data['count'], count($data['files']));
        }
    }
    echo "\nPossible metrics detected:\n";
    foreach (array_slice($possible_metrics, 0, 20, true) as $metric => $count) {
        printf("  %-40s  (%d)\n", $metric, $count);
    }
    echo "\nRun with --json for full output.\n";
}
