<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\FlightPlanService;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;
use BIomie\WorkingDays;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    $vendorRef = rawurldecode(trim((string) Http::query('vendor_id')));
    // Se vier vazio e tivermos start/end, tudo bem, vamos resolver como __SEM_VENDEDOR__ no resolver
    
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end = $range['end'];
    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $targetsRepository = null;
    $canonical = null;
    $targetsConnection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (Database::isConfigured($config, $targetsConnection)) {
        try {
            $pdo = Database::connect($config, $targetsConnection);
            $targetsRepository = new TargetsRepository($pdo, $logger);

            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (Throwable $exception) {
            $logger->warning('Vendor Detail: conexao MySQL indisponivel, usando fallback.', [
                'message' => $exception->getMessage(),
                'connection' => $targetsConnection,
            ]);
        }
    }

    if ($canonical !== null) {
        $vendorMap = $canonical->getVendorMap();
        require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMap);
        
        $identity = $resolver->resolve($vendorRef);
        $resolvedVendorId = $identity['vendor_id'];
        $resolvedVendorName = $identity['vendor_name'];
        $isGeneric = $identity['is_generic'];

        // Se o bucket não for o genérico, pegamos os dados de meta do Plano de Voo original.
        $vendorTargetInfo = [
            'target_period' => 0.0,
            'realized_period' => 0.0,
            'realizado_vendedor' => 0.0,
            'mercadoria_faturada_vendedor' => 0.0,
            'forecast_period' => 0.0,
            'gap_to_target' => 0.0,
            'attainment_pct' => 0.0,
            'risk_status' => 'SEM_META'
        ];
        
        require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/VendorRealizedService.php';
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
        $realizedComposition = $realizedService->explainVendor($start, $end, $identity);
        
        if (!$isGeneric) {
            $serviceV2 = new FlightPlanV2Service($canonical, $targetsRepository, $saturdayWeight);
            $plan = $serviceV2->build($start, $end, null);
            foreach ($plan['vendors'] ?? [] as $row) {
                if (trim((string)($row['vendor_id'] ?? '')) === $resolvedVendorId) {
                    $vendorTargetInfo = $row;
                    break;
                }
            }
        }

        $allInvoices = array_values(array_filter(
            $canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null),
            static fn(array $row): bool => ($isGeneric 
                ? (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === '' || stripos((string)($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''), 'sem vendedor') !== false)
                : (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === $resolvedVendorId))
        ));

        $allPipeline = array_values(array_filter(
            $canonical->listPipelineOrdersForDisplayCommercial($start, $end, null),
            static fn(array $row): bool => ($isGeneric 
                ? (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === '' || stripos((string)($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''), 'sem vendedor') !== false)
                : (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === $resolvedVendorId))
        ));

        $allCancelled = array_values(array_filter(
            $canonical->listCancelledInvoicesForDisplayCommercial($start, $end),
            static fn(array $row): bool => ($isGeneric 
                ? (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === '' || stripos((string)($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''), 'sem vendedor') !== false)
                : (trim((string)($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? '')) === $resolvedVendorId))
        ));

        $dailySales = buildVendorDetailDailySales($start, $end, $allInvoices);
        
        $grossTotal = 0.0;
        $customerSet = [];
        foreach ($allInvoices as $row) {
            $grossTotal += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
            $cid = (string)($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
            if ($cid !== '') $customerSet[$cid] = true;
        }

        $openTotal = 0.0;
        foreach ($allPipeline as $row) {
            $openTotal += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
            $cid = (string)($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
            if ($cid !== '') $customerSet[$cid] = true;
        }

        $cancelledTotal = 0.0;
        foreach ($allCancelled as $row) {
            $cancelledTotal += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
        }

        $invoicesCount = count($allInvoices);
        
        $topCustomers = [];
        // TODO: Map top customers from $allInvoices
        $mapC = [];
        foreach ($allInvoices as $row) {
            $k = (string)($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? '');
            if ($k === '') continue;
            $mapC[$k] = $mapC[$k] ?? ['id'=>$k, 'label'=>$row['cliente']??$k, 'value'=>0, 'orders'=>0];
            $mapC[$k]['value'] += Support::toFloat($row['merchandise_total'] ?? $row['total'] ?? 0);
            $mapC[$k]['orders']++;
        }
        uasort($mapC, static fn($a,$b)=>$b['value']<=>$a['value']);
        $topCustomers = array_values(array_slice($mapC, 0, 8));
        foreach($topCustomers as &$tc) $tc['value'] = Support::money($tc['value']);

        Http::json([
            'ok' => true,
            'source' => 'canonical',
            'vendor' => [
                'id' => $resolvedVendorId,
                'name' => $resolvedVendorName,
                'aliases' => $identity['aliases'],
                'is_generic' => $isGeneric,
                'target_period' => $vendorTargetInfo['target_period'] ?? 0,
                'realized_period' => $vendorTargetInfo['realizado_vendedor'] ?? $vendorTargetInfo['realized_period'] ?? Support::money($grossTotal),
                'realizado_vendedor' => $vendorTargetInfo['realizado_vendedor'] ?? Support::money($realizedComposition['total_realized'] ?? 0),
                'mercadoria_faturada_vendedor' => $vendorTargetInfo['mercadoria_faturada_vendedor'] ?? Support::money($realizedComposition['total_merchandise'] ?? 0),
                'gap_to_target' => $vendorTargetInfo['gap_to_target'] ?? 0,
                'attainment_pct' => $vendorTargetInfo['attainment_pct'] ?? 0,
                'forecast_period' => $vendorTargetInfo['forecast_period'] ?? 0,
                'risk_status' => $vendorTargetInfo['risk_status'] ?? 'SEM_META'
            ],
            'period' => ['start' => $start, 'end' => $end],
            'summary' => [
                'orders_count' => count($allPipeline),
                'invoices_count' => count($allInvoices),
                'customers_count' => count($customerSet),
                'products_count' => 0,
                'gross_total' => Support::money($grossTotal),
                'cancelled_total' => Support::money($cancelledTotal),
                'open_total' => Support::money($openTotal),
                'ticket_avg' => $invoicesCount > 0 ? Support::money($grossTotal / $invoicesCount) : 0,
            ],
            'series' => $dailySales,
            'daily_sales' => $dailySales,
            'wallet_orders' => array_slice($allPipeline, 0, 20),
            'pipeline_orders' => array_slice($allPipeline, 0, 20),
            'invoices' => array_slice($allInvoices, 0, 20),
            'orders' => array_slice($allInvoices, 0, 20),
            'cancelled_invoices' => array_slice($allCancelled, 0, 20),
            'customers' => $topCustomers,
            'realized_composition' => $realizedComposition['invoices'] ?? [],
            'products' => [],
            'timeline' => [],
            'data_quality' => 'operational',
            'meta' => [
                'generated_at' => $canonical->lastSyncAt($start, $end),
                'request_id' => RequestContext::id(),
                'identity_resolution' => $identity
            ],
        ]);
    }

    $service = new FlightPlanService($config, $cache, $logger, $analytics, $targetsRepository);
    $payload = $service->build($start, $end, false);

    $vendorTarget = null;
    foreach ($payload['vendors'] ?? [] as $vendor) {
        if ((string) ($vendor['vendor_id'] ?? '') === $vendorRef) {
            $vendorTarget = $vendor;
            break;
        }
    }

    if ($vendorTarget === null) {
        throw new InvalidArgumentException('Vendedor nao encontrado no plano de voo atual.');
    }

    $snapshot = $analytics->getSnapshot($start, $end, false);
    $vendorOrders = [];
    $dailySalesMap = [];

    foreach (Support::eachDate($start, $end) as $date) {
        $dailySalesMap[$date] = [
            'date' => $date,
            'gross_revenue' => 0.0,
            'orders' => 0,
        ];
    }

    foreach ($snapshot['top_orders'] ?? [] as $order) {
        if ((string) ($order['vendor_id'] ?? '') !== $vendorRef) {
            continue;
        }

        $orderStatus = strtolower((string) ($order['stage'] ?? ''));
        $isCancelled = str_contains($orderStatus, 'cancelado')
            || str_contains($orderStatus, 'devolvid')
            || str_contains($orderStatus, 'recusad');

        if (!$isCancelled) {
            $date = (string) ($order['billing_date'] ?? $order['issue_date'] ?? '');
            if ($date !== '' && isset($dailySalesMap[$date])) {
                $dailySalesMap[$date]['gross_revenue'] += Support::toFloat($order['total'] ?? 0);
                $dailySalesMap[$date]['orders']++;
            }
        }

        $vendorOrders[] = $order;
    }

    usort(
        $vendorOrders,
        static fn (array $left, array $right): int => strcmp((string) ($right['issue_date'] ?? ''), (string) ($left['issue_date'] ?? ''))
    );

    Http::json([
        'ok' => true,
        'source' => 'legacy',
        'vendor' => $vendorTarget,
        'daily_sales' => array_values($dailySalesMap),
        'orders' => array_slice($vendorOrders, 0, 20),
        'cancelled_invoices' => [],
        'meta' => [
            'request_id' => RequestContext::id(),
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao gerar Vendor Detail.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel carregar os detalhes do vendedor. Tente novamente.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @param array<string,mixed> $row
 */
function vendorDetailMatchesReference(array $row, string $vendorRef): bool
{
    $references = array_filter([
        rawurldecode(trim((string) ($row['vendor_id'] ?? ''))),
        rawurldecode(trim((string) ($row['vendor_key'] ?? ''))),
        rawurldecode(trim((string) ($row['vendor_ref'] ?? ''))),
        rawurldecode(trim((string) ($row['vendor_name'] ?? ''))),
    ], static fn (string $value): bool => $value !== '');

    $normalizedRef = vendorDetailNormalizeName($vendorRef);
    foreach ($references as $reference) {
        if ($reference === $vendorRef) {
            return true;
        }
        if ($normalizedRef !== '' && vendorDetailNormalizeName($reference) === $normalizedRef) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $row
 */
function vendorDetailMatchesCommercialRow(array $row, string $vendorId, string $vendorKey, string $vendorName): bool
{
    $rowVendorId = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
    $rowVendorName = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''));

    if ($vendorId !== '' && $rowVendorId !== '' && $rowVendorId === $vendorId) {
        return true;
    }

    if ($vendorKey !== '' && $rowVendorId !== '' && $rowVendorId === $vendorKey) {
        return true;
    }

    return $vendorName !== '' && vendorDetailNormalizeName($rowVendorName) === vendorDetailNormalizeName($vendorName);
}

function vendorDetailNormalizeName(string $value): string
{
    $decoded = str_contains($value, '%') ? rawurldecode($value) : $value;
    $normalized = trim(mb_strtolower($decoded));
    return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
}

/**
 * @param list<array<string,mixed>> $invoices
 * @return list<array{date:string,gross_revenue:float,orders:int}>
 */
function buildVendorDetailDailySales(string $start, string $end, array $invoices): array
{
    $daily = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $daily[$date] = [
            'date' => $date,
            'gross_revenue' => 0.0,
            'orders' => 0,
        ];
    }

    foreach ($invoices as $invoice) {
        $date = (string) ($invoice['issue_date'] ?? $invoice['billing_date'] ?? '');
        if ($date === '' || !isset($daily[$date])) {
            continue;
        }

        $daily[$date]['gross_revenue'] = Support::money(
            (float) $daily[$date]['gross_revenue'] + Support::toFloat($invoice['merchandise_total'] ?? $invoice['total'] ?? 0)
        );
        $daily[$date]['orders']++;
    }

    return array_values($daily);
}

/**
 * @return array<string,mixed>
 */
function buildCanonicalVendorDetailPayload(
    CanonicalRepository $canonical,
    ?TargetsRepository $targetsRepository,
    string $start,
    string $end,
    string $vendorId,
    float $saturdayWeight
): array {
    $months = monthsInRangeVendorDetail($start, $end);
    $today = new DateTimeImmutable('today');
    $workdays = WorkingDays::summarize($start, $end, $today, $saturdayWeight);
    $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths($months, $vendorId)
        : [];

    $vendorRows = $canonical->aggregateByVendorCommercial($start, $end);
    $vendorMap = $canonical->getVendorMap();

    $vendorName = (string) ($vendorMap[$vendorId]['nome'] ?? '');
    $realized = 0.0;
    $backlog = 0.0;
    $ordersCount = 0;

    foreach ($vendorRows as $row) {
        if ((string) ($row['codigo_vendedor_omie'] ?? '') !== $vendorId) {
            continue;
        }

        $vendorName = trim((string) ($row['nome_vendedor'] ?? $vendorName));
        $status = (string) ($row['status_comercial'] ?? 'outro');
        $total = Support::toFloat($row['total'] ?? 0);
        $orders = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $realized += $total;
            $ordersCount += $orders;
        } elseif ($status === 'autorizado') {
            $backlog += $total;
        }
    }

    $targetPeriod = resolveVendorTargetForPeriodVendorDetail($vendorId, $start, $end, $months, $targetsMap, $saturdayWeight);
    $targetMonth = resolveVendorFullMonthTargetVendorDetail($vendorId, $months, $targetsMap);

    if ($vendorName === '' && $targetPeriod <= 0.0 && $realized <= 0.0 && $backlog <= 0.0) {
        throw new InvalidArgumentException('Vendedor nao encontrado no periodo atual.');
    }

    $forecasts = calculateForecastVendorDetail($realized, $backlog, $workdays, $today, $start, $end);
    $forecast = $forecasts['probable'];
    $line85 = Support::money($targetPeriod * 0.85);

    $dailySalesMap = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailySalesMap[$date] = ['date' => $date, 'gross_revenue' => 0.0, 'orders' => 0];
    }

    $vendorOrders = [];
    foreach ($canonical->listOrdersForDisplayCommercial($start, $end, null) as $order) {
        if ((string) ($order['vendor_id'] ?? '') !== $vendorId) {
            continue;
        }

        $statusGroup = (string) ($order['status_group'] ?? '');
        if (!in_array($statusGroup, ['faturado', 'autorizado'], true)) {
            continue;
        }

        $vendorOrders[] = $order;

        if ($statusGroup === 'faturado') {
            $date = (string) ($order['issue_date'] ?? '');
            if ($date !== '' && isset($dailySalesMap[$date])) {
                $dailySalesMap[$date]['gross_revenue'] += Support::toFloat($order['total'] ?? 0);
                $dailySalesMap[$date]['orders']++;
            }
        }
    }

    usort($vendorOrders, static function (array $left, array $right): int {
        $leftDate = (string) ($left['issue_date'] ?? '');
        $rightDate = (string) ($right['issue_date'] ?? '');
        return strcmp($rightDate, $leftDate);
    });

    return [
        'ok' => true,
        'source' => 'canonical',
        'vendor' => [
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
            'target_month' => $targetMonth,
            'target_period' => $targetPeriod,
            'realized_period' => Support::money($realized),
            'attainment_pct' => $targetPeriod > 0 ? round(($realized / $targetPeriod) * 100, 2) : 0.0,
            'forecast_period' => $forecast,
            'forecasts' => $forecasts,
            'gap_to_target' => Support::money(max($targetPeriod - $realized, 0)),
            'line_85_value' => $line85,
            'line_85_gap' => Support::money(max($line85 - $realized, 0)),
            'new_daily_target' => calculateNewDailyTargetVendorDetail($realized, $targetPeriod, $workdays),
            'risk_status' => riskStatusVendorDetail($forecast, $targetPeriod, $line85),
            'orders' => $ordersCount,
        ],
        'daily_sales' => array_values(array_map(static function (array $row): array {
            $row['gross_revenue'] = Support::money((float) $row['gross_revenue']);
            return $row;
        }, $dailySalesMap)),
        'orders' => array_slice($vendorOrders, 0, 20),
        'meta' => [
            'generated_at' => $canonical->lastSyncAt($start, $end),
            'last_sync' => $canonical->lastSyncAt($start, $end),
            'period_basis' => 'issue_date',
            'request_id' => RequestContext::id(),
        ],
    ];
}

/**
 * @return list<string>
 */
function monthsInRangeVendorDetail(string $start, string $end): array
{
    $months = [];
    $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
    $last = new DateTimeImmutable(substr($end, 0, 7) . '-01');

    while ($cursor <= $last) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    return $months;
}

function resolveVendorTargetForPeriodVendorDetail(
    string $vendorId,
    string $start,
    string $end,
    array $months,
    array $targetsMap,
    float $saturdayWeight
): float {
    if ($vendorId === '') {
        return 0.0;
    }

    $target = 0.0;
    foreach ($months as $month) {
        $key = $vendorId . '|' . $month;
        if (!isset($targetsMap[$key])) {
            continue;
        }

        $monthTarget = Support::toFloat($targetsMap[$key]['target_amount'] ?? 0);
        if ($monthTarget <= 0.0) {
            continue;
        }

        [$overlapStart, $overlapEnd] = monthOverlapVendorDetail($month, $start, $end);
        if ($overlapStart === null || $overlapEnd === null) {
            continue;
        }

        $monthWeight = WorkingDays::monthTotalWeight($month, $saturdayWeight);
        $overlapWeight = WorkingDays::weightedBetween($overlapStart, $overlapEnd, $saturdayWeight);
        if ($monthWeight > 0.0) {
            $target += $monthTarget * ($overlapWeight / $monthWeight);
        }
    }

    return Support::money($target);
}

function resolveVendorFullMonthTargetVendorDetail(string $vendorId, array $months, array $targetsMap): float
{
    if ($vendorId === '') {
        return 0.0;
    }

    $target = 0.0;
    foreach ($months as $month) {
        $key = $vendorId . '|' . $month;
        if (!isset($targetsMap[$key])) {
            continue;
        }

        $target += Support::toFloat($targetsMap[$key]['target_amount'] ?? 0);
    }

    return Support::money($target);
}

/**
 * @return array{0:?string,1:?string}
 */
function monthOverlapVendorDetail(string $yearMonth, string $start, string $end): array
{
    $monthStart = new DateTimeImmutable($yearMonth . '-01');
    $monthEnd = $monthStart->modify('last day of this month');
    $rangeStart = new DateTimeImmutable($start);
    $rangeEnd = new DateTimeImmutable($end);

    $overlapStart = $monthStart > $rangeStart ? $monthStart : $rangeStart;
    $overlapEnd = $monthEnd < $rangeEnd ? $monthEnd : $rangeEnd;
    if ($overlapEnd < $overlapStart) {
        return [null, null];
    }

    return [$overlapStart->format('Y-m-d'), $overlapEnd->format('Y-m-d')];
}

/**
 * @return array{base:float,probable:float,commit:float}
 */
function calculateForecastVendorDetail(
    float $realized,
    float $backlog,
    array $workdays,
    DateTimeImmutable $today,
    string $start,
    string $end
): array {
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $empty = ['base' => 0.0, 'probable' => 0.0, 'commit' => 0.0];

    if ($today < $startDate) {
        return $empty;
    }

    if ($today > $endDate) {
        $realized = Support::money($realized);
        return ['base' => $realized, 'probable' => $realized, 'commit' => $realized];
    }

    $elapsed = (float) ($workdays['elapsed_weight'] ?? 0.0);
    $total = (float) ($workdays['total_weight'] ?? 0.0);
    if ($elapsed <= 0.0 || $total <= 0.0) {
        return $empty;
    }

    $base = Support::money(($realized / $elapsed) * $total);
    $probable = Support::money($base + ($backlog * 0.8));
    $commit = Support::money($base + ($backlog * 0.4));

    return [
        'base' => $base,
        'probable' => $probable,
        'commit' => $commit,
    ];
}

function calculateNewDailyTargetVendorDetail(float $realized, float $target, array $workdays): float
{
    $remainingWeight = (float) ($workdays['remaining_weight'] ?? 0.0);
    if ($target <= 0.0 || $remainingWeight <= 0.0) {
        return 0.0;
    }

    return Support::money(max($target - $realized, 0.0) / $remainingWeight);
}

function riskStatusVendorDetail(float $forecast, float $target, float $line85): string
{
    if ($target <= 0.0) {
        return 'SEM_META';
    }
    if ($forecast >= $target) {
        return 'OK';
    }
    if ($forecast >= $line85) {
        return 'ATENCAO';
    }

    return 'SALA_DE_GUERRA';
}
