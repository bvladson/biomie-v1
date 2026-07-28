<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = (string) file_get_contents($root . '/api/board_consolidated.php');
$bootstrap = (string) file_get_contents($root . '/api/bootstrap.php');

$profileDefinition = strpos(
    $endpoint,
    "define('BIOMIE_API_BOOTSTRAP_PROFILE', 'board-read');"
);
$bootstrapRequire = strpos($endpoint, "require __DIR__ . '/bootstrap.php';");
$lightweightReturn = strpos($bootstrap, "if (\$apiBootstrapProfile === 'board-read')");
$bronzeBoot = strpos($bootstrap, 'BronzeRepository::boot(');
$omieBoot = strpos($bootstrap, 'OmieClientFactory::boot(');
$analyticsBoot = strpos($bootstrap, 'new SalesAnalyticsService(');

$requiredBeforeReturn = [
    'error_boundary' => strpos($bootstrap, 'ErrorBoundary::registerApiHandlers('),
    'endpoint_metrics' => strpos($bootstrap, 'EndpointMetricsCollector::registerShutdownHook('),
    'telemetry' => strpos($bootstrap, 'Telemetry::boot('),
    'performance_timer' => strpos($bootstrap, 'PerformanceTimer::start('),
    'auth_boot' => strpos($bootstrap, 'Auth::boot('),
    'require_login' => strpos($bootstrap, 'Auth::requireLogin(true);'),
    'tenant_context' => strpos($bootstrap, "TenantContext::boot(Database::connect(\$config, 'db'))"),
    'csrf' => strpos($bootstrap, 'Http::enforceCsrf($logger);'),
    'session_unlock' => strpos($bootstrap, 'session_write_close();'),
];

$allRequiredBeforeReturn = $lightweightReturn !== false;
foreach ($requiredBeforeReturn as $position) {
    $allRequiredBeforeReturn = $allRequiredBeforeReturn
        && $position !== false
        && $position < $lightweightReturn;
}

$checks = [
    'profile_is_defined_before_bootstrap' =>
        $profileDefinition !== false
        && $bootstrapRequire !== false
        && $profileDefinition < $bootstrapRequire,
    'unknown_profiles_fall_back_to_full' =>
        str_contains($bootstrap, "if (\$apiBootstrapProfile !== 'board-read')")
        && str_contains($bootstrap, "\$apiBootstrapProfile = 'full';"),
    'security_and_telemetry_run_before_lightweight_return' => $allRequiredBeforeReturn,
    'unused_commercial_stack_runs_only_after_lightweight_return' =>
        $lightweightReturn !== false
        && $bronzeBoot !== false
        && $omieBoot !== false
        && $analyticsBoot !== false
        && $lightweightReturn < $bronzeBoot
        && $lightweightReturn < $omieBoot
        && $lightweightReturn < $analyticsBoot,
    'profile_is_present_in_runtime_telemetry' =>
        str_contains(
            $bootstrap,
            "RequestPerformanceContext::setTag('api_bootstrap_profile', \$apiBootstrapProfile);"
        )
        && str_contains($bootstrap, "'bootstrap_profile' => \$apiBootstrapProfile"),
    'lightweight_usage_is_counted' =>
        str_contains(
            $bootstrap,
            "RequestPerformanceContext::increment('api_lightweight_bootstrap');"
        ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
