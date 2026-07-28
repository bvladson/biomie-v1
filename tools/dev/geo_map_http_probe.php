<?php

declare(strict_types=1);

$options = getopt('', ['url:', 'json']);

$url = $options['url'] ?? 'http://localhost/biomie/api/geo_summary.php?date_from=2026-05-01&date_to=2026-05-31&city=Fortaleza';
$isJson = isset($options['json']);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// If it requires auth, in dev mode we might bypass it or it will return 401
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    // 'Authorization: Bearer cli' // if auth bypass is supported via header
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo $isJson ? json_encode(['ok' => false, 'error' => $error]) . "\n" : "cURL Error: {$error}\n";
    exit(1);
}

$decoded = json_decode((string)$response, true);

if ($isJson) {
    echo json_encode([
        'ok' => $status === 200,
        'status' => $status,
        'response' => $decoded ?? $response
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($status === 200 ? 0 : 1);
}

echo "HTTP Status: {$status}\n";
if ($status !== 200) {
    echo "Response:\n";
    print_r($decoded ?? $response);
    exit(1);
}

$diag = $decoded['diagnostics'] ?? [];
echo "--- GEO MAP HTTP PROBE ---\n";
echo "URL: {$url}\n";
echo "Motivo Zero: " . ($diag['motivo_zero'] ?? 'N/A') . "\n";
echo "API Rows: " . ($diag['mart_geo_rows_for_period'] ?? 'N/A') . "\n";
echo "Canonical NFs: " . ($diag['canonical_nfs_for_period'] ?? 'N/A') . "\n";
echo "Missing Months: " . implode(', ', $diag['missing_months_in_geo_mart'] ?? []) . "\n";
echo "Layer Payload Count: " . ($diag['layer_payload_count'] ?? 'N/A') . "\n";
echo "Sample Name: " . ($diag['neighborhood_key_sample'] ?? 'N/A') . "\n";
echo "Sample Normalized: " . ($diag['normalized_neighborhood_key_sample'] ?? 'N/A') . "\n";
