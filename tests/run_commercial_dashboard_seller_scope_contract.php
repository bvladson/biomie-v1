<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/api/commercial_dashboard.php') ?: '';
$vendors = file_get_contents($root . '/api/vendors_list.php') ?: '';
$page = file_get_contents($root . '/index.php') ?: '';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok];
};

$check(
    'dashboard resolve allowedSellerIds empresa por empresa',
    str_contains($endpoint, 'TenantContext::allowedCompanyIds()')
        && str_contains($endpoint, '->allowedSellerIds($scopeCompanyId, $userId)')
        && str_contains($endpoint, '$sellerScopesByCompany[$scopeCompanyId]')
);
$check(
    'dashboard nao depende de sqlFilter novo',
    !str_contains($endpoint, '$sellerVisibility->sqlFilter(')
);
$check(
    'NF autorizada usa predicado multiempresa seguro',
    str_contains($endpoint, 'VendorScopeFilter::conditionForContext(')
        && str_contains($endpoint, "'nf',")
        && str_contains($endpoint, '$companyIds,')
);
$check(
    'carteira usa o mesmo predicado multiempresa seguro',
    str_contains($endpoint, "'p',")
        && str_contains($endpoint, '$pdParams,')
);
$check(
    'vendor_ids restringe NF e carteira com compatibilidade escalar',
    str_contains($endpoint, 'VendorScopeFilter::requestedIds()')
        && str_contains($endpoint, "'requested_vendor_ids' => \$fVendorIds")
        && str_contains($endpoint, "'vendor_id' => \$fVendorId")
);
$check(
    'agenda usa intersecao autorizada por empresa',
    str_contains($endpoint, 'foreach ($companyIds as $cidQuotes)')
        && str_contains($endpoint, '$sellerScopesByCompany[$cidQuotes]')
        && str_contains($endpoint, 'array_intersect(')
        && str_contains($endpoint, "\$quotesFilters['seller_ids'] = \$quotesScope")
);
$check(
    'agenda desabilitada numa empresa nao derruba as demais',
    str_contains($endpoint, "if (!\$agendaAvailability['enabled'])")
        && str_contains($endpoint, 'continue;')
        && str_contains($endpoint, '$quotesValue +=')
);
$check(
    'metas sao lidas e autorizadas por company_id',
    str_contains($endpoint, 'foreach ($companyIds as $targetCompanyId)')
        && str_contains($endpoint, 'tenantCompanyId: $targetCompanyId')
        && str_contains($endpoint, '$sellerScopesByCompany[$targetCompanyId]')
);
$check(
    'perfil self exige vinculo no dashboard',
    str_contains($endpoint, "Auth::hasCapability('sales.quotes.followup.self')")
        && str_contains($endpoint, 'new SellerBindingResolver($pdo)')
        && str_contains($endpoint, '->sellerIdsFor($userId, $scopeCompanyId)')
);
$check(
    'perfil self exige vinculo no roster',
    str_contains($vendors, "Auth::hasCapability('sales.quotes.followup.self')")
        && str_contains($vendors, 'new SellerBindingResolver($pdo)')
        && str_contains($vendors, '->sellerIdsFor($userId, $companyId)')
);
$check(
    'KPIs de agenda respeitam disponibilidade da empresa',
    str_contains($endpoint, 'QuoteAgendaAvailability::forCompany(')
        && str_contains($endpoint, "\$agendaAvailability['enabled']")
        && str_contains($endpoint, 'Auth::hasAnyCapability([')
);
$check(
    'resposta publica escopo e KPIs operacionais',
    str_contains($endpoint, "'seller_scope' => [")
        && str_contains($endpoint, "'quotes_overdue'")
        && str_contains($endpoint, "'cancelled_value'")
        && str_contains($endpoint, "'target' => \$targetSummary")
);
$check(
    'lista consolidada nao vaza roster e agrupa codigo so depois do escopo',
    str_contains($vendors, 'TenantContext::allowedCompanyIds()')
        && str_contains($vendors, '->allowedSellerIds($companyId, $userId)')
        && str_contains($vendors, 'v.codigo_omie IN (')
        && str_contains($vendors, '$vendorGroups[$groupKey]')
        && str_contains($vendors, "'company_ids'")
        && !str_contains($vendors, '->sqlFilter(')
);
$check(
    'bootstrap global e bloqueado para escopo restrito',
    str_contains($page, "\$sellerScope === null")
        && str_contains($page, "Auth::hasCapability('sales.quotes.followup.self')")
        && str_contains($page, 'new SellerVisibilityScope($pdo)')
);

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
