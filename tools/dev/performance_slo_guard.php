<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Observability\EndpointMetricsCollector;
use BIomie\PageAssetRegistry;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['hours:', 'min-samples:', 'json', 'pretty', 'enforce']);
$hours = max(1, min(24 * 30, (int) ($opts['hours'] ?? 24)));
$config = new Config();
$minSamples = max(1, (int) ($opts['min-samples'] ?? $config->get('performance.endpoint_slo_min_samples', 10)));
$enforce = isset($opts['enforce']);
$json = isset($opts['json']);
$root = dirname(__DIR__, 2);

$architectureChecks = [
    [
        'id' => 'request_performance_context',
        'ok' => is_file($root . '/app/Observability/RequestPerformanceContext.php'),
        'message' => 'Contexto leve de performance por request.',
    ],
    [
        'id' => 'external_get_guard',
        'ok' => is_file($root . '/app/Observability/ExternalCallGuard.php')
            && str_contains((string) @file_get_contents($root . '/app/OmieClient.php'), 'ExternalCallGuard::beforeNetworkCall'),
        'message' => 'Guard de chamada externa em GET.',
    ],
    [
        'id' => 'endpoint_runtime_log',
        'ok' => str_contains((string) @file_get_contents($root . '/app/Observability/EndpointMetricsCollector.php'), "'rt' => RequestPerformanceContext::snapshot()"),
        'message' => 'Log de endpoint enriquecido com runtime.',
    ],
    [
        'id' => 'authenticated_browser_performance_beacon',
        'ok' => is_file($root . '/api/performance_beacon.php')
            && str_contains((string) @file_get_contents($root . '/assets/js/performance-monitor.js'), 'api/performance_beacon.php')
            && str_contains((string) @file_get_contents($root . '/api/performance_beacon.php'), 'Auth::verifyCsrf'),
        'message' => 'Fallback autenticado de telemetria real do navegador.',
    ],
    [
        'id' => 'orders_shadow_without_activation_flag',
        'ok' => !str_contains(
            performanceFunctionSlice((string) @file_get_contents($root . '/api/orders.php'), 'function buildCanonicalOrdersShadowPayload'),
            "isCanonicalEnabled('orders')"
        ),
        'message' => 'Shadow canonico de pedidos independente da flag oficial.',
    ],
    [
        'id' => 'commercial_help_lazy_loaded',
        'ok' => str_contains((string) @file_get_contents($root . '/app/PageAssetRegistry.php'), 'assets/js/commercial-card-help-loader.js')
            && !str_contains(
                performanceFunctionSlice((string) @file_get_contents($root . '/app/PageAssetRegistry.php'), 'private const BASE_JS'),
                "'assets/js/commercial-card-help.js'"
            ),
        'message' => 'Ajuda comercial pesada fora do caminho critico inicial.',
    ],
    [
        'id' => 'commercial_drilldowns_lazy_loaded',
        'ok' => str_contains((string) @file_get_contents($root . '/assets/js/modules/drilldown-router.js'), 'function ensureFeature(feature)')
            && str_contains((string) @file_get_contents($root . '/app/PageAssetRegistry.php'), 'lazyDrilldownVersion')
            && !performanceHasEagerDrilldownAssets(),
        'message' => 'Detalhamentos comerciais pesados carregados somente no primeiro uso.',
    ],
    [
        'id' => 'geo_lazy_feature_loader',
        'ok' => str_contains((string) @file_get_contents($root . '/app/PageAssetRegistry.php'), 'assets/js/geo-feature-loader.js')
            && str_contains((string) @file_get_contents($root . '/assets/js/geo-feature-loader.js'), 'IntersectionObserver'),
        'message' => 'Mapa e graficos geograficos carregam perto da viewport.',
    ],
    [
        'id' => 'finance_without_legacy_bundle',
        'ok' => str_contains(
            (string) @file_get_contents($root . '/app/PageAssetRegistry.php'),
            'assets/css/finance-legacy-utilities.css'
        ),
        'message' => 'Financeiro usa utilitarios dedicados no lugar do bundle legado.',
    ],
];

$collector = new EndpointMetricsCollector($root . '/storage/logs/endpoint_requests.jsonl');
$endpointRows = [];
$violations = [];
$warnings = [];

foreach ($collector->summarize($hours) as $row) {
    $budget = performanceEndpointBudget((string) ($row['endpoint'] ?? ''));
    if ($budget === null || (string) ($row['method'] ?? '') !== 'GET') {
        continue;
    }

    $instrumented = (int) ($row['instrumented_total'] ?? 0);
    $p95 = (int) ($row['p95_ms'] ?? 0);
    $externalGetViolations = (int) ($row['external_get_violations'] ?? 0);
    $status = 'ok';

    if ($instrumented < $minSamples) {
        $status = 'insufficient_sample';
    } elseif ($p95 > $budget) {
        $status = 'slo_violation';
        $violations[] = sprintf('%s p95=%dms acima do budget=%dms', $row['endpoint'], $p95, $budget);
    }

    if ($externalGetViolations > 0) {
        $warnings[] = sprintf('%s registrou %d chamada(s) externa(s) em GET', $row['endpoint'], $externalGetViolations);
        if ($enforce && $instrumented >= $minSamples) {
            $violations[] = sprintf('%s ainda chama servico externo durante GET', $row['endpoint']);
        }
    }

    $endpointRows[] = $row + [
        'budget_p95_ms' => $budget,
        'status' => $status,
    ];
}

$assets = [];
foreach (PageAssetRegistry::pageKeys() as $pageKey) {
    $paths = array_values(array_unique(array_merge(
        PageAssetRegistry::cssForPage($pageKey),
        PageAssetRegistry::jsHeadForPage($pageKey),
        PageAssetRegistry::jsBodyForPage($pageKey),
    )));
    $bytes = 0;
    foreach ($paths as $path) {
        $size = @filesize($root . '/' . ltrim($path, '/'));
        if ($size !== false) {
            $bytes += $size;
        }
    }
    $assets[] = [
        'page' => $pageKey,
        'files' => count($paths),
        'raw_bytes' => $bytes,
        'raw_kb' => round($bytes / 1024, 1),
    ];
}

$financeAsset = null;
foreach ($assets as $asset) {
    if ($asset['page'] === 'financeiro') {
        $financeAsset = $asset;
        break;
    }
}
$architectureChecks[] = [
    'id' => 'finance_initial_asset_budget',
    'ok' => is_array($financeAsset) && (int) $financeAsset['raw_bytes'] <= 850 * 1024,
    'message' => 'Financeiro respeita o budget inicial bruto de 850 KB.',
];
if ($endpointRows === []) {
    $warnings[] = 'Nenhuma amostra autenticada de endpoint foi coletada na janela; a primeira navegacao real alimentara o beacon.';
}

$failedArchitecture = array_values(array_filter($architectureChecks, static fn (array $check): bool => !$check['ok']));
$ok = $failedArchitecture === [] && (!$enforce || $violations === []);
$payload = [
    'ok' => $ok,
    'generated_at' => date(DATE_ATOM),
    'mode' => $enforce ? 'enforce' : 'observe',
    'hours' => $hours,
    'min_samples' => $minSamples,
    'summary' => [
        'architecture_checks' => count($architectureChecks),
        'architecture_failed' => count($failedArchitecture),
        'endpoints_evaluated' => count($endpointRows),
        'slo_violations' => count($violations),
        'warnings' => count($warnings),
        'pages_measured' => count($assets),
    ],
    'architecture_checks' => $architectureChecks,
    'endpoint_slos' => $endpointRows,
    'asset_baseline' => $assets,
    'violations' => array_values(array_unique($violations)),
    'warnings' => array_values(array_unique($warnings)),
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
}

echo "BIomie performance SLO guard ({$payload['mode']}, {$hours}h)\n";
echo "  architecture: " . (count($architectureChecks) - count($failedArchitecture)) . '/' . count($architectureChecks) . " ok\n";
echo "  endpoints    : " . count($endpointRows) . " avaliados\n";
echo "  violations   : " . count($violations) . "\n";
echo "  warnings     : " . count($warnings) . "\n\n";

foreach ($endpointRows as $row) {
    printf(
        "  %-34s p95=%6dms budget=%4dms samples=%d/%d external_get=%d status=%s\n",
        (string) $row['endpoint'],
        (int) $row['p95_ms'],
        (int) $row['budget_p95_ms'],
        (int) $row['instrumented_total'],
        (int) $row['total'],
        (int) $row['external_get_violations'],
        (string) $row['status'],
    );
}

echo "\nAsset baseline (raw, before HTTP compression):\n";
foreach ($assets as $asset) {
    printf("  %-24s %5d files %8.1f KB\n", $asset['page'], $asset['files'], $asset['raw_kb']);
}

foreach ($payload['violations'] as $message) {
    echo "\n[FAIL] {$message}";
}
foreach ($payload['warnings'] as $message) {
    echo "\n[WARN] {$message}";
}
echo "\n";

exit($ok ? 0 : 1);

function performanceEndpointBudget(string $endpoint): ?int
{
    return match ($endpoint) {
        'overview.php', 'vendors.php', 'orders.php', 'flight_plan.php' => 1000,
        'products.php' => 2000,
        'nf_periodo_table.php' => 1200,
        'finance_overview.php', 'finance_titles.php', 'finance_visual_analytics.php' => 1500,
        'finance_collection_by_vendor.php', 'finance_collection_governance.php' => 2000,
        'geo_summary.php', 'sales_by_neighborhood.php', 'geo_map_payload.php' => 1200,
        'live_stream.php' => null,
        default => null,
    };
}

function performanceFunctionSlice(string $contents, string $needle): string
{
    $position = strpos($contents, $needle);
    return $position === false ? '' : substr($contents, $position, 1200);
}

function performanceHasEagerDrilldownAssets(): bool
{
    $detailFolders = [
        '/vendor-detail/',
        '/order-detail/',
        '/invoice-detail/',
        '/customer-detail/',
        '/product-detail/',
        '/geo-detail/',
    ];

    foreach (PageAssetRegistry::pageKeys() as $pageKey) {
        foreach (PageAssetRegistry::jsBodyForPage($pageKey) as $path) {
            foreach ($detailFolders as $folder) {
                if (str_contains($path, $folder)) {
                    return true;
                }
            }
        }
    }

    return false;
}
