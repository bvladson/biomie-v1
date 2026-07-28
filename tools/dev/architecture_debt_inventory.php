<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = getopt('', ['output:', 'baseline:', 'write-baseline', 'enforce']);
$root = dirname(__DIR__, 2);
$scanRoots = ['api', 'app', 'views'];
$canonicalPattern = '/\\b(?:biomie_(?:pedidos|notas_fiscais|titulos_financeiros|vendedores|clientes)[a-z0-9_]*|mart_[a-z0-9_]+)\\b/i';
$allowedPathPattern = '#(?:Migration|Repository|ReadModel|tools/|tests/)#i';
$reads = [];
$lineCounts = [];

foreach ($scanRoots as $scanRoot) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $scanRoot));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !in_array($entry->getExtension(), ['php', 'html'], true)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $lines = file($entry->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        $lineCounts[$relative] = count($lines);
        foreach ($lines as $index => $line) {
            if (!preg_match('/\\b(?:SELECT|FROM|JOIN)\\b/i', $line)
                || !preg_match_all($canonicalPattern, $line, $tables)
            ) {
                continue;
            }
            foreach (array_unique(array_map('strtolower', $tables[0])) as $table) {
                $allowed = preg_match($allowedPathPattern, $relative) === 1;
                $reads[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'table' => $table,
                    'classification' => $allowed ? 'allowed_infrastructure' : 'architecture_debt',
                    'criticality' => str_starts_with($relative, 'api/') ? 'high' : 'medium',
                ];
            }
        }
    }
}

$baselinePath = isset($opts['baseline'])
    ? (string) $opts['baseline']
    : $root . '/config/architecture-complexity-baseline.json';
$large = [];
foreach ($lineCounts as $file => $lines) {
    if ($lines > 1000) {
        $large[$file] = $lines;
    }
}
ksort($large);
if (isset($opts['write-baseline'])) {
    $json = json_encode([
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'rule' => 'Existing files above 1000 lines may shrink but may not grow.',
        'files' => $large,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($baselinePath, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write architecture baseline.');
    }
}
$baselinePayload = is_file($baselinePath)
    ? json_decode((string) file_get_contents($baselinePath), true)
    : [];
$baseline = is_array($baselinePayload['files'] ?? null) ? $baselinePayload['files'] : [];
$growth = [];
foreach ($large as $file => $lines) {
    if (!isset($baseline[$file]) || $lines > (int) $baseline[$file]) {
        $growth[$file] = ['baseline' => $baseline[$file] ?? null, 'current' => $lines];
    }
}

$debt = array_values(array_filter($reads, static fn (array $row): bool => $row['classification'] === 'architecture_debt'));
$payload = [
    'ok' => $growth === [],
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'read_model_reads' => [
        'total_detected' => count($reads),
        'architecture_debt' => count($debt),
        'high_criticality' => count(array_filter($debt, static fn (array $row): bool => $row['criticality'] === 'high')),
        'entries' => $reads,
    ],
    'complexity_ratchet' => [
        'existing_files_above_1000_lines' => count($large),
        'growth_violations' => $growth,
        'baseline' => 'config/architecture-complexity-baseline.json',
    ],
];
$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write architecture inventory.');
    }
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(isset($opts['enforce']) && $growth !== [] ? 1 : 0);

