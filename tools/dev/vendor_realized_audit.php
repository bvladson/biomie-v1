<?php

declare(strict_types=1);

require __DIR__ . '/../../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Logger;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver;
use BIomie\Modules\Sales\Application\Vendors\VendorPayloadBuilder;
use BIomie\Modules\Sales\Application\Vendors\VendorRequest;
use BIomie\FlightPlanV2Service;
use BIomie\Modules\Sales\Application\Overview\CanonicalOverviewPayloadBuilder;
use BIomie\SalesAnalyticsService;

$config = new Config();
$logger = new Logger(__DIR__ . '/../../storage/logs/biomie.log');

// Mock auth for CLI
if (php_sapi_name() === 'cli') {
    $_SESSION['biomie_user'] = ['id' => 1, 'role' => 'admin', 'capabilities' => ['sales.view']];
}

$shortopts = '';
$longopts = [
    'start:',
    'end:',
    'json',
];
$options = getopt($shortopts, $longopts);

$start = $options['start'] ?? date('Y-m-01');
$end = $options['end'] ?? date('Y-m-t');
$isJson = isset($options['json']);

try {
    $pdo = Database::connect($config, 'db');
    $canonical = new CanonicalRepository($pdo, $logger);
    $vendorMap = $canonical->getVendorMap();
    $resolver = new VendorIdentityResolver($vendorMap);

    // 1. vendors.php (Merc. Faturadas)
    $analytics = new SalesAnalyticsService($config, new \BIomie\Cache(__DIR__ . '/../../storage/cache'), $logger, null, null);
    $vendorBuilder = new VendorPayloadBuilder($config, $logger, $analytics);
    $_GET['start'] = $start;
    $_GET['end'] = $end;
    $_GET['competencia'] = null; // Prevent warning
    $request = VendorRequest::fromGlobals();
    $vendorsPayload = $vendorBuilder->build($request);
    $vendorsPageMap = [];
    foreach ($vendorsPayload['vendors'] ?? [] as $v) {
        $id = $resolver->resolve($v['vendor_id'] ?? '')['vendor_id'];
        $vendorsPageMap[$id] = $v['merchandise_total'] ?? 0.0;
    }

    // 2. flight_plan.php (Realizado)
    $fpService = new FlightPlanV2Service($canonical, null, 0.33);
    $fpPayload = $fpService->build($start, $end, null);
    $fpMap = [];
    foreach ($fpPayload['vendors'] ?? [] as $v) {
        $id = $resolver->resolve($v['vendor_id'] ?? '')['vendor_id'];
        $fpMap[$id] = $v['realized_period'] ?? 0.0;
    }

    // 3. overview.php (Ranking Comercial)
    $overviewBuilder = new CanonicalOverviewPayloadBuilder();
    $overviewPayload = $overviewBuilder->build($pdo, $logger, $canonical, null, null, ['start' => $start, 'end' => $end, 'label' => ''], '', 0.33);
    $overviewMap = [];
    foreach ($overviewPayload['vendor_ranking'] ?? [] as $v) {
        $id = $resolver->resolve($v['vendor_id'] ?? '')['vendor_id'];
        $overviewMap[$id] = $v['revenue'] ?? 0.0;
    }

    // 4. Raw canonical invoices / Official via VendorRealizedService
    require_once __DIR__ . '/../../app/Modules/Sales/Application/Vendors/VendorRealizedService.php';
    $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
    $officialPayload = $realizedService->summarizeByVendor($start, $end);
    $officialMap = [];
    foreach ($officialPayload as $v) {
        $officialMap[$v['vendor_id']] = $v['realized'];
    }

    $allVendorIds = array_unique(array_merge(array_keys($vendorsPageMap), array_keys($fpMap), array_keys($overviewMap), array_keys($officialMap)));

    $vendorsResult = [];
    $totalOfficial = 0.0;

    foreach ($allVendorIds as $vId) {
        $vPage = round((float)($vendorsPageMap[$vId] ?? 0), 2);
        $fp = round((float)($fpMap[$vId] ?? 0), 2);
        $ov = round((float)($overviewMap[$vId] ?? 0), 2);
        $official = round((float)($officialMap[$vId] ?? 0), 2);
        $totalOfficial += $official;

        $deltaFp = abs($official - $fp);
        $deltaOv = abs($official - $ov);
        $deltaVPage = abs($official - $vPage);

        $hasDivergence = $deltaFp > 0.01 || $deltaOv > 0.01 || $deltaVPage > 0.01;

        if ($hasDivergence || $official > 0) {
            $name = $vendorMap[$vId]['nome'] ?? $vId;
            if ($vId === '__SEM_VENDEDOR__') $name = 'Sem vendedor';

            $vendorsResult[] = [
                'vendor_name' => $name,
                'vendor_id' => $vId,
                'official' => $official,
                'vendors_page' => $vPage,
                'flight_plan' => $fp,
                'overview' => $ov,
                'delta_flight_plan' => $deltaFp,
                'delta_overview' => $deltaOv,
                'delta_vendors_page' => $deltaVPage,
                'probable_reason' => $hasDivergence ? 'field_or_source_mismatch' : null,
                'diagnostics' => [
                    'official_realized' => $official,
                    'vendor_page_used_merchandise' => $vPage !== $official && $vPage > 0,
                ]
            ];
        }
    }

    $hasAnyDivergence = count(array_filter($vendorsResult, fn($v) => $v['probable_reason'] !== null)) > 0;

    $output = [
        'ok' => !$hasAnyDivergence,
        'period' => [
            'start' => $start,
            'end' => $end,
        ],
        'total_official' => $totalOfficial,
        'vendors' => $vendorsResult,
    ];

    if ($isJson) {
        echo json_encode($output, JSON_PRETTY_PRINT);
    } else {
        print_r($output);
    }

    exit($hasAnyDivergence ? 1 : 0);

} catch (Throwable $e) {
    if ($isJson) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
    exit(2);
}
