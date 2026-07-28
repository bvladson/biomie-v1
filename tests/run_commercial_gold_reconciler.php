<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CommercialGoldReconciler;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$invoiceSummary = [
    'billed_value' => 300.00,
    'billed_orders' => 3,
    'merchandise_total' => 330.00,
    'discounts_total' => 30.00,
    'active_vendors' => 2,
    'active_clients' => 3,
];
$commercialSummary = [
    'billed_value' => 300.00,
    'billed_orders' => 3,
    'authorized_value' => 80.00,
    'authorized_orders' => 1,
    'cancelled_value' => 20.00,
    'cancelled_orders' => 1,
];
$dailyRows = [
    ['dia' => '2026-07-01', 'status_comercial' => 'faturado', 'total' => 100.00, 'pedidos' => 1],
    ['dia' => '2026-07-02', 'status_comercial' => 'faturado', 'total' => 200.00, 'pedidos' => 2],
    ['dia' => '2026-07-02', 'status_comercial' => 'autorizado', 'total' => 80.00, 'pedidos' => 1],
    ['dia' => '2026-07-03', 'status_comercial' => 'cancelado', 'total' => 20.00, 'pedidos' => 1],
];
$vendorRows = [
    ['codigo_vendedor_omie' => '10', 'nome_vendedor' => 'ANA', 'status_comercial' => 'faturado', 'total' => 200.00, 'pedidos' => 2],
    ['codigo_vendedor_omie' => '20', 'nome_vendedor' => 'BRUNO', 'status_comercial' => 'faturado', 'total' => 100.00, 'pedidos' => 1],
    ['codigo_vendedor_omie' => '10', 'nome_vendedor' => 'ANA', 'status_comercial' => 'autorizado', 'total' => 80.00, 'pedidos' => 1],
    ['codigo_vendedor_omie' => '20', 'nome_vendedor' => 'BRUNO', 'status_comercial' => 'cancelado', 'total' => 20.00, 'pedidos' => 1],
];

$canonical = [
    'invoice_summary' => $invoiceSummary,
    'commercial_summary' => $commercialSummary,
    'daily_rows' => $dailyRows,
    'vendor_rows' => $vendorRows,
];

// Ordering differences and sub-cent rounding remain compatible.
$gold = [
    'invoice_summary' => $invoiceSummary + ['ignored' => 1],
    'commercial_summary' => $commercialSummary,
    'daily_rows' => array_reverse($dailyRows),
    'vendor_rows' => array_reverse($vendorRows),
];
$gold['invoice_summary']['billed_value'] = 300.009;

$checks = [];
$assert = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$matched = CommercialGoldReconciler::compareBundles($canonical, $gold);
$assert('reconciled_bundle_selects_gold',
    $matched['reconciled'] === true
        && $matched['selected_source'] === 'gold'
        && $matched['fallback_used'] === false
        && $matched['reason_code'] === null,
    $matched,
);

$dailyMismatch = $gold;
$dailyMismatch['daily_rows'][0]['total'] = 199.00;
$dailyMismatchResult = CommercialGoldReconciler::compareBundles($canonical, $dailyMismatch);
$assert('daily_distribution_mismatch_falls_back_all_core',
    $dailyMismatchResult['reconciled'] === false
        && $dailyMismatchResult['selected_source'] === 'canonical'
        && $dailyMismatchResult['fallback_used'] === true
        && $dailyMismatchResult['reason_code'] === 'daily_rows_mismatch',
    $dailyMismatchResult,
);

$vendorMismatch = $gold;
$vendorMismatch['vendor_rows'][0]['total'] = 19.00;
$vendorMismatchResult = CommercialGoldReconciler::compareBundles($canonical, $vendorMismatch);
$assert('vendor_distribution_mismatch_falls_back_all_core',
    $vendorMismatchResult['reconciled'] === false
        && $vendorMismatchResult['selected_source'] === 'canonical'
        && $vendorMismatchResult['reason_code'] === 'vendor_rows_mismatch',
    $vendorMismatchResult,
);

$summaryMismatch = $gold;
$summaryMismatch['invoice_summary']['billed_orders'] = 2;
$summaryMismatchResult = CommercialGoldReconciler::compareBundles($canonical, $summaryMismatch);
$assert('summary_count_mismatch_falls_back_all_core',
    $summaryMismatchResult['reconciled'] === false
        && $summaryMismatchResult['selected_source'] === 'canonical'
        && $summaryMismatchResult['reason_code'] === 'invoice_billed_orders_mismatch',
    $summaryMismatchResult,
);

$overviewBuilder = (string) file_get_contents(
    dirname(__DIR__) . '/app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php',
);
$overviewCoordinator = (string) file_get_contents(
    dirname(__DIR__) . '/app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php',
);
$assert('live_overview_consumes_one_selected_core_bundle',
    str_contains($overviewBuilder, '$selectedCore[\'invoice_summary\']')
        && str_contains($overviewBuilder, '$selectedCore[\'vendor_rows\']')
        && str_contains($overviewBuilder, '$selectedCore[\'daily_rows\']')
        && str_contains($overviewBuilder, 'summarizeAggregatedRowsByVendor')
        && !str_contains($overviewBuilder, '$useGoldCore ? $goldMart->dailyByStatusCommercial'),
);
$assert('monthly_gold_and_partial_canonical_guard_present',
    str_contains($overviewBuilder, '$useGoldCore && $canUseMonthlyMarts')
        && str_contains($overviewBuilder, '$canonical->topProductsCommercial($start, $end, 12)')
        && str_contains($overviewBuilder, '$canonical->topClientsCommercial($start, $end, 12)'),
);
$assert('gold_source_of_truth_survives_overview_enrichment',
    str_contains($overviewBuilder, "'gold_live_reconciled'")
        && str_contains($overviewCoordinator, '$payload[\'meta\'][\'commercial_core_source\']')
        && str_contains($overviewCoordinator, "['gold_live_reconciled', 'canonical_live']"),
);

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => count($failed),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
