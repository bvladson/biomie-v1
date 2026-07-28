<?php

declare(strict_types=1);

/**
 * http_probe.php — Verifica o contrato HTTP real de endpoints da aplicação.
 *
 * Testa: http_status, redirects, content_type, cache headers,
 * JSON validity, avisos PHP no body, detecção de redirect para login.
 *
 * Uso:
 *   php tools/dev/http_probe.php [--url=URL] [--endpoint=overview|flight_plan|...]
 *                                [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
 *                                [--cookie=PHPSESSID=xxx] [--json] [--all]
 */

$options  = getopt('', ['url:', 'endpoint:', 'start:', 'end:', 'cookie:', 'json', 'all', 'base-url:']);
$isJson   = isset($options['json']);
$runAll   = isset($options['all']);
$start    = (string) ($options['start'] ?? date('Y-m-01'));
$end      = (string) ($options['end']   ?? date('Y-m-t'));
$cookie   = (string) ($options['cookie'] ?? '');
$baseUrl  = rtrim((string) ($options['base-url'] ?? getenv('BIOMIE_BASE_URL') ?: 'http://localhost'), '/');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERRO: cURL nao disponivel. Instale a extensao php-curl.\n");
    exit(1);
}

$requestId = bin2hex(random_bytes(8));

// ── Endpoints conhecidos ──────────────────────────────────────────────────────

$knownEndpoints = [
    'overview'     => "/api/overview.php?start={$start}&end={$end}",
    'flight_plan'  => "/api/flight_plan.php?start={$start}&end={$end}",
    'geo_summary'  => "/api/geo_summary.php?start={$start}&end={$end}",
    'vendor_detail'=> "/api/vendor_detail.php?start={$start}&end={$end}",
    'omie_status'  => "/api/omie_status.php",
    'sync_status'  => "/api/omie_status.php",
];

// ── Determine targets ─────────────────────────────────────────────────────────

$targets = [];

if (isset($options['url'])) {
    $targets[] = ['name' => 'custom', 'url' => (string) $options['url']];
} elseif (isset($options['endpoint'])) {
    $ep = (string) $options['endpoint'];
    if (!isset($knownEndpoints[$ep])) {
        fwrite(STDERR, "Endpoint desconhecido: {$ep}. Opcoes: " . implode(', ', array_keys($knownEndpoints)) . "\n");
        exit(1);
    }
    $targets[] = ['name' => $ep, 'url' => $baseUrl . $knownEndpoints[$ep]];
} elseif ($runAll) {
    foreach ($knownEndpoints as $name => $path) {
        $targets[] = ['name' => $name, 'url' => $baseUrl . $path];
    }
} else {
    // Default: probe overview
    $targets[] = ['name' => 'overview', 'url' => $baseUrl . $knownEndpoints['overview']];
}

// ── Probe function ─────────────────────────────────────────────────────────────

/**
 * @return array<string,mixed>
 */
function probeEndpoint(string $name, string $url, string $cookie): array
{
    $result = [
        'name'        => $name,
        'url'         => $url,
        'source'      => 'http_probe',
        'reason_code' => null,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_MAXREDIRS      => 0,
    ]);

    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    $httpHeaders = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $result['status']      = 'connection_failed';
        $result['reason_code'] = 'curl_error: ' . $curlError;
        return $result;
    }

    $headerSize  = (int) $info['header_size'];
    $rawHeaders  = substr((string) $response, 0, $headerSize);
    $body        = substr((string) $response, $headerSize);
    $httpStatus  = (int) $info['http_code'];

    // Parse response headers
    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$hName, $hVal] = explode(':', $line, 2);
            $headers[strtolower(trim($hName))] = trim($hVal);
        }
    }

    $result['http_status']   = $httpStatus;
    $result['content_type']  = $headers['content-type'] ?? null;
    $result['cache_control'] = $headers['cache-control'] ?? null;
    $result['pragma']        = $headers['pragma'] ?? null;
    $result['redirect_location'] = ($httpStatus >= 300 && $httpStatus < 400)
        ? ($headers['location'] ?? null)
        : null;

    // ── Login redirect detection ──────────────────────────────────────────────
    $isLoginRedirect = false;
    if ($httpStatus === 302 || $httpStatus === 301) {
        $loc = strtolower((string) ($headers['location'] ?? ''));
        if (str_contains($loc, 'login') || str_contains($loc, 'index.php')) {
            $isLoginRedirect = true;
        }
    }
    if ($httpStatus === 200) {
        $bodyLower = strtolower(substr($body, 0, 1024));
        if (str_contains($bodyLower, '<form') && str_contains($bodyLower, 'password')) {
            $isLoginRedirect = true;
        }
    }
    $result['is_login_redirect'] = $isLoginRedirect;

    // ── Content-Type check ────────────────────────────────────────────────────
    $contentTypeOk = str_contains((string) ($headers['content-type'] ?? ''), 'application/json');
    $result['content_type_ok'] = $contentTypeOk;

    // ── Cache headers check ───────────────────────────────────────────────────
    $noStore = str_contains(strtolower((string) ($headers['cache-control'] ?? '')), 'no-store');
    $result['cache_no_store'] = $noStore;

    // ── PHP warnings in body ──────────────────────────────────────────────────
    $phpWarningsPatterns = ['<br />', 'Warning:', 'Notice:', 'Deprecated:', 'Fatal error:'];
    $phpWarnings = [];
    foreach ($phpWarningsPatterns as $pattern) {
        if (str_contains($body, $pattern)) {
            $phpWarnings[] = $pattern;
        }
    }
    $result['php_warnings_detected'] = $phpWarnings !== [];
    $result['php_warnings']          = $phpWarnings;

    // ── JSON validity ─────────────────────────────────────────────────────────
    $decoded = json_decode($body, true);
    $jsonOk  = is_array($decoded);
    $result['json_valid'] = $jsonOk;
    $result['json_parse_error'] = $jsonOk ? null : json_last_error_msg();

    if ($jsonOk) {
        $result['payload_ok']     = (bool) ($decoded['ok'] ?? false);
        $result['payload_source'] = $decoded['source'] ?? $decoded['mode'] ?? null;
        $result['payload_keys']   = array_keys($decoded);

        // Contract checks
        $result['contract'] = [];

        // Check for 'ok' field
        $result['contract']['has_ok_field'] = array_key_exists('ok', $decoded);

        // Check for 'meta' block
        $result['contract']['has_meta']     = is_array($decoded['meta'] ?? null);

        // Check for request_id
        $result['contract']['has_request_id'] = isset($decoded['request_id'])
            || isset($decoded['meta']['request_id'])
            || isset($decoded['diagnostics']['request_id']);

        // Check for expected keys by endpoint type
        $overviewKeys = ['summary', 'vendor_ranking', 'top_clients', 'month_comparison', 'alerts'];
        $missingKeys  = [];
        if (in_array($result['name'], ['overview'], true)) {
            foreach ($overviewKeys as $k) {
                if (!array_key_exists($k, $decoded)) {
                    $missingKeys[] = $k;
                }
            }
        }
        $result['contract']['missing_expected_keys'] = $missingKeys;

        // month_comparison alias check
        if (in_array($result['name'], ['overview'], true)) {
            $mcKey = 'none';
            foreach (['month_comparison', 'monthly_comparison', 'monthlyComparison'] as $alias) {
                if (array_key_exists($alias, $decoded)) {
                    $mcKey = $alias;
                    break;
                }
            }
            $result['contract']['month_comparison_key_used'] = $mcKey;
            $result['contract']['month_comparison_is_array'] = is_array($decoded[$mcKey] ?? null);
            $result['contract']['month_comparison_count']    = is_array($decoded[$mcKey] ?? null)
                ? count($decoded[$mcKey])
                : 0;
        }

        // alerts format check
        if (array_key_exists('alerts', $decoded) && is_array($decoded['alerts'])) {
            $badAlerts = 0;
            foreach ($decoded['alerts'] as $alert) {
                if (!is_array($alert) || !isset($alert['level'], $alert['title'], $alert['message'])) {
                    $badAlerts++;
                }
            }
            $result['contract']['alerts_count']        = count($decoded['alerts']);
            $result['contract']['alerts_format_valid'] = $badAlerts === 0;
            $result['contract']['alerts_bad_count']    = $badAlerts;
        }

        // source_of_truth
        if (is_array($decoded['meta'] ?? null)) {
            $result['contract']['source_of_truth'] = $decoded['meta']['source_of_truth'] ?? null;
            $result['contract']['duration_ms']     = $decoded['meta']['duration_ms'] ?? null;
        }
    }

    // ── Body preview ──────────────────────────────────────────────────────────
    $bodyPreview = substr($body, 0, 300);
    $result['body_preview'] = $bodyPreview;

    // ── Overall status ────────────────────────────────────────────────────────
    if ($httpStatus !== 200) {
        $result['reason_code'] = "http_{$httpStatus}";
    } elseif ($isLoginRedirect) {
        $result['reason_code'] = 'login_redirect_detected';
    } elseif (!$contentTypeOk) {
        $result['reason_code'] = 'wrong_content_type';
    } elseif (!$jsonOk) {
        $result['reason_code'] = 'invalid_json';
    } elseif ($phpWarnings !== []) {
        $result['reason_code'] = 'php_warnings_in_body';
    } elseif (!empty($result['contract']['missing_expected_keys'] ?? [])) {
        $result['reason_code'] = 'missing_contract_keys: ' . implode(',', $result['contract']['missing_expected_keys']);
    }

    return $result;
}

// ── Run probes ─────────────────────────────────────────────────────────────────

$probeResults = [];
foreach ($targets as $target) {
    $probeResults[] = probeEndpoint($target['name'], $target['url'], $cookie);
}

$output = [
    'request_id'   => $requestId,
    'source'       => 'http_probe',
    'base_url'     => $baseUrl,
    'period'       => ['start' => $start, 'end' => $end],
    'cookie_set'   => $cookie !== '',
    'probes'       => $probeResults,
    'summary' => [
        'total'   => count($probeResults),
        'ok'      => count(array_filter($probeResults, static fn (array $r): bool => ($r['reason_code'] ?? null) === null)),
        'failed'  => count(array_filter($probeResults, static fn (array $r): bool => ($r['reason_code'] ?? null) !== null)),
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// ── Text output ────────────────────────────────────────────────────────────────

$sym = static fn (bool $ok): string => $ok ? '[OK]' : '[FAIL]';

echo "\n=== HTTP Probe ===\n";
echo "Request ID : {$requestId}\n";
echo "Base URL   : {$baseUrl}\n";
echo "Periodo    : {$start} ate {$end}\n";
echo "Cookie     : " . ($cookie !== '' ? 'definido' : 'nenhum (sem autenticacao)') . "\n\n";

foreach ($probeResults as $r) {
    echo "── {$r['name']} ─────────────────────────────────────\n";
    echo "   URL         : {$r['url']}\n";
    echo "   HTTP Status : " . ($r['http_status'] ?? 'N/A') . "\n";
    echo "   Content-Type: " . ($r['content_type'] ?? '-') . "\n";
    echo "   Cache       : " . ($r['cache_control'] ?? '-') . "\n";
    echo "   " . $sym((bool) ($r['content_type_ok'] ?? false)) . " Content-Type eh JSON\n";
    echo "   " . $sym((bool) ($r['cache_no_store'] ?? false)) . " Cache no-store definido\n";
    echo "   " . $sym((bool) ($r['json_valid'] ?? false)) . " JSON valido\n";
    echo "   " . $sym(!($r['is_login_redirect'] ?? false)) . " Sem redirect para login\n";
    echo "   " . $sym(!($r['php_warnings_detected'] ?? false)) . " Sem warnings PHP no body\n";

    if (isset($r['contract'])) {
        $c = $r['contract'];
        echo "   " . $sym((bool) ($c['has_ok_field'] ?? false)) . " Campo 'ok' presente\n";
        echo "   " . $sym((bool) ($c['has_meta'] ?? false)) . " Bloco 'meta' presente\n";
        if (isset($c['month_comparison_key_used'])) {
            $mcOk = $c['month_comparison_key_used'] === 'month_comparison';
            echo "   " . $sym($mcOk) . " month_comparison usa chave canonica"
                . " (encontrada: '{$c['month_comparison_key_used']}', linhas: {$c['month_comparison_count']})\n";
        }
        if (isset($c['alerts_format_valid'])) {
            echo "   " . $sym((bool) $c['alerts_format_valid']) . " Alerts estruturados corretamente"
                . " ({$c['alerts_count']} alertas)\n";
        }
        if (isset($c['source_of_truth'])) {
            echo "   source_of_truth : {$c['source_of_truth']}\n";
        }
        if (!empty($c['missing_expected_keys'] ?? [])) {
            echo "   MISSING KEYS    : " . implode(', ', $c['missing_expected_keys']) . "\n";
        }
    }

    if (($r['reason_code'] ?? null) !== null) {
        echo "   [reason_code]   : {$r['reason_code']}\n";
    }

    echo "\n";
}

echo "Total: {$output['summary']['total']} | OK: {$output['summary']['ok']} | Falhas: {$output['summary']['failed']}\n";
echo "\nUso: php tools/dev/http_probe.php [--endpoint=overview|flight_plan|...] [--cookie=PHPSESSID=xxx]\n";
echo "     [--url=URL] [--base-url=http://host] [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--all]\n\n";
