<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';

\BIomie\Support\CliGuard::requireCli();

/**
 * Auditoria de arquivos grandes — Fase 5.
 *
 * Uso:
 *   php tools/dev/large_file_audit.php
 *   php tools/dev/large_file_audit.php --json
 *   php tools/dev/large_file_audit.php --threshold=500
 */

$json      = in_array('--json', $argv ?? [], true);
$threshold = 300;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--threshold=')) {
        $threshold = (int) substr($arg, 12);
    }
}

$root = dirname(__DIR__, 2);

$targets = [
    ['path' => 'app',        'ext' => 'php'],
    ['path' => 'api',        'ext' => 'php'],
    ['path' => 'views',      'ext' => 'php'],
    ['path' => 'assets/js',  'ext' => 'js'],
    ['path' => 'cron',       'ext' => 'php'],
];

$riskLimits = [
    'php' => ['critical' => 1200, 'high' => 700, 'medium' => 400],
    'js'  => ['critical' => 1500, 'high' => 800, 'medium' => 400],
    'php_api' => ['critical' => 600, 'high' => 300, 'medium' => 150],
];

$splitSuggestions = [
    'CanonicalRepository.php'     => ['DataPlatform/Silver/Schema', 'DataPlatform/Silver/Writers', 'Modules/Geo/Infrastructure', 'DataPlatform/Reconciliation'],
    'CanonicalReadRepository.php' => ['Modules/Sales/Infrastructure', 'Modules/Finance/Infrastructure', 'Modules/Fiscal/Infrastructure', 'DataPlatform/Silver/Readers'],
    'GoldMartBuilder.php'         => ['DataPlatform/Gold/Builders/CommercialOrder', 'DataPlatform/Gold/Builders/FiscalNfe', 'DataPlatform/Gold/Builders/FinanceTitle', 'DataPlatform/Gold/Builders/VendorDay'],
    'overview.php'                => ['Modules/Sales/Application/Overview/OverviewPayloadBuilder', 'Modules/Sales/Application/Overview/LegacyOverviewPayloadBuilder'],
    'app.js'                      => ['assets/js/core/formatters', 'assets/js/core/api-client', 'assets/js/core/chart-factory', 'assets/js/modules/overview'],
    'finance.js'                  => ['assets/js/modules/finance/api', 'assets/js/modules/finance/charts', 'assets/js/modules/finance/tables'],
    'admin.php'                   => ['views/admin/tabs/', 'views/admin/partials/', 'assets/js/modules/admin/'],
];

$results = [];

foreach ($targets as $target) {
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target['path']);
    if (!is_dir($dir)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== $target['ext']) {
            continue;
        }

        $rel   = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $lines = countLines($file->getPathname());

        if ($lines < $threshold) {
            continue;
        }

        $ext       = $target['ext'];
        $isApiFile = str_starts_with(str_replace('\\', '/', $rel), 'api/');
        $limits    = $isApiFile ? $riskLimits['php_api'] : ($riskLimits[$ext] ?? $riskLimits['php']);
        $risk      = classifyRisk($lines, $limits);

        $methods = countSymbols($file->getPathname(), $ext);
        $name    = $file->getBasename();

        $entry = [
            'file'             => str_replace('\\', '/', $rel),
            'lines'            => $lines,
            'methods'          => $methods,
            'risk'             => $risk,
            'suggested_split'  => $splitSuggestions[$name] ?? [],
        ];

        $results[] = $entry;
    }
}

usort($results, fn($a, $b) => $b['lines'] <=> $a['lines']);

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$riskColors = ['critical' => "\033[31m", 'high' => "\033[33m", 'medium' => "\033[36m", 'low' => "\033[32m"];
$reset      = "\033[0m";

printf("\n%-80s %6s %8s %10s\n", 'Arquivo', 'Linhas', 'Métodos', 'Risco');
echo str_repeat('-', 110) . "\n";

foreach ($results as $r) {
    $color = $riskColors[$r['risk']] ?? '';
    printf(
        "%s%-80s%s %6d %8d %10s\n",
        $color,
        $r['file'],
        $reset,
        $r['lines'],
        $r['methods'],
        $r['risk']
    );
    if (!empty($r['suggested_split'])) {
        foreach ($r['suggested_split'] as $split) {
            echo "  → $split\n";
        }
    }
}

$critical = count(array_filter($results, fn($r) => $r['risk'] === 'critical'));
$high     = count(array_filter($results, fn($r) => $r['risk'] === 'high'));
echo "\nTotal: " . count($results) . " arquivos acima de {$threshold} linhas. Critical: {$critical}. High: {$high}.\n\n";

// ── Funções auxiliares ───────────────────────────────────────────────────────

function countLines(string $path): int
{
    $lines = 0;
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }
    while (!feof($handle)) {
        fgets($handle);
        $lines++;
    }
    fclose($handle);
    return $lines;
}

function countSymbols(string $path, string $ext): int
{
    $content = file_get_contents($path);
    if ($content === false) {
        return 0;
    }

    if ($ext === 'php') {
        preg_match_all('/^\s*(?:public|protected|private|static|abstract|final|\s)*function\s+\w+/m', $content, $m);
        return count($m[0]);
    }

    // JS: function declarations + arrow functions assigned to vars/consts + methods
    preg_match_all('/(?:function\s+\w+|(?:const|let|var)\s+\w+\s*=\s*(?:async\s+)?(?:function|\())/m', $content, $m);
    return count($m[0]);
}

function classifyRisk(int $lines, array $limits): string
{
    if ($lines >= $limits['critical']) {
        return 'critical';
    }
    if ($lines >= $limits['high']) {
        return 'high';
    }
    if ($lines >= $limits['medium']) {
        return 'medium';
    }
    return 'low';
}
