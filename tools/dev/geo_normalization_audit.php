<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Support\NeighborhoodNormalizer;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['json']);
$asJson = isset($options['json']);
$casesPath = __DIR__ . '/geo_normalization_cases.json';
$raw = is_file($casesPath) ? file_get_contents($casesPath) : false;
$cases = $raw !== false ? json_decode($raw, true) : null;

if (!is_array($cases)) {
    $payload = [
        'status' => 'error',
        'message' => 'Nao foi possivel ler tools/dev/geo_normalization_cases.json',
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$results = [];
$failures = 0;
foreach ($cases as $index => $case) {
    $input = $case['input'] ?? null;
    $expected = (string) ($case['expected'] ?? '');
    $actual = NeighborhoodNormalizer::normalizeKey(is_string($input) ? $input : null);
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    $results[] = [
        'index' => $index,
        'input' => $input,
        'expected' => $expected,
        'actual' => $actual,
        'ok' => $ok,
        'explain' => NeighborhoodNormalizer::explain(is_string($input) ? $input : null),
    ];
}

$payload = [
    'status' => $failures === 0 ? 'ok' : 'fail',
    'checked' => count($results),
    'failures' => $failures,
    'normalizer' => 'NeighborhoodNormalizer',
    'results' => $results,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "--- GEO NORMALIZATION AUDIT ---\n";
    echo "Status: {$payload['status']}\n";
    echo "Checked: {$payload['checked']}\n";
    echo "Failures: {$payload['failures']}\n";
    foreach ($results as $result) {
        $mark = $result['ok'] ? 'OK' : 'FAIL';
        $inputLabel = $result['input'] === null ? 'null' : '"' . (string) $result['input'] . '"';
        echo "{$mark} {$inputLabel} => {$result['actual']}";
        if (!$result['ok']) {
            echo " (expected {$result['expected']})";
        }
        echo "\n";
    }
}

exit($failures === 0 ? 0 : 1);
