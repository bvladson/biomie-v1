<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Modules\Sales\Application\Overview\OverviewRequest;
use BIomie\Omie\OmieClientFactory;
use BIomie\Support\TenantContext;
use DateTimeImmutable;

final class HomeBootstrapService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $start, string $end): array
    {
        $ttl = Cache::adaptiveTtl($end, 60, 3600);

        return $this->cache->getOrCompute(
            self::cacheKey($start, $end),
            $ttl,
            fn (): array => $this->computeBundle($start, $end)
        );
    }

    public static function cacheKey(string $start, string $end): string
    {
        return sprintf(
            'home_bootstrap_v3_%s_%s',
            OverviewRequest::tenantCacheScope(),
            md5($start . '_' . $end),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function computeBundle(string $start, string $end): array
    {
        $range = [
            'start' => $start,
            'end' => $end,
            'label' => $this->formatLabel($start, $end),
        ];

        $freshness = DatasetFreshnessService::boot($this->config, $this->logger);
        $sales = $this->makeSalesAnalytics();
        $snapshot = $sales->getSnapshot($start, $end, false);

        [$canonical, $targetsRepository] = $this->loadCanonicalContext();

        $overview = $this->loadCachedOverviewPayload($start, $end);
        if (!is_array($overview)) {
            $overview = LegacyEndpointPayloadFactory::overview($snapshot, $freshness);
            $overview['source'] = $overview['source'] ?? 'bootstrap_snapshot';
            $overview['meta'] = is_array($overview['meta'] ?? null) ? $overview['meta'] : [];
            $overview['meta']['served_from'] = (string) ($overview['meta']['served_from'] ?? 'bootstrap-snapshot');

            if ($canonical !== null) {
                $overview['top_clients'] = $this->buildTopClients($canonical, $start, $end);
                $overview['month_comparison'] = $this->buildMonthComparison($canonical, $targetsRepository, $start, (float) $this->config->get('flight_plan.saturday_weight', 0.33));
            } else {
                $overview['top_clients'] = $overview['top_clients'] ?? [];
                $overview['month_comparison'] = $overview['month_comparison'] ?? [];
            }
        }
        $overview = $this->ensureMonthComparisonContract($overview, $start, $end);
        if ($overview['month_comparison'] === [] && $canonical !== null) {
            try {
                $overview['month_comparison'] = $this->buildMonthComparison(
                    $canonical,
                    $targetsRepository,
                    $start,
                    (float) $this->config->get('flight_plan.saturday_weight', 0.33)
                );
                $overview = $this->ensureMonthComparisonContract($overview, $start, $end);
            } catch (\Throwable $exception) {
                $this->logger->warning('Home bootstrap: comparativo mensal indisponivel.', [
                    'message' => $exception->getMessage(),
                ]);
                $overview['diagnostics'] = is_array($overview['diagnostics'] ?? null) ? $overview['diagnostics'] : [];
                $overview['diagnostics']['month_comparison'] = is_array($overview['diagnostics']['month_comparison'] ?? null)
                    ? $overview['diagnostics']['month_comparison']
                    : [];
                $overview['diagnostics']['month_comparison']['build_error'] = $exception->getMessage();
            }
        }

        $vendors = LegacyEndpointPayloadFactory::vendors($snapshot, $range + ['competencia' => null], 'bootstrap_snapshot');
        $vendors['meta'] = is_array($vendors['meta'] ?? null) ? $vendors['meta'] : [];
        $vendors['meta']['served_from'] = (string) ($vendors['meta']['served_from'] ?? 'bootstrap-snapshot');

        $health = $this->buildCommercialHealth($start, $end, $canonical);
        $geoIndex = $this->buildGeoIndex($start, $end);

        return [
            'range' => $range,
            'generated_at' => date(DATE_ATOM),
            'dashboard' => [
                'overview' => $overview,
                'vendors' => $vendors,
                'health' => $health,
            ],
            'geo_index' => $geoIndex,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadCachedOverviewPayload(string $start, string $end): ?array
    {
        $cacheKey = OverviewRequest::cacheKeyForRange($start, $end);
        $payload = $this->cache->read($cacheKey);
        return is_array($payload) ? $payload : null;
    }

    private function makeSalesAnalytics(): SalesAnalyticsService
    {
        $repository = null;
        if (!TenantContext::isConsolidado()) {
            try {
                $bronze = BronzeRepository::boot($this->config, $this->logger);
                $client = OmieClientFactory::boot($this->config, $this->logger, $this->cache)
                    ->forCompany(TenantContext::companyId());
                $repository = new OmieRepository($client, $this->config, $bronze, 'home-bootstrap-sales');
            } catch (\RuntimeException $exception) {
                $this->logger->warning('Home bootstrap sem integracao Omie para o tenant.', [
                    'company_id' => TenantContext::companyId(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $queue = new RefreshQueue(Bootstrap::rootPath('storage/cache'), $this->logger);

        return new SalesAnalyticsService($this->config, $this->cache, $this->logger, $repository, $queue);
    }

    private function makeFinanceAnalytics(): ?FinanceAnalyticsService
    {
        if (TenantContext::isConsolidado()) {
            return null;
        }

        try {
            $bronze = BronzeRepository::boot($this->config, $this->logger);
            $client = OmieClientFactory::boot($this->config, $this->logger, $this->cache)
                ->forCompany(TenantContext::companyId());
        } catch (\RuntimeException $exception) {
            $this->logger->warning('Finance bootstrap sem integracao Omie para o tenant.', [
                'company_id' => TenantContext::companyId(),
                'message' => $exception->getMessage(),
            ]);
            return null;
        }

        $salesRepository = new OmieRepository($client, $this->config, $bronze, 'home-bootstrap-finance');
        $financeRepository = new FinanceRepository($client, $this->config, $this->logger, $this->cache, $bronze, 'home-bootstrap-finance');
        $queue = new RefreshQueue(Bootstrap::rootPath('storage/cache'), $this->logger);
        $finance = new FinanceAnalyticsService($this->config, $this->cache, $this->logger, $financeRepository, $salesRepository, $queue);

        [$canonical] = $this->loadCanonicalContext();
        if ($canonical !== null) {
            $extraMap = [];
            foreach ($canonical->getVendorMap() as $codigo => $row) {
                $name = trim((string) ($row['nome'] ?? ''));
                if ($name !== '') {
                    $extraMap[(string) $codigo] = $name;
                }
            }
            if ($extraMap !== []) {
                $finance->withExtraVendorMap($extraMap);
            }
        }

        return $finance;
    }

    /**
     * @return array{0:?CanonicalRepository,1:?TargetsRepository}
     */
    private function loadCanonicalContext(): array
    {
        try {
            $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            if (!Database::isConfigured($this->config, $connection)) {
                return [null, null];
            }

            $pdo = Database::connect($this->config, $connection);
            $reconnect = fn (): \PDO => Database::connect($this->config, $connection);
            $canonical = new CanonicalRepository($pdo, $this->logger, $reconnect);
            if (!$canonical->isAvailable()) {
                return [null, null];
            }

            $targetsRepository = null;
            try {
                $targetsRepository = new TargetsRepository($pdo, $this->logger);
            } catch (\Throwable) {
                $targetsRepository = null;
            }

            return [$canonical, $targetsRepository];
        } catch (\Throwable $exception) {
            $this->logger->warning('Home bootstrap: camada canonical indisponivel.', [
                'message' => $exception->getMessage(),
            ]);

            return [null, null];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildTopClients(CanonicalRepository $canonical, string $start, string $end): array
    {
        $summary = $canonical->summarizeInvoicesCommercial($start, $end);
        $grossRevenue = Support::toFloat($summary['billed_value'] ?? 0);
        $rows = $canonical->topClientsCommercial($start, $end, 8);

        return array_map(static function (array $row) use ($grossRevenue): array {
            $invoiceTotal = (float) ($row['invoice_total'] ?? 0);

            return [
                'client_id' => (string) ($row['codigo_cliente_omie'] ?? ''),
                'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
                'client_document' => (string) ($row['client_document'] ?? ''),
                'notes_count' => (int) ($row['notes_count'] ?? 0),
                'invoice_total' => Support::money($invoiceTotal),
                'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
                'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
                'last_purchase' => (string) ($row['last_purchase'] ?? ''),
                'share_pct' => $grossRevenue > 0 ? round(($invoiceTotal / $grossRevenue) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildMonthComparison(
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        string $start,
        float $saturdayWeight,
    ): array {
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new DateTimeImmutable('today');
        $anchor = $anchor->modify('first day of this month');

        $months = [];
        for ($offset = 11; $offset >= 0; $offset--) {
            $months[] = $anchor->modify('-' . $offset . ' months');
        }

        $targetMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(array_map(
                static fn (DateTimeImmutable $month): string => $month->format('Y-m'),
                $months
            ))
            : [];

        $currentMonth = (new DateTimeImmutable('today'))->format('Y-m');
        $rows = [];

        foreach ($months as $monthStart) {
            $monthKey = $monthStart->format('Y-m');
            $rangeStart = $monthStart->format('Y-m-01');
            $rangeEnd = $monthStart->modify('last day of this month')->format('Y-m-d');
            $summary = $canonical->summarizeInvoicesCommercial($rangeStart, $rangeEnd);
            $revenue = (float) ($summary['billed_value'] ?? 0);
            $orders = (int) ($summary['billed_orders'] ?? 0);
            $target = 0.0;

            foreach ($targetMap as $targetRow) {
                if ($targetRow['year_month'] === $monthKey) {
                    $target += Support::toFloat($targetRow['target_amount']);
                }
            }

            $forecast = $revenue;
            if ($monthKey === $currentMonth) {
                $pending = $canonical->summarizePendingCommercial($rangeStart, $rangeEnd);
                $workdays = WorkingDays::summarize($rangeStart, $rangeEnd, new DateTimeImmutable('today'), $saturdayWeight);
                $pipeline = Support::money(
                    (float) ($pending['authorized_value'] ?? 0)
                    + (float) ($pending['quote_value'] ?? 0)
                );
                $forecast = Support::money(
                    (float) (CommercialPlanning::calculateForecast(
                        $revenue,
                        $pipeline,
                        $workdays,
                        new DateTimeImmutable('today'),
                        $rangeStart,
                        $rangeEnd
                    )['probable'] ?? $revenue)
                );
            }

            $rows[] = [
                'competencia' => $monthKey,
                'label' => ucfirst($monthStart->format('M/y')),
                'revenue' => Support::money($revenue),
                'orders' => $orders,
                'target' => Support::money($target),
                'forecast' => Support::money($forecast),
                'attainment_pct' => $target > 0 ? round(($revenue / $target) * 100, 2) : 0.0,
                'is_current' => $monthKey === $anchor->format('Y-m'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function ensureMonthComparisonContract(array $payload, string $start, string $end): array
    {
        $sourceKey = '';
        foreach (['month_comparison', 'monthly_comparison', 'monthlyComparison'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $sourceKey = $key;
                $value = $payload[$key];
                $payload['month_comparison'] = array_key_exists('rows', $value)
                    ? (is_array($value['rows'] ?? null) ? array_values($value['rows']) : [])
                    : array_values($value);
                break;
            }
        }

        if (!is_array($payload['month_comparison'] ?? null)) {
            $payload['month_comparison'] = [];
        }

        $payload['diagnostics'] = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
        if ($payload['month_comparison'] !== []) {
            unset($payload['diagnostics']['month_comparison']);
            return $payload;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['diagnostics']['month_comparison'] = [
            'status' => 'empty',
            'reason' => $sourceKey === '' ? 'chave month_comparison ausente no payload original' : 'dataset sem linhas para o periodo',
            'expected_key' => 'month_comparison',
            'accepted_aliases' => ['monthly_comparison', 'monthlyComparison'],
            'source_key' => $sourceKey !== '' ? $sourceKey : null,
            'period_start' => (string) ($meta['period_start'] ?? $start),
            'period_end' => (string) ($meta['period_end'] ?? $end),
            'served_from' => (string) ($meta['served_from'] ?? ($payload['source'] ?? '')),
            'rows_count' => 0,
        ];

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCommercialHealth(string $start, string $end, ?CanonicalRepository $canonical): array
    {
        $billed = [
            'invoice_total' => 0.0,
            'merchandise_total' => 0.0,
            'notes_count' => 0,
        ];

        if ($canonical !== null) {
            try {
                $billed = $canonical->summarizeInvoicedCommercial($start, $end);
            } catch (\Throwable $exception) {
                $this->logger->warning('Home bootstrap: resumo faturado indisponivel no canonical.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $finance = $this->makeFinanceAnalytics();
        if ($finance === null) {
            return [
                'ok' => true,
                'available' => false,
                'source' => 'finance-unconfigured',
                'period' => ['start' => $start, 'end' => $end, 'label' => $this->formatLabel($start, $end)],
                'summary' => [
                    'invoice_total' => Support::money((float) ($billed['invoice_total'] ?? 0)),
                    'merchandise_total' => Support::money((float) ($billed['merchandise_total'] ?? 0)),
                    'notes_count' => (int) ($billed['notes_count'] ?? 0),
                ],
                'message' => 'Modulo financeiro Omie nao configurado para enriquecer o painel comercial.',
            ];
        }

        try {
            $snapshot = $finance->getSnapshot($start, $end, false);
            $financeSummary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
            $received = Support::toFloat($financeSummary['received'] ?? 0);
            $open = Support::toFloat($financeSummary['open'] ?? 0);
            $overdue = Support::toFloat($financeSummary['overdue'] ?? 0);
            $invoiceTotal = Support::toFloat($billed['invoice_total'] ?? 0);
            $merchandiseTotal = Support::toFloat($billed['merchandise_total'] ?? 0);

            return [
                'ok' => true,
                'available' => true,
                'source' => 'finance-bootstrap',
                'period' => ['start' => $start, 'end' => $end, 'label' => $this->formatLabel($start, $end)],
                'summary' => [
                    'invoice_total' => Support::money($invoiceTotal),
                    'merchandise_total' => Support::money($merchandiseTotal),
                    'notes_count' => (int) ($billed['notes_count'] ?? 0),
                    'received' => $received,
                    'open' => $open,
                    'overdue' => $overdue,
                    'delinquency_rate' => Support::toFloat($financeSummary['delinquency_rate'] ?? 0),
                    'realized_vs_planned' => Support::toFloat($financeSummary['realized_vs_planned'] ?? 0),
                    'cash_conversion_pct' => $invoiceTotal > 0 ? round(($received / $invoiceTotal) * 100, 2) : 0.0,
                    'overdue_exposure_pct' => $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0,
                    'received_titles' => (int) ($financeSummary['received_titles'] ?? 0),
                    'open_titles' => (int) ($financeSummary['open_titles'] ?? 0),
                    'overdue_titles' => (int) ($financeSummary['overdue_titles'] ?? 0),
                ],
                'top_overdue_clients' => array_map(static fn (array $row): array => [
                    'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
                    'client_document' => (string) ($row['client_document'] ?? ''),
                    'overdue' => Support::money((float) ($row['overdue'] ?? 0)),
                    'titles' => (int) ($row['titles'] ?? 0),
                    'max_days_late' => (int) ($row['max_days_late'] ?? 0),
                    'dominant_document_type' => (string) ($row['dominant_document_type'] ?? ''),
                    'dominant_payment_method' => (string) ($row['dominant_payment_method'] ?? ''),
                    'cause_label' => (string) ($row['cause_label'] ?? ''),
                ], array_slice((array) ($snapshot['by_client'] ?? []), 0, 5)),
                'payment_methods' => array_map(static fn (array $row): array => [
                    'payment_method' => (string) ($row['payment_method'] ?? 'Nao identificado'),
                    'received' => Support::money((float) ($row['received'] ?? 0)),
                    'titles' => (int) ($row['titles'] ?? 0),
                ], array_slice((array) ($snapshot['payment_methods'] ?? []), 0, 5)),
                'meta' => [
                    'finance_generated_at' => (string) ($snapshot['meta']['generated_at'] ?? date(DATE_ATOM)),
                    'finance_served_from' => (string) ($snapshot['meta']['served_from'] ?? 'live-build'),
                ],
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Home bootstrap: widget financeiro indisponivel.', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => true,
                'available' => false,
                'source' => 'finance-error',
                'period' => ['start' => $start, 'end' => $end, 'label' => $this->formatLabel($start, $end)],
                'summary' => [],
                'message' => 'Nao foi possivel enriquecer o painel comercial com dados de recebimento agora.',
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildGeoIndex(string $start, string $end): array
    {
        try {
            [$pdo, $sourceDb] = Database::getGeoConnection($this->config);
            $geo = new GeoAnalyticsService($pdo, $this->logger);

            return [
                'summary' => $geo->summarize($start, $end, 'uf_brasil'),
                'cities' => $geo->salesByCity($start, $end, 'ALL', 'city'),
                'neighborhoods' => $geo->salesByNeighborhood($start, $end, '2304400'),
                'meta' => [
                    'source_db' => $sourceDb,
                    'generated_at' => date(DATE_ATOM),
                ],
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Home bootstrap: camada geo indisponivel.', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'summary' => null,
                'cities' => ['data' => [], 'meta' => ['source_mode' => 'unavailable']],
                'neighborhoods' => ['data' => [], 'meta' => ['source_mode' => 'unavailable']],
                'meta' => [
                    'error' => $exception->getMessage(),
                    'generated_at' => date(DATE_ATOM),
                ],
            ];
        }
    }

    private function formatLabel(string $start, string $end): string
    {
        return date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end));
    }
}
