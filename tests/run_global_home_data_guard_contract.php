<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$guard = file_get_contents($root . '/app/Support/GlobalHomeDataGuard.php') ?: '';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok];
};

$check(
    'admin ignora a guarda global',
    str_contains($guard, 'if (Auth::isAdmin())')
        && str_contains($guard, "public const ERROR_CODE = 'global_home_scope_restricted'")
);
$check(
    'perfil self-only e bloqueado antes de consultar escopo',
    str_contains($guard, "!Auth::hasCapability('sales.quotes.followup.view')")
        && str_contains($guard, "Auth::hasCapability('sales.quotes.followup.self')")
        && strpos($guard, '$sellerSelfOnly') < strpos($guard, 'allowedSellerIds(')
);
$check(
    'somente SellerVisibilityScope all libera fonte global',
    str_contains($guard, 'new SellerVisibilityScope(')
        && str_contains($guard, "Database::isConfigured(\$config, 'flight_plan_db')")
        && str_contains($guard, '->allowedSellerIds($companyId, $userId)')
        && str_contains($guard, 'if ($allowedSellerIds !== null)')
);
$check(
    'negativa usa resposta 403 com codigo estavel',
    str_contains($guard, "'code' => self::ERROR_CODE")
        && str_contains($guard, '], 403);')
);

$endpoints = [
    'api/overview.php' => [
        "Auth::requireCapability('sales.view', true);",
        '$request = OverviewRequest::fromGlobals();',
    ],
    'api/vendors.php' => [
        "Auth::requireCapability('sales.view', true);",
        '$builder = new VendorPayloadBuilder(',
    ],
    'api/dashboard_boot.php' => [
        "Auth::requireCapability('sales.view', true);",
        '$range = Http::resolveDateRange();',
    ],
    'api/commercial_health.php' => [
        "\\BIomie\\Auth::requireAnyCapability(['jobs.view', 'sales.view', 'finance.view'], true);",
        '$range = Http::resolveDateRangeOrCompetencia();',
    ],
    'api/geo_summary.php' => [
        "Auth::requireModule('geo', true);",
        '$useReadModel = FeatureFlags::geoReadModelV2(',
    ],
    'api/sales_by_city.php' => [
        "Auth::requireModule('geo', true);",
        '$range = Http::resolveDateRangeOrCompetencia();',
    ],
    'api/sales_by_neighborhood.php' => [
        "Auth::requireModule('geo', true);",
        '$useReadModel = FeatureFlags::geoReadModelV2(',
    ],
    'api/company_cnae_ranking.php' => [
        "Auth::requireModule('geo', true);",
        '$startedAt = microtime(true);',
    ],
];

foreach ($endpoints as $path => [$authMarker, $workMarker]) {
    $source = file_get_contents($root . '/' . $path) ?: '';
    $authPosition = strpos($source, $authMarker);
    $guardPosition = strpos($source, 'GlobalHomeDataGuard::requireUnrestricted();');
    $workPosition = strpos($source, $workMarker);

    $check(
        $path . ' aplica a guarda apos auth e antes da leitura global',
        str_contains($source, 'use BIomie\\Support\\GlobalHomeDataGuard;')
            && $authPosition !== false
            && $guardPosition !== false
            && $workPosition !== false
            && $authPosition < $guardPosition
            && $guardPosition < $workPosition
            && substr_count($source, 'GlobalHomeDataGuard::requireUnrestricted();') === 1
    );
}

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
