<?php

declare(strict_types=1);

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

require __DIR__ . '/bootstrap.php';

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);
    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);

    $start = $range['start'];
    $end = $range['end'];
    $force = Http::boolQuery('force', false);
    
    $cacheKey = 'api_overview_payload_' . md5($start . '_' . $end . '_' . ($range['competencia'] ?? ''));
    if (!$force && isset($cache) && $cache instanceof \BIomie\Cache) {
        $cachedResponse = $cache->get($cacheKey, 60);
        if (is_array($cachedResponse)) {
            header('X-Predictive-Cache: hit');
            Http::json($cachedResponse);
        }
    }

    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);
    $snapshot = null;
    $snapshotError = null;
    $canonicalCoverage = null;
    $canonicalFallbackReason = null;

    try {
        $snapshot = $analytics->getSnapshot($start, $end, $force);
    } catch (Throwable $exception) {
        $snapshotError = $exception->getMessage();
        $logger->warning('Overview: snapshot legado indisponivel.', [
            'message' => $exception->getMessage(),
            'period' => ['start' => $start, 'end' => $end],
            'request_id' => RequestContext::id(),
        ]);
    }

    if ($config->isCanonicalEnabled('overview') && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $connection)) {
            try {
                $pdo = Database::connect($config, $connection);
                $canonical = new CanonicalRepository($pdo, $logger);
                $targetsRepository = null;
                if ($canonical->isAvailable()) {
                    $canonicalCoverage = $canonical->commercialCoverageDiagnostics($start, $end);
                    try {
                        $targetsRepository = new TargetsRepository($pdo, $logger);
                    } catch (Throwable) {
                        $targetsRepository = null;
                    }

                    $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
                    $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
                    if ($snapshotYm !== null) {
                        $payload = $historical->loadPayload('overview', $snapshotYm);
                        if ($payload === null || $force) {
                            $built = $historical->materializeCompetencia($snapshotYm, $force);
                            $payload = $built['overview'];
                        }
                        $payload['month_comparison'] = buildOverviewMonthComparison(
                            $canonical,
                            $targetsRepository,
                            $start,
                            $saturdayWeight
                        );
                        $historicalPayload = $freshness->attach('overview', $payload, ['observe_request' => true]);
                        $historicalPayload = enrichOverviewMeta($historicalPayload, $canonicalCoverage ?? [], null);
                        $shadow->defer(
                            'overview',
                            static function () use ($snapshot, $freshness): ?array {
                                return $snapshot === null ? null : buildLegacyOverviewPayload($snapshot, $freshness);
                            },
                            static fn (): ?array => $historicalPayload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $range['competencia'],
                                'official_mode' => 'canonical_historical',
                            ]
                        );
                        if (isset($cache) && $cache instanceof \BIomie\Cache) {
                            $cache->put($cacheKey, $historicalPayload);
                        }
                        Http::json($historicalPayload);
                    }

                    $lastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM));
                    $payload = buildCanonicalOverviewPayload(
                        $canonical,
                        $targetsRepository,
                        $snapshot,
                        $range,
                        $lastSync,
                        $saturdayWeight,
                        $snapshotError
                    );
                    $payload = $freshness->attach('overview', $payload, ['observe_request' => true]);
                    $payload = enrichOverviewMeta($payload, $canonicalCoverage ?? [], null);

                    if (CanonicalCoverage::canServe($canonicalCoverage ?? []) || $snapshot === null) {
                        $shadow->defer(
                            'overview',
                            static function () use ($snapshot, $freshness): ?array {
                                return $snapshot === null ? null : buildLegacyOverviewPayload($snapshot, $freshness);
                            },
                            static fn (): ?array => $payload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $range['competencia'],
                                'official_mode' => 'canonical_live',
                            ]
                        );
                        if (isset($cache) && $cache instanceof \BIomie\Cache) {
                            $cache->put($cacheKey, $payload);
                        }
                        Http::json($payload);
                    }

                    $canonicalFallbackReason = 'Camada canonical disponivel, mas ainda sem sincronizacao/cobertura suficiente para o periodo.';
                    $logger->warning('Overview: canonical sem cobertura suficiente no periodo aberto; mantendo snapshot legado.', [
                        'period' => ['start' => $start, 'end' => $end],
                        'coverage' => $canonicalCoverage,
                        'request_id' => RequestContext::id(),
                    ]);
                }
            } catch (Throwable $exception) {
                $canonicalFallbackReason = $exception->getMessage();
                $logger->warning('Overview: leitura canonical falhou, mantendo legado.', [
                    'message' => $exception->getMessage(),
                    'period' => ['start' => $start, 'end' => $end],
                    'request_id' => RequestContext::id(),
                ]);
            }
        }
    }

    if ($snapshot === null) {
        Http::json([
            'ok' => false,
            'message' => 'Nao foi possivel carregar o painel no momento.',
            'request_id' => RequestContext::id(),
        ], 500);
    }

    $snapshot['source'] = $snapshot['source'] ?? 'legacy';
    $legacyPayload = $freshness->attach('overview', buildLegacyOverviewPayload($snapshot, $freshness), ['observe_request' => true]);
    $legacyPayload = enrichOverviewMeta(
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
        'overview',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $range, $snapshot, $snapshotError, $saturdayWeight, $force): ?array {
            return buildCanonicalOverviewShadowPayload(
                $config,
                $logger,
                $freshness,
                $range,
                $snapshot,
                $snapshotError,
                $saturdayWeight,
                $force
            );
        },
        [
            'start' => $start,
            'end' => $end,
            'competencia' => $range['competencia'],
            'official_mode' => 'legacy',
        ]
    );
    if (isset($cache) && $cache instanceof \BIomie\Cache) {
        $cache->put($cacheKey, $legacyPayload);
    }
    Http::json($legacyPayload);
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha em api/overview.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar o painel. Tente novamente.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @param array<string,mixed> $snapshot
 * @return array<string,mixed>
 */
function buildLegacyOverviewPayload(array $snapshot, DatasetFreshnessService $freshness): array
{
    return LegacyEndpointPayloadFactory::overview($snapshot, $freshness);
}

/**
 * @param array{start:string,end:string,label:string,competencia:?string} $range
 * @param array<string,mixed>|null $snapshot
 * @return array<string,mixed>|null
 */
function buildCanonicalOverviewShadowPayload(
    \BIomie\Config $config,
    \BIomie\Logger $logger,
    DatasetFreshnessService $freshness,
    array $range,
    ?array $snapshot,
    ?string $snapshotError,
    float $saturdayWeight,
    bool $force = false,
): ?array {
    if (
        !$config->isCanonicalEnabled('overview')
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
        $canonical = new CanonicalRepository($pdo, $logger);
        if (!$canonical->isAvailable()) {
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
        $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
        $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
        if ($snapshotYm !== null) {
            $payload = $historical->loadPayload('overview', $snapshotYm);
            if ($payload === null || $force) {
                $built = $historical->materializeCompetencia($snapshotYm, $force);
                $payload = $built['overview'];
            }
            if (is_array($payload)) {
                $payload['month_comparison'] = buildOverviewMonthComparison(
                    $canonical,
                    $targetsRepository,
                    $start,
                    $saturdayWeight
                );
                if (!historicalOverviewHasStrongSignal($payload)) {
                    $built = $historical->materializeCompetencia($snapshotYm, true);
                    $payload = $built['overview'];
                    $payload['month_comparison'] = buildOverviewMonthComparison(
                        $canonical,
                        $targetsRepository,
                        $start,
                        $saturdayWeight
                    );
                }

                if (historicalOverviewHasStrongSignal($payload) || $snapshot === null) {
                    return $freshness->attach('overview', $payload);
                }
            }
        }

        $payload = buildCanonicalOverviewPayload(
            $canonical,
            $targetsRepository,
            $snapshot,
            $range,
            $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM),
            $saturdayWeight,
            $snapshotError
        );
        $payload = $freshness->attach('overview', $payload);

        return (canonicalOverviewHasSignal($payload) || $snapshot === null) ? $payload : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param array<string,mixed>|null $snapshot
 * @param array{start:string,end:string,label:string,competencia:?string} $range
 * @return array<string,mixed>
 */
function buildCanonicalOverviewPayload(
    CanonicalRepository $canonical,
    ?TargetsRepository $targetsRepository,
    ?array $snapshot,
    array $range,
    string $lastSync,
    float $saturdayWeight,
    ?string $snapshotError = null
): array {
    $start = $range['start'];
    $end = $range['end'];
    $summaryRow = $canonical->summarizeInvoicesCommercial($start, $end);
    $invoiceVendorRows = $canonical->aggregateInvoicesByVendorCommercial($start, $end);
    $planningRows = $canonical->aggregateByVendorCommercial($start, $end);
    $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
    $pendingSummary = $canonical->summarizePendingCommercial($start, $end);
    $divergences = $canonical->openDivergences(10);
    $canonicalProducts = $canonical->topProductsCommercial($start, $end, 12);
    $canonicalClients = $canonical->topClientsCommercial($start, $end, 8);
    $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
        : [];
    $planning = CommercialPlanning::build($planningRows, $targetsMap, $start, $end, $saturdayWeight);
    $planningMap = [];
    foreach ($planning['vendors'] as $vendorPlan) {
        $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
        $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
        $planningMap[$vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName)] = $vendorPlan;
    }

    $billedValue = Support::toFloat($summaryRow['billed_value'] ?? 0);
    $ordersCount = (int) ($summaryRow['billed_orders'] ?? 0);
    $grossRevenue = Support::money($billedValue);

    $merchandiseTotal = 0.0;
    $discountsTotal = 0.0;
    $vendors = [];

    foreach ($invoiceVendorRows as $row) {
        $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
        $vendorName = trim((string) ($row['nome_vendedor'] ?? 'Sem vendedor'));
        $key = $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);

        if (!isset($vendors[$key])) {
            $vendors[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                'revenue' => 0.0,
                'gross_revenue' => 0.0,
                'merchandise_total' => 0.0,
                'discount_total' => 0.0,
                'orders' => 0,
            ];
        }

        $total = Support::toFloat($row['total'] ?? 0);
        $merchandise = Support::toFloat($row['mercadorias'] ?? 0);
        $discount = Support::toFloat($row['descontos'] ?? 0);
        $orders = (int) ($row['pedidos'] ?? 0);

        $vendors[$key]['revenue'] += $total;
        $vendors[$key]['gross_revenue'] += $total;
        $vendors[$key]['merchandise_total'] += $merchandise;
        $vendors[$key]['discount_total'] += $discount;
        $vendors[$key]['orders'] += $orders;

        $merchandiseTotal += $merchandise;
        $discountsTotal += $discount;
    }

    $vendorRanking = [];
    foreach ($vendors as $key => $row) {
        $revenue = Support::money($row['revenue']);
        $orders = (int) $row['orders'];
        $plan = $planningMap[$key] ?? null;

        $row['revenue'] = $revenue;
        $row['gross_revenue'] = Support::money($row['gross_revenue']);
        $row['merchandise_total'] = Support::money($row['merchandise_total']);
        $row['discount_total'] = Support::money($row['discount_total']);
        $row['ticket_avg'] = $orders > 0 ? Support::money($revenue / $orders) : 0.0;
        $row['share'] = $grossRevenue > 0 ? round(($revenue / $grossRevenue) * 100, 2) : 0.0;
        $row['target_period'] = Support::toFloat($plan['target_period'] ?? 0);
        $row['forecast_period'] = Support::toFloat($plan['forecast_period'] ?? 0);
        $row['gap_to_target'] = Support::toFloat($plan['gap_to_target'] ?? 0);
        $row['attainment_pct'] = Support::toFloat($plan['attainment_pct'] ?? 0);

        $vendorRanking[] = $row;
    }
    usort($vendorRanking, static fn (array $left, array $right): int => $right['revenue'] <=> $left['revenue']);

    $dailySalesMap = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailySalesMap[$date] = [
            'date' => $date,
            'revenue' => 0.0,
            'gross_revenue' => 0.0,
            'orders' => 0,
        ];
    }

    foreach ($dailyRows as $row) {
        $status = (string) ($row['status_comercial'] ?? 'outro');
        if ($status !== 'faturado') {
            continue;
        }

        $date = (string) ($row['dia'] ?? '');
        if ($date === '' || !isset($dailySalesMap[$date])) {
            continue;
        }

        $total = Support::toFloat($row['total'] ?? 0);
        $orders = (int) ($row['pedidos'] ?? 0);

        $dailySalesMap[$date]['revenue'] += $total;
        $dailySalesMap[$date]['gross_revenue'] += $total;
        $dailySalesMap[$date]['orders'] += $orders;
    }

    $dailySales = array_values(array_map(static function (array $row): array {
        $row['revenue'] = Support::money($row['revenue']);
        $row['gross_revenue'] = Support::money($row['gross_revenue']);
        return $row;
    }, $dailySalesMap));

    $orders = $canonical->listInvoicesForDisplayCommercial($start, $end);
    usort(
        $orders,
        static fn (array $left, array $right): int =>
            Support::toFloat($right['gross_total'] ?? $right['total'] ?? 0) <=> Support::toFloat($left['gross_total'] ?? $left['total'] ?? 0)
    );

    $topProducts = $canonicalProducts !== []
        ? array_map(static fn (array $row): array => [
            'product_code' => (string) ($row['product_code'] ?? ''),
            'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
            'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
            'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
            'orders' => (int) ($row['orders'] ?? 0),
        ], $canonicalProducts)
        : (is_array($snapshot['top_products'] ?? null) ? $snapshot['top_products'] : []);
    $topClients = array_map(static fn (array $row): array => [
        'client_id' => (string) ($row['codigo_cliente_omie'] ?? ''),
        'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
        'client_document' => (string) ($row['client_document'] ?? ''),
        'notes_count' => (int) ($row['notes_count'] ?? 0),
        'invoice_total' => Support::money((float) ($row['invoice_total'] ?? 0)),
        'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
        'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
        'share_pct' => $grossRevenue > 0 ? round(((float) ($row['invoice_total'] ?? 0) / $grossRevenue) * 100, 2) : 0.0,
    ], $canonicalClients);
    $top5ClientConcentration = $grossRevenue > 0
        ? round(array_sum(array_map(static fn (array $row): float => (float) ($row['invoice_total'] ?? 0), array_slice($canonicalClients, 0, 5))) / $grossRevenue * 100, 2)
        : 0.0;
    $alerts = is_array($snapshot['alerts'] ?? null) ? $snapshot['alerts'] : [];
    if ($topClients !== []) {
        $firstClient = $topClients[0] ?? null;
        $topShare = (float) ($firstClient['share_pct'] ?? 0);
        if ($topShare >= 35) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Concentracao elevada em cliente',
                'message' => sprintf('O principal cliente concentrou %.1f%% da receita fiscal do periodo.', $topShare),
                'metric' => 'Top cliente',
                'value' => $topShare,
            ];
        }
    }
    if ($alerts === []) {
        foreach ($divergences as $divergence) {
            $alerts[] = [
                'level' => 'warning',
                'title' => (string) ($divergence['tipo'] ?? 'Divergencia'),
                'message' => (string) ($divergence['descricao'] ?? 'Inconsistencia detectada na camada canonica.'),
                'metric' => 'Divergencia',
                'value' => 1,
            ];
        }
    }

    $mode = $snapshot !== null ? 'hybrid' : 'canonical';
    $source = $snapshot !== null ? 'canonical+legacy' : 'canonical';
    $monthComparison = buildOverviewMonthComparison($canonical, $targetsRepository, $start, $saturdayWeight);

    return [
        'ok' => true,
        'mode' => $mode,
        'source' => $source,
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => $range['label'],
        ],
        'summary' => [
            'revenue' => $grossRevenue,
            'gross_revenue' => $grossRevenue,
            'merchandise_total' => Support::money((float) ($summaryRow['merchandise_total'] ?? $merchandiseTotal)),
            'discounts_total' => Support::money((float) ($summaryRow['discounts_total'] ?? $discountsTotal)),
            'orders' => $ordersCount,
            'avg_ticket' => $ordersCount > 0 ? Support::money($grossRevenue / $ordersCount) : 0.0,
            'active_vendors' => count(array_filter(
                $vendorRanking,
                static fn (array $row): bool => trim((string) ($row['vendor_name'] ?? '')) !== ''
                    && strcasecmp((string) ($row['vendor_name'] ?? ''), 'Sem vendedor') !== 0
            )),
            'active_clients' => (int) ($summaryRow['active_clients'] ?? count($topClients)),
            'top5_client_concentration_pct' => $top5ClientConcentration,
            'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
            'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
            'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
            'pending_orders' => (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0),
            'pending_value' => Support::money((float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0)),
            'cancelled_orders' => (int) ($pendingSummary['cancelled_orders'] ?? 0),
            'cancelled_value' => Support::money((float) ($pendingSummary['cancelled_value'] ?? 0)),
        ],
        'daily_sales' => $dailySales,
        'vendor_ranking' => $vendorRanking,
        'top_orders' => array_slice($orders, 0, 8),
        'top_products' => $topProducts,
        'top_clients' => $topClients,
        'month_comparison' => $monthComparison,
        'alerts' => $alerts,
        'meta' => [
            'generated_at' => $lastSync,
            'served_from' => $source === 'canonical' ? 'canonical-db' : 'canonical-db+legacy-snapshot',
            'last_sync' => $lastSync,
            'period_basis' => 'issue_date',
            'request_id' => RequestContext::id(),
            'snapshot_fallback_error' => $snapshotError,
            'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
        ],
    ];
}

/**
 * @param array<string,mixed> $payload
 */
function canonicalOverviewHasSignal(array $payload): bool
{
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $revenue = Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
    $orders = (int) ($summary['orders'] ?? 0);
    $pendingOrders = (int) ($summary['pending_orders'] ?? 0);
    $activeVendors = (int) ($summary['active_vendors'] ?? 0);

    return $revenue > 0
        || $orders > 0
        || $pendingOrders > 0
        || $activeVendors > 0
        || rowsContainOverviewSignal($payload['daily_sales'] ?? [], ['revenue', 'gross_revenue', 'orders'])
        || rowsContainOverviewSignal($payload['vendor_ranking'] ?? [], ['revenue', 'gross_revenue', 'orders']);
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $coverage
 * @return array<string,mixed>
 */
function enrichOverviewMeta(array $payload, array $coverage, ?string $fallbackReason): array
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
 * @param array<string,mixed> $payload
 */
function historicalOverviewHasStrongSignal(array $payload): bool
{
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $revenue = Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
    $orders = (int) ($summary['orders'] ?? 0);
    $activeVendors = (int) ($summary['active_vendors'] ?? 0);
    $activeClients = (int) ($summary['active_clients'] ?? 0);

    return $revenue > 0
        || $orders > 0
        || $activeVendors > 0
        || $activeClients > 0
        || rowsContainOverviewSignal($payload['daily_sales'] ?? [], ['revenue', 'gross_revenue', 'orders'])
        || rowsContainOverviewSignal($payload['vendor_ranking'] ?? [], ['revenue', 'gross_revenue', 'orders']);
}

/**
 * @param mixed $rows
 * @param list<string> $keys
 */
function rowsContainOverviewSignal(mixed $rows, array $keys): bool
{
    if (!is_array($rows)) {
        return false;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($keys as $key) {
            if (Support::toFloat($row[$key] ?? 0) > 0) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return list<array<string,mixed>>
 */
function buildOverviewMonthComparison(
    CanonicalRepository $canonical,
    ?TargetsRepository $targetsRepository,
    string $start,
    float $saturdayWeight
): array {
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new DateTimeImmutable('today');
    $anchor = $anchor->modify('first day of this month');

    $months = [];
    for ($offset = 3; $offset >= 0; $offset--) {
        $monthStart = $anchor->modify('-' . $offset . ' months')->modify('first day of this month');
        $months[] = $monthStart;
    }

    $targetMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths(array_map(
            static fn (DateTimeImmutable $month): string => $month->format('Y-m'),
            $months
        ))
        : [];

    $currentMonth = (new DateTimeImmutable('today'))->format('Y-m');
    $comparison = [];

    foreach ($months as $monthStart) {
        $monthKey = $monthStart->format('Y-m');
        $monthEnd = $monthStart->modify('last day of this month');
        $rangeStart = $monthStart->format('Y-m-d');
        $rangeEnd = $monthEnd->format('Y-m-d');

        $summary = $canonical->summarizeInvoicesCommercial($rangeStart, $rangeEnd);
        $pending = $canonical->summarizePendingCommercial($rangeStart, $rangeEnd);
        $revenue = Support::money((float) ($summary['billed_value'] ?? 0));
        $orders = (int) ($summary['billed_orders'] ?? 0);
        $target = 0.0;

        foreach ($targetMap as $targetRow) {
            if ((string) ($targetRow['year_month'] ?? '') !== $monthKey) {
                continue;
            }

            $target += Support::toFloat($targetRow['target_amount'] ?? 0);
        }

        $target = Support::money($target);
        $forecast = $revenue;
        if ($monthKey === $currentMonth) {
            $workdays = \BIomie\WorkingDays::summarize($rangeStart, $rangeEnd, new DateTimeImmutable('today'), $saturdayWeight);
            $pipeline = Support::money(
                (float) ($pending['authorized_value'] ?? 0)
                + (float) ($pending['quote_value'] ?? 0)
            );
            $forecast = Support::money(
                (float) (\BIomie\CommercialPlanning::calculateForecast(
                    $revenue,
                    $pipeline,
                    $workdays,
                    new DateTimeImmutable('today'),
                    $rangeStart,
                    $rangeEnd
                )['probable'] ?? $revenue)
            );
        }

        $comparison[] = [
            'competencia' => $monthKey,
            'label' => ucfirst($monthStart->format('M/y')),
            'revenue' => $revenue,
            'orders' => $orders,
            'target' => $target,
            'forecast' => $forecast,
            'attainment_pct' => $target > 0 ? round(($revenue / $target) * 100, 2) : 0.0,
            'is_current' => $monthKey === $anchor->format('Y-m'),
        ];
    }

    return $comparison;
}
