<?php

declare(strict_types=1);

use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\CanonicalCoverage;
use BIomie\CanonicalRepository;
use BIomie\CommercialPlanning;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Http;
use BIomie\LegacyEndpointPayloadFactory;
use BIomie\ShadowValidationService;
use BIomie\Support;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);
    $start = $range['start'];
    $end = $range['end'];
    $forceRefresh = Http::boolQuery('force', false);
    $statusFilter = Http::query('status', '');
    $canonicalFallbackReason = null;
    $canonicalCoverage = null;

    // ── Canonical: ativa quando a camada estiver disponível ─────────────────
    $useCanonical = false;
    $targetsRepository = null;

    if ($config->isCanonicalEnabled('orders') && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $connection)) {
            try {
                $pdo = Database::connect($config, $connection);
                $repo = new CanonicalRepository($pdo, $logger);
                if ($repo->isAvailable()) {
                    $useCanonical = true;
                    $canonicalCoverage = $repo->commercialCoverageDiagnostics($start, $end);
                    try {
                        $targetsRepository = new TargetsRepository($pdo, $logger);
                    } catch (Throwable) {
                        $targetsRepository = null;
                    }
                }
            } catch (Throwable $e) {
                $logger->warning('Orders: canonical check falhou.', ['message' => $e->getMessage()]);
            }
        }
    }

    if ($useCanonical) {
        $orders = $repo->listInvoicesForDisplayCommercial($start, $end);
        $pendingOrders = $repo->listPendingOrdersForDisplayCommercial($start, $end);
        $cancelledOrders = $repo->listCancelledInvoicesForDisplayCommercial($start, $end);
        $invoiceSummary = $repo->summarizeInvoicesCommercial($start, $end);
        $pendingSummary = $repo->summarizePendingCommercial($start, $end);
        $lastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM));
        $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($repo->aggregateByVendorCommercial($start, $end), $targetsMap, $start, $end, (float) $config->get('flight_plan.saturday_weight', 0.33));

        $grossRevenue = (float) ($invoiceSummary['billed_value'] ?? 0);
        $ordersCount = (int) ($invoiceSummary['billed_orders'] ?? 0);
        $pendingCount = (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0);
        $activeVendors = (int) ($invoiceSummary['active_vendors'] ?? 0);

        $canonicalPayload = [
            'ok' => true,
            'mode' => 'canonical',
            'source' => 'canonical',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
            ],
            'summary' => [
                'revenue' => Support::money($grossRevenue),
                'gross_revenue' => Support::money($grossRevenue),
                'merchandise_total' => Support::money((float) ($invoiceSummary['merchandise_total'] ?? 0)),
                'discounts_total' => Support::money((float) ($invoiceSummary['discounts_total'] ?? 0)),
                'orders' => $ordersCount,
                'avg_ticket' => $ordersCount > 0 ? Support::money($grossRevenue / $ordersCount) : 0,
                'active_vendors' => $activeVendors,
                'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
                'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
                'pending_orders' => $pendingCount,
                'pending_value' => Support::money((float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0)),
                'quote_orders' => (int) ($pendingSummary['quote_orders'] ?? 0),
                'cancelled_orders' => (int) ($pendingSummary['cancelled_orders'] ?? 0),
                'cancelled_value' => Support::money((float) ($pendingSummary['cancelled_value'] ?? 0)),
            ],
            'orders' => $orders,
            'pending_orders' => $pendingOrders,
            'cancelled_orders' => $cancelledOrders,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => 'canonical-db',
                'last_sync' => $lastSync,
                'period_basis' => 'issue_date',
                'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
                'table_kind' => 'fiscal_invoices+pending_orders',
            ],
        ];

        $canonicalPayload = $freshness->attach('orders', $canonicalPayload, ['observe_request' => true]);
        $canonicalPayload = enrichOrdersMeta($canonicalPayload, $canonicalCoverage ?? [], null);

        $shadow->defer(
            'orders',
            static function () use ($analytics, $freshness, $start, $end, $forceRefresh, $statusFilter): ?array {
                return buildLegacyOrdersPayload($analytics->getSnapshot($start, $end, $forceRefresh), $freshness, $statusFilter);
            },
            static fn (): ?array => $canonicalPayload,
            [
                'start' => $start,
                'end' => $end,
                'status' => $statusFilter,
                'official_mode' => 'canonical',
            ]
        );
        Http::json(DataMasker::applyToPayload($canonicalPayload, Auth::canViewFullDocuments()));
    }

    // ── Fallback legado ──────────────────────────────────────────────────────
    $snapshot = $analytics->getSnapshot($start, $end, $forceRefresh);
    $legacyPayload = buildLegacyOrdersPayload($snapshot, $freshness, $statusFilter);
    $legacyPayload = enrichOrdersMeta(
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
        'orders',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $start, $end): ?array {
            return buildCanonicalOrdersShadowPayload($config, $logger, $freshness, $start, $end);
        },
        [
            'start' => $start,
            'end' => $end,
            'status' => $statusFilter,
            'official_mode' => 'legacy',
        ]
    );

    Http::json(DataMasker::applyToPayload($legacyPayload, Auth::canViewFullDocuments()));
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha em api/orders.', [
        'message' => $exception->getMessage(),
        'trace'   => $exception->getTraceAsString(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar pedidos. Tente novamente.'], 500);
}

/**
 * @return array<string,mixed>
 */
function buildLegacyOrdersPayload(array $snapshot, DatasetFreshnessService $freshness, string $statusFilter = ''): array
{
    return LegacyEndpointPayloadFactory::orders($snapshot, $freshness, $statusFilter);
}

/**
 * @return array<string,mixed>|null
 */
function buildCanonicalOrdersShadowPayload(\BIomie\Config $config, \BIomie\Logger $logger, DatasetFreshnessService $freshness, string $start, string $end): ?array
{
    if (
        !$config->isCanonicalEnabled('orders')
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

        $orders = $repo->listInvoicesForDisplayCommercial($start, $end);
        $pendingOrders = $repo->listPendingOrdersForDisplayCommercial($start, $end);
        $cancelledOrders = $repo->listCancelledInvoicesForDisplayCommercial($start, $end);
        $invoiceSummary = $repo->summarizeInvoicesCommercial($start, $end);
        $pendingSummary = $repo->summarizePendingCommercial($start, $end);
        $lastSync = $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM);
        $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build(
            $repo->aggregateByVendorCommercial($start, $end),
            $targetsMap,
            $start,
            $end,
            (float) $config->get('flight_plan.saturday_weight', 0.33)
        );

        $grossRevenue = (float) ($invoiceSummary['billed_value'] ?? 0);
        $ordersCount = (int) ($invoiceSummary['billed_orders'] ?? 0);
        $pendingCount = (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0);
        $activeVendors = (int) ($invoiceSummary['active_vendors'] ?? 0);

        $payload = [
            'ok' => true,
            'mode' => 'canonical',
            'source' => 'canonical',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'summary' => [
                'revenue' => Support::money($grossRevenue),
                'gross_revenue' => Support::money($grossRevenue),
                'merchandise_total' => Support::money((float) ($invoiceSummary['merchandise_total'] ?? 0)),
                'discounts_total' => Support::money((float) ($invoiceSummary['discounts_total'] ?? 0)),
                'orders' => $ordersCount,
                'avg_ticket' => $ordersCount > 0 ? Support::money($grossRevenue / $ordersCount) : 0,
                'active_vendors' => $activeVendors,
                'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
                'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
                'pending_orders' => $pendingCount,
                'pending_value' => Support::money((float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0)),
                'quote_orders' => (int) ($pendingSummary['quote_orders'] ?? 0),
                'cancelled_orders' => (int) ($pendingSummary['cancelled_orders'] ?? 0),
                'cancelled_value' => Support::money((float) ($pendingSummary['cancelled_value'] ?? 0)),
            ],
            'orders' => $orders,
            'pending_orders' => $pendingOrders,
            'cancelled_orders' => $cancelledOrders,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => 'canonical-db',
                'last_sync' => $lastSync,
                'period_basis' => 'issue_date',
                'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
                'table_kind' => 'fiscal_invoices+pending_orders',
            ],
        ];

        $payload = $freshness->attach('orders', $payload, ['observe_request' => true]);
        return canonicalOrdersPayloadHasSignal($payload) ? $payload : null;
    } catch (Throwable) {
        return null;
    }
}


/**
 * @return list<string>
 */
function buildVendorLookupKeys(mixed $vendorId): array
{
    $raw = trim((string) $vendorId);
    if ($raw === '') return [];
    $keys = [$raw];
    $digits = Support::digitsOnly($raw);
    if ($digits !== '') {
        $keys[] = $digits;
        $keys[] = ltrim($digits, '0') ?: '0';
    }
    return array_values(array_unique(array_filter($keys, static fn (string $value): bool => $value !== '')));
}

/**
 * @param array<string,mixed> $summary
 * @param list<array<string,mixed>> $orders
 * @return array<string,mixed>
 */
function rebuildOrdersSummary(array $summary, array $orders): array
{
    $gross = 0.0;
    $merchandise = 0.0;
    $discounts = 0.0;
    $vendors = [];

    foreach ($orders as $row) {
        $gross += Support::toFloat($row['gross_total'] ?? $row['total'] ?? 0);
        $merchandise += Support::toFloat($row['merchandise_total'] ?? 0);
        $discounts += Support::toFloat($row['discount_total'] ?? 0);
        $vendorName = trim((string) ($row['vendor_name'] ?? ''));
        if ($vendorName !== '' && strcasecmp($vendorName, 'Sem vendedor') !== 0) {
            $vendors[strtolower($vendorName)] = true;
        }
    }

    $ordersCount = count($orders);
    $summary['revenue'] = Support::money($gross);
    $summary['gross_revenue'] = Support::money($gross);
    $summary['merchandise_total'] = Support::money($merchandise);
    $summary['discounts_total'] = Support::money($discounts);
    $summary['net_after_discount'] = Support::money($merchandise - $discounts);
    $summary['orders'] = $ordersCount;
    $summary['avg_ticket'] = Support::money($ordersCount > 0 ? $gross / $ordersCount : 0);
    $summary['active_vendors'] = count($vendors);

    return $summary;
}

/**
 * @param array<string,mixed> $payload
 */
function canonicalOrdersPayloadHasSignal(array $payload): bool
{
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

    return Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0) > 0
        || (int) ($summary['orders'] ?? 0) > 0
        || (int) ($summary['pending_orders'] ?? 0) > 0
        || (int) ($summary['cancelled_orders'] ?? 0) > 0
        || (is_array($payload['orders'] ?? null) && count($payload['orders']) > 0)
        || (is_array($payload['pending_orders'] ?? null) && count($payload['pending_orders']) > 0)
        || (is_array($payload['cancelled_orders'] ?? null) && count($payload['cancelled_orders']) > 0);
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $coverage
 * @return array<string,mixed>
 */
function enrichOrdersMeta(array $payload, array $coverage, ?string $fallbackReason): array
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
