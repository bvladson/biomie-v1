<?php

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

$shortopts = '';
$longopts = [
    'start:',
    'end:',
    'vendor:',
    'json',
];
$options = getopt($shortopts, $longopts);

$start = $options['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $options['end'] ?? date('Y-m-d');
$vendor = $options['vendor'] ?? '__SEM_VENDEDOR__';
$json = isset($options['json']);

function callApi(string $url) {
    // Simulate HTTP request internally by including the file or using cURL.
    // For a local probe, we'll use a direct internal boot loop.
    // But since `api/flight_plan.php` has HTTP context and exit() in Http::json(), we need to use a sub-process.
    $php = PHP_BINARY;
    $cmd = sprintf(
        'set REQUEST_METHOD=GET && set QUERY_STRING=%s && %s %s',
        escapeshellarg(parse_url($url, PHP_URL_QUERY) ?? ''),
        $php,
        escapeshellarg(__DIR__ . '/../../' . parse_url($url, PHP_URL_PATH))
    );

    // Bypassing Auth check could be hard via CLI if Auth requires headers.
    // Assuming Auth::requireCapability handles CLI gracefully or we override it.
    // Actually, setting HTTP_X_BIOMIE_CAPABILITY might be needed, or using cURL to localhost.
    // For simplicity, we just execute via CLI and set environment to bypass or fake it if needed.
    
    // Better way: use file_get_contents to localhost if server is running.
    // Or just run it via CLI and capture output (it usually outputs JSON and exits).
    // Let's use CLI.
    putenv('CLI_API_TEST=1');
    $output = shell_exec($cmd);
    return json_decode((string)$output, true);
}

// Emulating auth override for CLI tests if supported
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// We will use cURL to http://localhost/biomie/api/... to avoid fastcgi/auth issues if running locally
$baseUrl = 'http://localhost/biomie';

if (!function_exists('curl_init')) {
    echo "cURL is required for this probe.\n";
    exit(1);
}

function fetchLocal(string $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Fake auth header if needed or session cookie
    // curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-BIomie-Auth: test']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400 || !$response) {
        // Fallback for environments without local web server but with php-cli
        // We can't easily test HTTP endpoints if the web server is not up.
    }
    
    return [
        'code' => $httpCode,
        'body' => $response,
        'data' => json_decode((string)$response, true)
    ];
}

if (!$json) {
    echo "=> Testando /api/flight_plan.php (Start: $start, End: $end)\n";
}

$planUrl = $baseUrl . "/api/flight_plan.php?start={$start}&end={$end}";
$planRes = fetchLocal($planUrl);

if (!$json) {
    if ($planRes['code'] === 200 && is_array($planRes['data'])) {
        echo "   [OK] HTTP 200\n";
        echo "   Data Quality: " . ($planRes['data']['data_quality'] ?? 'missing') . "\n";
        echo "   Vendedores: " . count($planRes['data']['vendors'] ?? []) . "\n";
    } else {
        echo "   [FALHA] HTTP {$planRes['code']}\n";
    }
}

if (!$json) {
    echo "=> Testando /api/flight_plan_vendor_detail.php (Vendor: $vendor)\n";
}

$vendorUrl = $baseUrl . "/api/flight_plan_vendor_detail.php?start={$start}&end={$end}&vendor_id=" . urlencode($vendor);
$vendorRes = fetchLocal($vendorUrl);

if (!$json) {
    if ($vendorRes['code'] === 200 && is_array($vendorRes['data'])) {
        echo "   [OK] HTTP 200\n";
        echo "   Data Quality: " . ($vendorRes['data']['data_quality'] ?? 'missing') . "\n";
        echo "   Vendor Name: " . ($vendorRes['data']['vendor']['name'] ?? 'missing') . "\n";
        echo "   Is Generic: " . (($vendorRes['data']['vendor']['is_generic'] ?? false) ? 'true' : 'false') . "\n";
    } else {
        echo "   [FALHA] HTTP {$vendorRes['code']}\n";
    }
}

if ($json) {
    echo json_encode([
        'flight_plan' => [
            'status' => $planRes['code'],
            'data_quality' => $planRes['data']['data_quality'] ?? null,
            'vendors_count' => count($planRes['data']['vendors'] ?? []),
        ],
        'vendor_detail' => [
            'status' => $vendorRes['code'],
            'data_quality' => $vendorRes['data']['data_quality'] ?? null,
            'vendor_name' => $vendorRes['data']['vendor']['name'] ?? null,
            'is_generic' => $vendorRes['data']['vendor']['is_generic'] ?? null,
        ]
    ], JSON_PRETTY_PRINT);
}

exit(0);
