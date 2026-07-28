<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Http;
use BIomie\Observability\EndpointMetricsCollector;
use BIomie\Support\PerformanceTimer;

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

if (PHP_SAPI === 'cli-server' && ($_GET['fixture'] ?? '') === '1') {
    $GLOBALS['biomie_performance_timer'] = PerformanceTimer::start('tests.http_etag');
    Http::jsonWithEtag([
        'ok' => true,
        'value' => 'stable',
    ], 300);
}

$root = dirname(__DIR__);
$httpSource = (string) file_get_contents($root . '/app/Http.php');
$endpointSource = (string) file_get_contents($root . '/api/board_consolidated.php');
$collectorSource = (string) file_get_contents($root . '/app/Observability/EndpointMetricsCollector.php');

$matcher = new ReflectionMethod(Http::class, 'ifNoneMatchMatches');
$etag = '"0123456789abcdef0123456789abcdef"';

$collectorMetrics = [];
$metricsPath = tempnam(sys_get_temp_dir(), 'biomie-http-etag-');
if (is_string($metricsPath)) {
    $rows = [
        [
            'ep' => 'etag_fixture.php',
            'm' => 'GET',
            'sc' => 200,
            'ms' => 12,
            'rt' => [
                'counters' => [
                    'etag_full_responses' => 1,
                    'api_lightweight_bootstrap' => 1,
                ],
                'tags' => [
                    'response_bytes' => 1660,
                    'api_bootstrap_profile' => 'board-read',
                ],
                'events' => [],
            ],
            'src' => 'server',
            'ts' => date(DATE_ATOM),
        ],
        [
            'ep' => 'etag_fixture.php',
            'm' => 'GET',
            'sc' => 304,
            'ms' => 5,
            'rt' => [
                'counters' => [
                    'etag_conditional_requests' => 1,
                    'etag_not_modified_responses' => 1,
                    'api_lightweight_bootstrap' => 1,
                ],
                'tags' => [
                    'response_bytes' => 0,
                    'api_bootstrap_profile' => 'board-read',
                ],
                'events' => [],
            ],
            'src' => 'server',
            'ts' => date(DATE_ATOM),
        ],
    ];
    file_put_contents(
        $metricsPath,
        implode("\n", array_map(
            static fn (array $row): string => (string) json_encode($row),
            $rows
        )) . "\n"
    );
    $summary = (new EndpointMetricsCollector(
        $metricsPath,
        $metricsPath . '.browser-missing'
    ))->summarize(1);
    $collectorMetrics = is_array($summary[0] ?? null) ? $summary[0] : [];
    @unlink($metricsPath);
}

$checks = [
    'exact_validator_matches' => $matcher->invoke(null, $etag, $etag) === true,
    'weak_validator_matches' => $matcher->invoke(null, 'W/' . $etag, $etag) === true,
    'validator_list_matches' => $matcher->invoke(null, '"other", W/' . $etag, $etag) === true,
    'wildcard_matches' => $matcher->invoke(null, '*', $etag) === true,
    'different_validator_does_not_match' => $matcher->invoke(null, '"other"', $etag) === false,
    'empty_validator_does_not_match' => $matcher->invoke(null, '', $etag) === false,
    'authenticated_response_remains_no_store' =>
        str_contains(
            $httpSource,
            "header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0')"
        ),
    'response_context_is_captured_for_200_and_304' =>
        str_contains($httpSource, 'self::captureResponseContext($payload, $status);')
        && str_contains($httpSource, "RequestPerformanceContext::setTag('response_bytes', \$responseBytes);"),
    'conditional_outcomes_are_counted' =>
        str_contains($httpSource, "RequestPerformanceContext::increment('etag_conditional_requests');")
        && str_contains($httpSource, "RequestPerformanceContext::increment('etag_not_modified_responses');"),
    'performance_headers_are_emitted' =>
        str_contains($httpSource, "\$headers['X-BIomie-Response-Time-Ms']")
        && str_contains($httpSource, "\$headers['Server-Timing']"),
    'etag_metrics_are_exposed_by_endpoint_collector' =>
        str_contains($collectorSource, "'etag_conditional_requests'")
        && str_contains($collectorSource, "'etag_not_modified_responses'")
        && str_contains($collectorSource, "'avg_response_bytes'")
        && str_contains($collectorSource, "'bootstrap_profile_counts'"),
    'etag_metrics_are_aggregated_correctly' =>
        ($collectorMetrics['etag_conditional_requests'] ?? null) === 1
        && ($collectorMetrics['etag_not_modified_responses'] ?? null) === 1
        && ($collectorMetrics['etag_full_responses'] ?? null) === 1
        && ($collectorMetrics['api_lightweight_bootstrap'] ?? null) === 2
        && ($collectorMetrics['response_bytes_total'] ?? null) === 1660
        && ($collectorMetrics['avg_response_bytes'] ?? null) === 830
        && ($collectorMetrics['bootstrap_profile_counts']['board-read'] ?? null) === 2,
    'endpoint_documents_private_revalidation' =>
        str_contains($endpointSource, 'sem persistir dados autenticados')
        && str_contains($endpointSource, 'Http::jsonWithEtag($payload, 300);'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
