<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = getopt('', ['baseline:', 'output:', 'write-baseline', 'enforce']);
$root = dirname(__DIR__, 2);
$baselinePath = isset($opts['baseline'])
    ? (string) $opts['baseline']
    : $root . '/config/asset-budget-baseline.json';
$assets = [];
foreach (['assets/js', 'assets/css'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !in_array($entry->getExtension(), ['js', 'css'], true)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $content = (string) file_get_contents($entry->getPathname());
        $assets[$relative] = [
            'bytes' => strlen($content),
            'gzip_bytes' => strlen(gzencode($content, 9)),
        ];
    }
}
ksort($assets);
if (isset($opts['write-baseline'])) {
    $json = json_encode([
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'maximum_growth_pct' => 5,
        'assets' => $assets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($baselinePath, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write asset budget baseline.');
    }
}
$baselinePayload = is_file($baselinePath)
    ? json_decode((string) file_get_contents($baselinePath), true)
    : [];
$baseline = is_array($baselinePayload['assets'] ?? null) ? $baselinePayload['assets'] : [];
$violations = [];
foreach ($assets as $file => $sizes) {
    if (!isset($baseline[$file])) {
        $violations[$file] = ['reason' => 'new_asset_requires_review', 'current' => $sizes];
        continue;
    }
    $limit = (int) floor(((int) $baseline[$file]['gzip_bytes']) * 1.05);
    if ($sizes['gzip_bytes'] > $limit) {
        $violations[$file] = [
            'reason' => 'gzip_growth_above_5_pct',
            'baseline_gzip_bytes' => (int) $baseline[$file]['gzip_bytes'],
            'current_gzip_bytes' => $sizes['gzip_bytes'],
            'limit_gzip_bytes' => $limit,
        ];
    }
}
$payload = [
    'ok' => $violations === [],
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'summary' => [
        'assets' => count($assets),
        'raw_bytes' => array_sum(array_column($assets, 'bytes')),
        'gzip_bytes' => array_sum(array_column($assets, 'gzip_bytes')),
        'violations' => count($violations),
    ],
    'violations' => $violations,
    'assets' => $assets,
];
$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write asset budget report.');
    }
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(isset($opts['enforce']) && $violations !== [] ? 1 : 0);

