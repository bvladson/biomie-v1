<?php

declare(strict_types=1);

use BIomie\CanonicalCoverage;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);
    $freshness = DatasetFreshnessService::boot($config, $logger);

    $start = $range['start'];
    $end = $range['end'];
    $force = Http::boolQuery('force', false);
    $productCode = trim((string) Http::query('product_code', ''));
    $breakdownLimit = max(1, min(500, (int) Http::query('limit', '200')));

    $snapshot = null;
    $snapshotError = null;
    try {
        $snapshot = $analytics->getSnapshot($start, $end, $force);
    } catch (Throwable $exception) {
        $snapshotError = $exception->getMessage();
        $logger->warning('Products: snapshot legado indisponivel.', [
            'message' => $exception->getMessage(),
            'period' => ['start' => $start, 'end' => $end],
            'request_id' => RequestContext::id(),
        ]);
    }

    $canonical = null;
    $canonicalSummary = null;
    $canonicalPendingSummary = null;
    $canonicalVendorRows = [];
    $canonicalProducts = [];
    $canonicalBreakdown = [];
    $canonicalLastSync = null;
    $canonicalCoverage = null;
    $canonicalFallbackReason = null;

    if ($config->isCanonicalEnabled('orders') && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $connection)) {
            try {
                $pdo = Database::connect($config, $connection);
                $canonical = new CanonicalRepository($pdo, $logger);
                if ($canonical->isAvailable()) {
                    $canonicalCoverage = $canonical->invoiceCoverageDiagnostics($start, $end);
                    $canonicalLastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM));
                    $canonicalSummary = $canonical->summarizeInvoicesCommercial($start, $end);
                    $canonicalPendingSummary = $canonical->summarizePendingCommercial($start, $end);
                    $canonicalVendorRows = $canonical->aggregateInvoicesByVendorCommercial($start, $end);
                    $canonicalProducts = $canonical->topProductsCommercial($start, $end, 16);
                    if ($productCode !== '' && CanonicalCoverage::canServe($canonicalCoverage)) {
                        $canonicalBreakdown = $canonical->productDocumentBreakdownCommercial($start, $end, $productCode, $breakdownLimit);
                    }
                }
            } catch (Throwable $exception) {
                $canonicalFallbackReason = $exception->getMessage();
                $logger->warning('Products: leitura canonical indisponivel.', [
                    'message' => $exception->getMessage(),
                    'request_id' => RequestContext::id(),
                ]);
            }
        }
    }

    if ($snapshot === null && $canonicalSummary === null) {
        Http::json([
            'ok' => false,
            'message' => 'Nao foi possivel carregar mercadorias no momento.',
            'request_id' => RequestContext::id(),
        ], 500);
    }

    $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
    $products = is_array($snapshot['top_products'] ?? null) ? $snapshot['top_products'] : [];
    $period = is_array($snapshot['period'] ?? null) ? $snapshot['period'] : [
        'start' => $start,
        'end' => $end,
        'label' => $range['label'],
    ];
    $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
    $mode = (string) ($snapshot['mode'] ?? 'legacy');
    $source = 'legacy';
    $breakdown = [];

    if ($canonicalSummary !== null && (CanonicalCoverage::canServe($canonicalCoverage ?? []) || $snapshot === null)) {
        $grossRevenue = Support::money(Support::toFloat($canonicalSummary['billed_value'] ?? 0));
        $ordersCount = (int) ($canonicalSummary['billed_orders'] ?? 0);

        $merchandiseTotal = 0.0;
        $discountsTotal = 0.0;
        $activeVendors = [];
        foreach ($canonicalVendorRows as $row) {
            $merchandiseTotal += Support::toFloat($row['mercadorias'] ?? 0);
            $discountsTotal += Support::toFloat($row['descontos'] ?? 0);

            $vendorName = trim((string) ($row['nome_vendedor'] ?? ''));
            if ($vendorName !== '' && strcasecmp($vendorName, 'Sem vendedor') !== 0) {
                $activeVendors[mb_strtolower($vendorName)] = true;
            }
        }

        $summary['revenue'] = $grossRevenue;
        $summary['gross_revenue'] = $grossRevenue;
        $summary['merchandise_total'] = Support::money($merchandiseTotal);
        $summary['discounts_total'] = Support::money($discountsTotal);
        $summary['orders'] = $ordersCount;
        $summary['avg_ticket'] = $ordersCount > 0 ? Support::money($grossRevenue / $ordersCount) : 0.0;
        $summary['active_vendors'] = count($activeVendors);
        $summary['pending_orders'] = (int) ($canonicalPendingSummary['authorized_orders'] ?? 0) + (int) ($canonicalPendingSummary['quote_orders'] ?? 0);
        $summary['pending_value'] = Support::money((float) ($canonicalPendingSummary['authorized_value'] ?? 0) + (float) ($canonicalPendingSummary['quote_value'] ?? 0));
        $summary['cancelled_orders'] = (int) ($canonicalPendingSummary['cancelled_orders'] ?? 0);
        $summary['cancelled_value'] = Support::money((float) ($canonicalPendingSummary['cancelled_value'] ?? 0));
        $summary['target'] = Support::toFloat($summary['target'] ?? 0);
        $summary['forecast'] = Support::toFloat($summary['forecast'] ?? 0);
        $summary['goal_progress'] = Support::toFloat($summary['goal_progress'] ?? 0);

        $products = array_map(static fn (array $row): array => [
            'product_code' => (string) ($row['product_code'] ?? ''),
            'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
            'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
            'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
            'orders' => (int) ($row['orders'] ?? 0),
        ], $canonicalProducts);
        $breakdown = $canonicalBreakdown;
        $mode = 'canonical';
        $source = 'canonical-db';

        if ($canonicalProducts === []) {
            $meta['note'] = 'Nao ha itens fiscais materializados para o ranking por produto no periodo.';
        }
    } elseif ($canonicalSummary !== null) {
        $canonicalFallbackReason = 'Camada canonical disponivel, mas ainda sem sincronizacao/cobertura suficiente para o periodo.';
        $logger->warning('Products: canonical sem cobertura suficiente no periodo; mantendo snapshot legado.', [
            'period' => ['start' => $start, 'end' => $end],
            'coverage' => $canonicalCoverage,
            'request_id' => RequestContext::id(),
        ]);
    }

    $meta = array_merge($meta, [
        'generated_at' => $canonicalLastSync ?? ($meta['generated_at'] ?? date(DATE_ATOM)),
        'last_sync' => $canonicalLastSync,
        'served_from' => $source === 'legacy' ? (string) ($meta['served_from'] ?? 'legacy-cache') : $source,
        'snapshot_error' => $snapshotError,
        'request_id' => RequestContext::id(),
        'period_basis' => $canonicalSummary !== null ? 'issue_date' : ($meta['period_basis'] ?? null),
        'selected_product_code' => $productCode !== '' ? $productCode : null,
    ]);

    $payload = [
        'ok' => true,
        'mode' => $mode,
        'source' => $source,
        'period' => $period,
        'summary' => $summary,
        'products' => $products,
        'breakdown' => $breakdown,
        'meta' => $meta,
    ];

    $payload = $freshness->attach('orders', $payload, ['observe_request' => true]);
    $payload['meta'] = CanonicalCoverage::decorateMeta(
        is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        is_array($canonicalCoverage) ? $canonicalCoverage : [
            'requested_start' => $start,
            'requested_end' => $end,
            'has_any_rows' => false,
            'last_sync_at' => null,
        ],
        $source === 'legacy' ? $canonicalFallbackReason : null
    );
    $payload['meta']['sync_state'] = [
        'reconcile_status' => $payload['meta']['reconcile_status'] ?? null,
        'has_retry_or_delay' => $payload['meta']['has_retry_or_delay'] ?? null,
        'open_divergences' => $payload['meta']['open_divergences'] ?? null,
        'data_mode' => $payload['meta']['data_mode'] ?? null,
    ];

    Http::json($payload);
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha em api/products.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar mercadorias.',
        'request_id' => RequestContext::id(),
    ], 500);
}
