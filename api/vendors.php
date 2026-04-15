<?php

declare(strict_types=1);

$__vendorsDebugLog = dirname(__DIR__) . '/storage/logs/vendors_runtime_debug.log';

register_shutdown_function(static function () use ($__vendorsDebugLog): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $payload = [
        'type' => 'shutdown_fatal',
        'message' => $error['message'] ?? 'unknown fatal',
        'file' => $error['file'] ?? null,
        'line' => $error['line'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'time' => date(DATE_ATOM),
    ];

    @file_put_contents(
        $__vendorsDebugLog,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Falha fatal em api/vendors.php',
            'fatal' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

set_exception_handler(static function (Throwable $e) use ($__vendorsDebugLog): void {
    $payload = [
        'type' => 'uncaught_exception',
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'time' => date(DATE_ATOM),
    ];

    @file_put_contents(
        $__vendorsDebugLog,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Exceção não tratada em api/vendors.php',
            'error' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

use BIomie\CanonicalCoverage;
use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\CommercialPlanning;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Http;
use BIomie\LegacyEndpointPayloadFactory;
use BIomie\RequestContext;
use BIomie\ShadowValidationService;
use BIomie\Support;
use BIomie\TargetsRepository;

$debugEnabled = false;
$flagsContext = [];
$requestContext = [
    'competencia' => is_string($_GET['competencia'] ?? null) ? trim($_GET['competencia']) : null,
    'start' => is_string($_GET['start'] ?? null) ? trim($_GET['start']) : null,
    'end' => is_string($_GET['end'] ?? null) ? trim($_GET['end']) : null,
];
$lastSourceAttempted = 'bootstrap';

try {
    require __DIR__ . '/bootstrap.php';
    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);

    $debugEnabled = (bool) $config->get('app.debug', false);
    $flagsContext = collectVendorFlags($config);

    $range = resolveVendorPeriod();
    $start = $range['start'];
    $end = $range['end'];
    $requestContext = [
        'competencia' => $range['competencia'],
        'start' => $start,
        'end' => $end,
    ];

    $forceRefresh = Http::boolQuery('force', false);
    $canonicalFallbackReason = null;
    $canonicalCoverage = null;

    if ($config->isCanonicalEnabled('vendors') && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $lastSourceAttempted = 'canonical';

        try {
            $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

            if (!Database::isConfigured($config, $connection)) {
                $canonicalFallbackReason = 'Conexao canonical nao configurada.';
            } else {
                $pdo = Database::connect($config, $connection);
                $repo = new CanonicalRepository($pdo, $logger);
                $targetsRepository = null;

                if (!$repo->isAvailable()) {
                    $canonicalFallbackReason = 'Camada canonical indisponivel.';
                } else {
                    $canonicalCoverage = $repo->commercialCoverageDiagnostics($start, $end);
                    try {
                        $targetsRepository = new TargetsRepository($pdo, $logger);
                    } catch (Throwable) {
                        $targetsRepository = null;
                    }

                    $historical = new CommercialCompetenciaSnapshotService(
                        $pdo,
                        $logger,
                        $repo,
                        $targetsRepository,
                        (float) $config->get('flight_plan.saturday_weight', 0.33)
                    );
                    $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
                    if ($snapshotYm !== null) {
                        $payload = $historical->loadPayload('vendors', $snapshotYm);
                        if ($payload === null || $forceRefresh) {
                            $built = $historical->materializeCompetencia($snapshotYm, $forceRefresh);
                            $payload = $built['vendors'];
                        }
                        $historicalPayload = $freshness->attach('vendors', $payload, ['observe_request' => true]);
                        $historicalPayload = enrichVendorMeta($historicalPayload, $canonicalCoverage ?? [], null);
                        $shadow->defer(
                            'vendors',
                            static function () use ($analytics, $range, $forceRefresh, $freshness): ?array {
                                $snapshot = $analytics->getSnapshot($range['start'], $range['end'], $forceRefresh);
                                return $freshness->attach('vendors', buildLegacyVendorPayload($snapshot, $range, 'legacy'));
                            },
                            static fn (): ?array => $historicalPayload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $range['competencia'],
                                'official_mode' => 'canonical_historical',
                            ]
                        );
                        respondJson($historicalPayload);
                    }

                    $lastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM));
                    $canonicalPayload = buildCanonicalVendorPayload($repo, $targetsRepository, $start, $end, $lastSync, (float) $config->get('flight_plan.saturday_weight', 0.33));
                    $canonicalPayload = $freshness->attach('vendors', $canonicalPayload, ['observe_request' => true]);
                    $canonicalPayload = enrichVendorMeta($canonicalPayload, $canonicalCoverage ?? [], null);
                    if (CanonicalCoverage::canServe($canonicalCoverage ?? [])) {
                        $shadow->defer(
                            'vendors',
                            static function () use ($analytics, $range, $forceRefresh, $freshness): ?array {
                                $snapshot = $analytics->getSnapshot($range['start'], $range['end'], $forceRefresh);
                                return $freshness->attach('vendors', buildLegacyVendorPayload($snapshot, $range, 'legacy'));
                            },
                            static fn (): ?array => $canonicalPayload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $range['competencia'],
                                'official_mode' => 'canonical_live',
                            ]
                        );
                        respondJson($canonicalPayload);
                    }

                    $canonicalFallbackReason = 'Camada canonical disponivel, mas ainda sem sincronizacao/cobertura suficiente para o periodo.';
                }
            }
        } catch (Throwable $canonicalException) {
            $canonicalFallbackReason = summarizeThrowable($canonicalException);

            $logger->error('Falha no caminho canonical de vendors; usando fallback legado.', [
                'message' => $canonicalException->getMessage(),
                'trace' => $canonicalException->getTraceAsString(),
                'exception_class' => $canonicalException::class,
                'competencia' => $range['competencia'],
                'period' => ['start' => $start, 'end' => $end],
                'source_attempted' => 'canonical',
                'flags' => $flagsContext,
                'request_id' => RequestContext::id(),
            ]);
        }

        if ($canonicalFallbackReason !== null) {
            $logger->warning('Vendors canonical indisponivel para o periodo; usando fallback legado.', [
                'reason' => $canonicalFallbackReason,
                'competencia' => $range['competencia'],
                'period' => ['start' => $start, 'end' => $end],
                'source_attempted' => 'canonical',
                'flags' => $flagsContext,
                'request_id' => RequestContext::id(),
            ]);
        }
    }

    $lastSourceAttempted = 'legacy';
    $snapshot = $analytics->getSnapshot($start, $end, $forceRefresh);
    $fallbackSource = $canonicalFallbackReason !== null ? 'legacy_fallback' : 'legacy';
    $legacyPayload = $freshness->attach('vendors', buildLegacyVendorPayload($snapshot, $range, $fallbackSource, $canonicalFallbackReason), ['observe_request' => true]);
    $legacyPayload = enrichVendorMeta(
        $legacyPayload,
        is_array($canonicalCoverage) ? $canonicalCoverage : [
            'requested_start' => $start,
            'requested_end' => $end,
            'has_any_rows' => false,
            'last_sync_at' => null,
        ],
        $canonicalFallbackReason
    );

    $shadow->defer(
        'vendors',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $range, $forceRefresh): ?array {
            return buildCanonicalVendorShadowPayload($config, $logger, $freshness, $range, $forceRefresh);
        },
        [
            'start' => $start,
            'end' => $end,
            'competencia' => $range['competencia'],
            'official_mode' => 'legacy',
        ]
    );

    respondJson($legacyPayload);
} catch (\InvalidArgumentException $exception) {
    respondJson([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 400);
} catch (Throwable $exception) {
    if (isset($logger)) {
        $logger->error('Falha em api/vendors.', [
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'exception_class' => $exception::class,
            'competencia' => $requestContext['competencia'] ?? null,
            'period' => [
                'start' => $requestContext['start'] ?? null,
                'end' => $requestContext['end'] ?? null,
            ],
            'source_attempted' => $lastSourceAttempted,
            'flags' => $flagsContext,
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }

    $payload = [
        'ok' => false,
        'message' => 'Erro interno ao carregar vendedores.',
    ];

    if ($debugEnabled) {
        $payload['debug'] = summarizeThrowable($exception, 500);
    }

    respondJson($payload, 500);
}

/**
 * @return array{start:string,end:string,label:string,competencia:?string}
 */
function resolveVendorPeriod(): array
{
    $competencia = Http::query('competencia');
    if ($competencia !== null && $competencia !== '') {
        if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            throw new \InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
        }

        $month = \DateTimeImmutable::createFromFormat('!Y-m', $competencia);
        if (!$month instanceof \DateTimeImmutable || $month->format('Y-m') !== $competencia) {
            throw new \InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
        }

        $start = $month->format('Y-m-01');
        $end = $month->modify('last day of this month')->format('Y-m-d');

        return [
            'start' => $start,
            'end' => $end,
            'label' => formatVendorPeriodLabel($start, $end),
            'competencia' => $competencia,
        ];
    }

    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $range['competencia'] = null;

    return $range;
}

/**
 * @param array<string,mixed> $snapshot
 * @param array{start:string,end:string,label:string,competencia:?string} $range
 * @return array<string,mixed>
 */
function buildLegacyVendorPayload(array $snapshot, array $range, string $source, ?string $canonicalError = null): array
{
    return LegacyEndpointPayloadFactory::vendors($snapshot, $range, $source, $canonicalError);
}

/**
 * @param array{start:string,end:string,label:string,competencia:?string} $range
 * @return array<string,mixed>|null
 */
function buildCanonicalVendorShadowPayload(\BIomie\Config $config, \BIomie\Logger $logger, DatasetFreshnessService $freshness, array $range, bool $forceRefresh = false): ?array
{
    if (
        !$config->isCanonicalEnabled('vendors')
        || !(bool) $config->get('features.canonical_fiscal_ready', false)
    ) {
        return null;
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        return null;
    }

    try {
        $pdo = Database::connect($config, $connection);
        $repo = new CanonicalRepository($pdo, $logger);
        if (!$repo->isAvailable()) {
            return null;
        }

        $targetsRepository = null;
        try {
            $targetsRepository = new TargetsRepository($pdo, $logger);
        } catch (Throwable) {
            $targetsRepository = null;
        }

        $start = $range['start'];
        $end = $range['end'];
        $historical = new CommercialCompetenciaSnapshotService(
            $pdo,
            $logger,
            $repo,
            $targetsRepository,
            (float) $config->get('flight_plan.saturday_weight', 0.33)
        );
        $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
        if ($snapshotYm !== null) {
            $payload = $historical->loadPayload('vendors', $snapshotYm);
            if ($payload === null || $forceRefresh) {
                $built = $historical->materializeCompetencia($snapshotYm, $forceRefresh);
                $payload = $built['vendors'];
            }

            if (is_array($payload) && canonicalVendorPayloadHasSignal($payload)) {
                return $freshness->attach('vendors', $payload);
            }
        }

        $lastSync = $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM);
        $payload = buildCanonicalVendorPayload(
            $repo,
            $targetsRepository,
            $start,
            $end,
            $lastSync,
            (float) $config->get('flight_plan.saturday_weight', 0.33)
        );
        $payload = $freshness->attach('vendors', $payload);

        return canonicalVendorPayloadHasSignal($payload) ? $payload : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<string,mixed>
 */
function buildCanonicalVendorPayload(CanonicalRepository $canonical, ?TargetsRepository $targetsRepository, string $start, string $end, string $lastSync, float $saturdayWeight = 0.33): array
{
    $summary = $canonical->summarizeCommercial($start, $end);
    $invoiceSummary = $canonical->summarizeInvoicesCommercial($start, $end);
    $vendorRows = $canonical->aggregateByVendorCommercial($start, $end);
    $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
    $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
        : [];
    $planning = CommercialPlanning::build($vendorRows, $targetsMap, $start, $end, $saturdayWeight);

    $billedVal = (float) ($summary['billed_value'] ?? 0);
    $billedQty = (int) ($summary['billed_orders'] ?? 0);
    $authVal = (float) ($summary['authorized_value'] ?? 0);
    $authQty = (int) ($summary['authorized_orders'] ?? 0);
    $cancelVal = (float) ($summary['cancelled_value'] ?? 0);
    $cancelQty = (int) ($summary['cancelled_orders'] ?? 0);

    $summaryStatus = [
        'billed_value' => Support::money($billedVal),
        'billed_orders' => $billedQty,
        'authorized_value' => Support::money($authVal),
        'authorized_orders' => $authQty,
        'cancelled_value' => Support::money($cancelVal),
        'cancelled_orders' => $cancelQty,
        'backlog_value' => Support::money($authVal),
        'conversion_rate' => ($authQty + $billedQty) > 0 ? round(($billedQty / ($authQty + $billedQty)) * 100, 2) : 0.0,
        'cancel_rate' => ($authQty + $cancelQty) > 0 ? round(($cancelQty / ($authQty + $cancelQty)) * 100, 2) : 0.0,
    ];

    $vendorMatrix = [];
    $byVendor = [];

    foreach ($vendorRows as $row) {
        $vendorId = (string) ($row['codigo_vendedor_omie'] ?? '');
        $vendorName = (string) ($row['nome_vendedor'] ?? 'Sem vendedor');
        $status = (string) ($row['status_comercial'] ?? 'outro');
        $key = $vendorId !== '' ? $vendorId : 'name:' . normalizeVendorKey($vendorName);

        if (!isset($byVendor[$key])) {
            $byVendor[$key] = [
                'vendor_id'            => $vendorId,
                'vendor_name'          => $vendorName,
                'vendor_ref'           => $key,
                'billed_value'         => 0.0,
                'billed_orders'        => 0,
                'billed_merchandise'   => 0.0,
                'authorized_value'     => 0.0,
                'authorized_orders'    => 0,
                'pipeline_merchandise' => 0.0,
                'cancelled_value'      => 0.0,
                'cancelled_orders'     => 0,
            ];
        }

        $total      = (float) ($row['total']      ?? 0);
        $orders     = (int)   ($row['pedidos']    ?? 0);
        $mercadorias = (float) ($row['mercadorias'] ?? 0);

        if ($status === 'faturado') {
            $byVendor[$key]['billed_value']       += $total;
            $byVendor[$key]['billed_orders']       += $orders;
            $byVendor[$key]['billed_merchandise']  += $mercadorias;
            continue;
        }

        if ($status === 'autorizado') {
            $byVendor[$key]['authorized_value']     += $total;
            $byVendor[$key]['authorized_orders']     += $orders;
            $byVendor[$key]['pipeline_merchandise'] += $mercadorias;
            continue;
        }

        if ($status === 'cancelado') {
            $byVendor[$key]['cancelled_value']  += $total;
            $byVendor[$key]['cancelled_orders'] += $orders;
        }
    }

    foreach ($byVendor as $vendor) {
        $vendorBilledValue       = (float) ($vendor['billed_value']         ?? 0);
        $vendorBilledOrders      = (int)   ($vendor['billed_orders']         ?? 0);
        $vendorBilledMerc        = (float) ($vendor['billed_merchandise']    ?? 0);
        $vendorAuthorizedValue   = (float) ($vendor['authorized_value']      ?? 0);
        $vendorAuthorizedOrders  = (int)   ($vendor['authorized_orders']      ?? 0);
        $vendorPipelineMerc      = (float) ($vendor['pipeline_merchandise']  ?? 0);
        $vendorCancelledValue    = (float) ($vendor['cancelled_value']       ?? 0);
        $vendorCancelledOrders   = (int)   ($vendor['cancelled_orders']       ?? 0);
        $vendorRef               = (string) ($vendor['vendor_ref']           ?? trim((string) ($vendor['vendor_id'] ?? '')));

        $vendorMatrix[] = [
            'vendor_id'            => (string) ($vendor['vendor_id']   ?? ''),
            'vendor_name'          => (string) ($vendor['vendor_name'] ?? 'Sem vendedor'),
            'vendor_ref'           => $vendorRef,
            'billed_value'         => Support::money($vendorBilledValue),
            'billed_orders'        => $vendorBilledOrders,
            'billed_merchandise'   => Support::money($vendorBilledMerc),
            'authorized_value'     => Support::money($vendorAuthorizedValue),
            'authorized_orders'    => $vendorAuthorizedOrders,
            'pipeline_merchandise' => Support::money($vendorPipelineMerc),
            'cancelled_value'      => Support::money($vendorCancelledValue),
            'cancelled_orders'     => $vendorCancelledOrders,
            'backlog_value'        => Support::money($vendorAuthorizedValue),
            'conversion_rate'      => ($vendorAuthorizedOrders + $vendorBilledOrders) > 0
                ? round(($vendorBilledOrders / ($vendorAuthorizedOrders + $vendorBilledOrders)) * 100, 2)
                : 0.0,
            'cancel_rate'          => ($vendorAuthorizedOrders + $vendorCancelledOrders) > 0
                ? round(($vendorCancelledOrders / ($vendorAuthorizedOrders + $vendorCancelledOrders)) * 100, 2)
                : 0.0,
            'avg_ticket_billed'    => $vendorBilledOrders > 0 ? Support::money($vendorBilledMerc / $vendorBilledOrders) : 0.0,
            'avg_days_to_bill'     => 0,
            'share_billed'         => $billedVal > 0 ? round(($vendorBilledValue / $billedVal) * 100, 2) : 0.0,
            'target_period'        => 0.0,
            'forecast_period'      => 0.0,
            'gap_to_target'        => 0.0,
            'attainment_pct'       => 0.0,
            'risk_status'          => 'SEM_META',
            'forecasts'            => [],
        ];
    }

    usort(
        $vendorMatrix,
        static fn (array $left, array $right): int => $right['billed_value'] <=> $left['billed_value']
    );

    $planningByKey = [];
    foreach ($planning['vendors'] as $vendorPlan) {
        $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
        $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
        $planningByKey[$vendorId !== '' ? $vendorId : 'name:' . normalizeVendorKey($vendorName)] = $vendorPlan;
    }

    foreach ($vendorMatrix as $index => $vendor) {
        $key = trim((string) ($vendor['vendor_id'] ?? '')) !== ''
            ? (string) $vendor['vendor_id']
            : 'name:' . normalizeVendorKey((string) ($vendor['vendor_name'] ?? 'Sem vendedor'));
        $plan = $planningByKey[$key] ?? null;
        if ($plan === null) {
            continue;
        }

        $target = Support::toFloat($plan['target_period'] ?? 0);
        $bm     = Support::toFloat($vendorMatrix[$index]['billed_merchandise'] ?? 0);

        $vendorMatrix[$index]['target_period']   = $target;
        $vendorMatrix[$index]['forecast_period']  = Support::toFloat($plan['forecast_period'] ?? 0);
        $vendorMatrix[$index]['gap_to_target']    = Support::money(max($target - $bm, 0));
        $vendorMatrix[$index]['attainment_pct']   = $target > 0 ? round(($bm / $target) * 100, 2) : 0.0;
        $vendorMatrix[$index]['risk_status']      = (string) ($plan['risk_status'] ?? 'SEM_META');
        $vendorMatrix[$index]['forecasts']        = $plan['forecasts'] ?? [];
    }

    $dailyStatus = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailyStatus[$date] = [
            'date' => $date,
            'billed_value' => 0.0,
            'billed_orders' => 0,
            'authorized_value' => 0.0,
            'authorized_orders' => 0,
            'cancelled_value' => 0.0,
            'cancelled_orders' => 0,
        ];
    }

    foreach ($dailyRows as $row) {
        $day = (string) ($row['dia'] ?? '');
        $status = (string) ($row['status_comercial'] ?? '');

        if (!isset($dailyStatus[$day])) {
            continue;
        }

        $total = (float) ($row['total'] ?? 0);
        $orders = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $dailyStatus[$day]['billed_value'] += $total;
            $dailyStatus[$day]['billed_orders'] += $orders;
            continue;
        }

        if ($status === 'autorizado') {
            $dailyStatus[$day]['authorized_value'] += $total;
            $dailyStatus[$day]['authorized_orders'] += $orders;
            continue;
        }

        if ($status === 'cancelado') {
            $dailyStatus[$day]['cancelled_value'] += $total;
            $dailyStatus[$day]['cancelled_orders'] += $orders;
        }
    }

    $alertsOperational = [];
    foreach ($vendorMatrix as $vendor) {
        $baseOrders = (int) ($vendor['cancelled_orders'] ?? 0) + (int) ($vendor['authorized_orders'] ?? 0);
        if ((float) ($vendor['cancel_rate'] ?? 0) > 15 && $baseOrders >= 3) {
            $alertsOperational[] = [
                'level' => 'danger',
                'title' => (string) ($vendor['vendor_name'] ?? 'Sem vendedor') . ': cancelamento de ' . $vendor['cancel_rate'] . '%',
                'message' => (int) ($vendor['cancelled_orders'] ?? 0) . ' cancelado(s) de ' . $baseOrders . ' pedido(s).',
            ];
        }
    }

    $revenueOrders = $billedQty;
    $grossRevenue = $billedVal;

    return [
        'ok' => true,
        'mode' => 'canonical',
        'source' => 'canonical',
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => formatVendorPeriodLabel($start, $end),
        ],
        'summary' => [
            'revenue' => Support::money($grossRevenue),
            'gross_revenue' => Support::money($grossRevenue),
            'merchandise_total' => Support::money((float) ($invoiceSummary['merchandise_total'] ?? 0)),
            'discounts_total' => Support::money((float) ($invoiceSummary['discounts_total'] ?? 0)),
            'orders' => $revenueOrders,
            'pending_orders' => $authQty,
            'pending_value' => Support::money($authVal),
            'cancelled_orders' => $cancelQty,
            'cancelled_value' => Support::money($cancelVal),
            'avg_ticket' => $revenueOrders > 0 ? Support::money($grossRevenue / $revenueOrders) : 0.0,
            'avg_ticket_billed' => $billedQty > 0 ? Support::money($billedVal / $billedQty) : 0.0,
            'active_vendors' => count(array_filter(
                $vendorMatrix,
                static fn (array $vendor): bool => trim((string) ($vendor['vendor_name'] ?? '')) !== ''
                    && strcasecmp((string) ($vendor['vendor_name'] ?? ''), 'Sem vendedor') !== 0
            )),
            'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
            'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
            'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
        ],
        'summary_status' => $summaryStatus,
        'vendors' => $vendorMatrix,
        'vendor_status_matrix' => $vendorMatrix,
        'daily_status' => array_values(array_map(static fn (array $day): array => [
            'date' => (string) ($day['date'] ?? ''),
            'billed_value' => Support::money((float) ($day['billed_value'] ?? 0)),
            'billed_orders' => (int) ($day['billed_orders'] ?? 0),
            'authorized_value' => Support::money((float) ($day['authorized_value'] ?? 0)),
            'authorized_orders' => (int) ($day['authorized_orders'] ?? 0),
            'cancelled_value' => Support::money((float) ($day['cancelled_value'] ?? 0)),
            'cancelled_orders' => (int) ($day['cancelled_orders'] ?? 0),
        ], $dailyStatus)),
        'funnel' => [
            'authorized' => Support::money($authVal),
            'billed' => Support::money($billedVal),
            'cancelled' => Support::money($cancelVal),
            'received' => 0.0,
        ],
        'alerts_operational' => $alertsOperational,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'served_from' => 'canonical-db',
            'last_sync' => $lastSync,
            'period_basis' => 'issue_date',
            'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
        ],
    ];
}

/**
 * @return array<string,bool>
 */
function collectVendorFlags($config): array
{
    return [
        'canonical_reads_vendors' => (bool) $config->get('features.canonical_reads_vendors', false),
        'canonical_reads_orders' => (bool) $config->get('features.canonical_reads_orders', false),
        'canonical_reads_overview' => (bool) $config->get('features.canonical_reads_overview', false),
    ];
}

function formatVendorPeriodLabel(string $start, string $end): string
{
    $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $end);

    if (!$startDate instanceof \DateTimeImmutable || !$endDate instanceof \DateTimeImmutable) {
        return $start . ' ate ' . $end;
    }

    return $startDate->format('d/m/Y') . ' ate ' . $endDate->format('d/m/Y');
}

function normalizeVendorKey(string $value): string
{
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    return trim($normalized);
}

function summarizeThrowable(Throwable $throwable, int $maxLength = 220): string
{
    $message = trim($throwable->getMessage());
    if ($message === '') {
        $message = $throwable::class;
    }

    if (strlen($message) <= $maxLength) {
        return $message;
    }

    return substr($message, 0, max(0, $maxLength - 3)) . '...';
}

function respondJson(array $payload, int $status = 200): never
{
    if (class_exists(Http::class)) {
        Http::json($payload, $status);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $json = '{"ok":false,"message":"Falha ao serializar a resposta JSON."}';
    }

    echo $json;
    exit;
}

/**
 * @param array<string,mixed> $payload
 */
function canonicalVendorPayloadHasSignal(array $payload): bool
{
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $summaryStatus = is_array($payload['summary_status'] ?? null) ? $payload['summary_status'] : [];
    $dailyStatus = is_array($payload['daily_status'] ?? null) ? $payload['daily_status'] : [];

    $billed = Support::toFloat($summaryStatus['billed_value'] ?? $summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
    $authorized = Support::toFloat($summaryStatus['authorized_value'] ?? $summary['pending_value'] ?? 0);
    $cancelled = Support::toFloat($summaryStatus['cancelled_value'] ?? $summary['cancelled_value'] ?? 0);
    $orders = (int) ($summary['orders'] ?? 0);
    $hasDailySignal = false;

    foreach ($dailyStatus as $row) {
        if (
            Support::toFloat($row['billed_value'] ?? 0) > 0
            || Support::toFloat($row['authorized_value'] ?? 0) > 0
            || Support::toFloat($row['cancelled_value'] ?? 0) > 0
            || (int) ($row['billed_orders'] ?? 0) > 0
            || (int) ($row['authorized_orders'] ?? 0) > 0
            || (int) ($row['cancelled_orders'] ?? 0) > 0
        ) {
            $hasDailySignal = true;
            break;
        }
    }

    return $billed > 0
        || $authorized > 0
        || $cancelled > 0
        || $orders > 0
        || vendorMatrixContainsSignal($payload['vendor_status_matrix'] ?? [])
        || $hasDailySignal;
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $coverage
 * @return array<string,mixed>
 */
function enrichVendorMeta(array $payload, array $coverage, ?string $fallbackReason): array
{
    $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $payload['meta'] = CanonicalCoverage::decorateMeta($payload['meta'], $coverage, $fallbackReason);
    $payload['meta']['sync_state'] = [
        'reconcile_status' => $payload['meta']['reconcile_status'] ?? null,
        'has_retry_or_delay' => $payload['meta']['has_retry_or_delay'] ?? null,
        'open_divergences' => $payload['meta']['open_divergences'] ?? null,
        'data_mode' => $payload['meta']['data_mode'] ?? null,
    ];

    return $payload;
}

/**
 * @param mixed $rows
 */
function vendorMatrixContainsSignal(mixed $rows): bool
{
    if (!is_array($rows)) {
        return false;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (
            Support::toFloat($row['billed_value'] ?? 0) > 0
            || Support::toFloat($row['authorized_value'] ?? 0) > 0
            || Support::toFloat($row['cancelled_value'] ?? 0) > 0
            || (int) ($row['billed_orders'] ?? 0) > 0
            || (int) ($row['authorized_orders'] ?? 0) > 0
            || (int) ($row['cancelled_orders'] ?? 0) > 0
        ) {
            return true;
        }
    }

    return false;
}
