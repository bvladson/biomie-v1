<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];
$check = static function (string $id, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
};
$source = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . '/' . $relative);
    return is_string($content) ? $content : '';
};
$methodBody = static function (string $content, string $method): string {
    $needle = 'function ' . $method . '(';
    $start = strpos($content, $needle);
    if ($start === false) {
        return '';
    }
    $open = strpos($content, '{', $start);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    $length = strlen($content);
    for ($i = $open; $i < $length; $i++) {
        if ($content[$i] === '{') {
            $depth++;
        } elseif ($content[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $open + 1, $i - $open - 1);
            }
        }
    }
    return '';
};

$helper = $source('app/Support/CommercialMartScope.php');
$check(
    'central_scope_combines_tenant_and_channel',
    str_contains($helper, 'TenantContext::sqlFilter')
        && str_contains($helper, 'BiVisibilityScope::getCondition')
        && str_contains($helper, "return '1 = 0'"),
    'CommercialMartScope combina tenant + canal e falha fechado.',
);

$repository = $source('app/DataPlatform/Repository/GeoReadRepository.php');
foreach (['geoWhere', 'territoryMartWhere'] as $method) {
    $body = $methodBody($repository, $method);
    $check(
        'repository_' . $method . '_channel_scoped',
        str_contains($body, "CommercialMartScope::condition(\$this->pdo, 'mart_compradores_por_geo'"),
        "GeoReadRepository::{$method} aplica o escopo central.",
    );
}
$visibilityBody = $methodBody($repository, 'visibilityScope');
$check(
    'repository_visibility_cache_cannot_bypass_channel',
    str_contains($visibilityBody, 'BiVisibilityScope::getCondition')
        && !str_contains($visibilityBody, 'array_key_exists($table, $this->tableColumnsCache)'),
    'O cache local de colunas nao pode retornar visibilidade sem business_channel.',
);

$activeReaders = [
    'api/client_profile.php' => 1,
    'api/geo_area_selection.php' => 1,
    'api/geo_compare_territories.php' => 1,
    'api/geo_composite_sales.php' => 1,
    'api/geo_data_quality.php' => 1,
    'api/geo_opportunities.php' => 1,
    'api/geo_sales_coverage_audit.php' => 1,
    'api/geo_summary.php' => 1,
    'api/geo_territory_detail.php' => 1,
    'api/geo_territory_evolution.php' => 1,
    'api/geo_territory_invoices.php' => 1,
    'api/geo_trace_nf.php' => 1,
    'api/sales_by_neighborhood.php' => 3,
    'api/top_buyers_by_geo.php' => 2,
    'app/Geo/GeoTerritoryAlertsService.php' => 1,
    'app/Modules/Sales/Infrastructure/Geo360ReadRepository.php' => 6,
    'app/Modules/Customers/Application/CustomerDetail/CustomerDetailBuilder.php' => 1,
    'app/Modules/Customers/Infrastructure/CustomerDimensionRepository.php' => 1,
    'app/Support/GeoTraceDiagnostics.php' => 4,
];

foreach ($activeReaders as $relative => $minimum) {
    $content = $source($relative);
    $count = substr_count($content, 'CommercialMartScope::condition');
    $check(
        'active_reader_' . preg_replace('/[^a-z0-9]+/i', '_', $relative),
        $content !== '' && $count >= $minimum,
        "{$relative} usa escopo central em {$count}/{$minimum} caminho(s) esperado(s).",
    );
}

$layerBuilder = $source('app/Geo/GeoSalesLayerBuilder.php');
$scopeBody = $methodBody($layerBuilder, 'buildScopeFilter');
$vendorBody = $methodBody($layerBuilder, 'buildVendorCoverage');
$check(
    'geo_sales_layer_channel_scoped',
    str_contains($scopeBody, 'BiVisibilityScope::getCondition')
        && str_contains($vendorBody, "buildScopeFilter('orc_pd')"),
    'Camadas e cobertura de vendedores aplicam business_channel.',
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => count($failed),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
