<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Semantic\KpiContextService;
use BIomie\DatasetFreshnessService;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;
use BIomie\ShadowValidationService;
use BIomie\TargetsRepository;
use RuntimeException;
use Throwable;

final class OverviewPayloadBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Cache $cache,
        private readonly SalesAnalyticsService $analytics,
        private readonly LegacyOverviewPayloadBuilder $legacyBuilder = new LegacyOverviewPayloadBuilder(),
        private readonly CanonicalOverviewPayloadBuilder $canonicalBuilder = new CanonicalOverviewPayloadBuilder(),
        private readonly OverviewShadowComparator $shadowComparator = new OverviewShadowComparator(),
        private readonly OverviewMetaEnricher $meta = new OverviewMetaEnricher(),
        private readonly OverviewMonthComparisonBuilder $monthComparison = new OverviewMonthComparisonBuilder(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(OverviewRequest $request): array
    {
        $startedAt = microtime(true);
        $force = $request->force;
        $cacheKey = $request->cacheKey();

        if (!$force) {
            $cachedResponse = $this->cache->get($cacheKey, Cache::adaptiveTtl($request->end()));
            if (is_array($cachedResponse)) {
                header('X-Predictive-Cache: hit');
                $cachedResponse['meta'] = $cachedResponse['meta'] ?? [];
                $cachedResponse['meta']['cache_status'] = 'hit';
                $cachedResponse['meta']['cache_key'] = $cacheKey;
                $cachedResponse['meta']['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
                return $cachedResponse;
            }
        }

        $payload = $this->doBuild($request);
        
        $payload['meta'] = $payload['meta'] ?? [];
        $payload['meta']['loaded_at'] = date(DATE_ATOM);
        $payload['meta']['period_start'] = $request->start();
        $payload['meta']['period_end'] = $request->end();
        $payload['meta']['cache_status'] = 'rebuilt';
        $payload['meta']['cache_key'] = $cacheKey;
        $payload['meta']['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        $payload['meta']['datasets_present'] = array_keys($payload);

        $this->cache->put($cacheKey, $payload);
        return $payload;
    }

    private function doBuild(OverviewRequest $request): array
    {
        $freshness = DatasetFreshnessService::boot($this->config, $this->logger);
        $shadow = ShadowValidationService::boot($this->config, $this->logger);

        $start = $request->start();
        $end = $request->end();
        $competencia = $request->competencia();
        $force = $request->force;

        $saturdayWeight = (float) $this->config->get('flight_plan.saturday_weight', 0.33);
        $snapshot = null;
        $snapshotError = null;
        $canonicalCoverage = null;
        $canonicalFallbackReason = null;

        try {
            $snapshot = $this->analytics->getSnapshot($start, $end, $force);
        } catch (Throwable $exception) {
            $snapshotError = $exception->getMessage();
            $this->logger->warning('Overview: snapshot legado indisponivel.', [
                'message' => $exception->getMessage(),
                'period' => ['start' => $start, 'end' => $end],
                'request_id' => RequestContext::id(),
            ]);
        }

        if ($this->config->isCanonicalEnabled('overview') && (bool) $this->config->get('features.canonical_fiscal_ready', false)) {
            $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            if (Database::isConfigured($this->config, $connection)) {
                try {
                    $pdo = Database::connect($this->config, $connection);
                    $canonical = new CanonicalRepository($pdo, $this->logger);
                    $targetsRepository = null;
                    if ($canonical->isAvailable()) {
                        $canonicalCoverage = $canonical->commercialCoverageDiagnostics($start, $end);
                        if (!$this->meta->canonicalCoverageIsComplete($canonicalCoverage, $start, $end)) {
                            throw new RuntimeException(sprintf(
                                'Cobertura canonical incompleta para overview. Solicitado %s ate %s; disponivel ate %s.',
                                $start,
                                $end,
                                $this->meta->canonicalCoverageEnd($canonicalCoverage) ?: 'sem dados'
                            ));
                        }
                        try {
                            $targetsRepository = new TargetsRepository($pdo, $this->logger);
                        } catch (Throwable) {
                            $targetsRepository = null;
                        }

                        $historical = new CommercialCompetenciaSnapshotService($pdo, $this->logger, $canonical, $targetsRepository, $saturdayWeight);
                        $snapshotYm = $historical->shouldUseSnapshot($start, $end, $competencia);
                        if ($snapshotYm !== null) {
                            $payload = $historical->loadPayload('overview', $snapshotYm);
                            if (!is_array($payload) || $force) {
                                $this->logger->warning('Overview: snapshot historico nao materializado durante request; seguindo com leitura canonical.', [
                                    'competencia' => $snapshotYm,
                                    'force' => $force,
                                    'request_id' => RequestContext::id(),
                                ]);
                            } else {
                                $payload['month_comparison'] = $this->monthComparison->build(
                                    $pdo,
                                    $this->logger,
                                    $canonical,
                                    $targetsRepository,
                                    $start,
                                    $saturdayWeight
                                );
                                $historicalPayload = $freshness->attach('overview', $payload, ['observe_request' => true]);
                                $historicalPayload = $this->meta->enrich($historicalPayload, $canonicalCoverage ?? [], null);
                                $shadow->defer(
                                    'overview',
                                    function () use ($snapshot, $freshness): ?array {
                                        return $snapshot === null ? null : $this->legacyBuilder->build($snapshot, $freshness);
                                    },
                                    static fn (): ?array => $historicalPayload,
                                    [
                                        'start' => $start,
                                        'end' => $end,
                                        'competencia' => $competencia,
                                        'official_mode' => 'canonical_historical',
                                    ]
                                );
                                $historicalPayload['meta']['source_of_truth'] = 'canonical_historical';
                                return $historicalPayload;
                            }
                        }

                        $lastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM));
                        $payload = $this->canonicalBuilder->build(
                            $pdo,
                            $this->logger,
                            $canonical,
                            $targetsRepository,
                            $snapshot,
                            $request->range,
                            $lastSync,
                            $saturdayWeight,
                            $snapshotError
                        );
                        $payload = $freshness->attach('overview', $payload, ['observe_request' => true]);
                        $payload = $this->meta->enrich($payload, $canonicalCoverage ?? [], null);

                        $shadow->defer(
                            'overview',
                            function () use ($snapshot, $freshness): ?array {
                                return $snapshot === null ? null : $this->legacyBuilder->build($snapshot, $freshness);
                            },
                            static fn (): ?array => $payload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $competencia,
                                'official_mode' => 'canonical_live',
                            ]
                        );
                        $payload['meta']['source_of_truth'] = 'canonical_live';
                        return $payload;
                    }
                } catch (Throwable $exception) {
                    $canonicalFallbackReason = $exception->getMessage();
                    $this->logger->warning('Overview: leitura canonical falhou, mantendo legado.', [
                        'message' => $exception->getMessage(),
                        'period' => ['start' => $start, 'end' => $end],
                        'kpi_registry' => $this->kpiRegistryContext(),
                        'request_id' => RequestContext::id(),
                    ]);
                }
            }
        }

        if ($snapshot === null) {
            throw new OverviewResponseException([
                'ok' => false,
                'message' => 'Nao foi possivel carregar o painel no momento.',
                'request_id' => RequestContext::id(),
            ], 500);
        }

        $snapshot['source'] = $snapshot['source'] ?? 'legacy';
        $legacyPayload = $freshness->attach('overview', $this->legacyBuilder->build($snapshot, $freshness), ['observe_request' => true]);
        $legacyPayload = $this->meta->enrich(
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
            function () use ($freshness, $request, $snapshot, $snapshotError, $saturdayWeight, $force): ?array {
                return $this->shadowComparator->buildCanonicalShadowPayload(
                    $this->config,
                    $this->logger,
                    $freshness,
                    $request->range,
                    $snapshot,
                    $snapshotError,
                    $saturdayWeight,
                    $force
                );
            },
            [
                'start' => $start,
                'end' => $end,
                'competencia' => $competencia,
                'official_mode' => 'legacy',
            ]
        );
        $legacyPayload['meta']['source_of_truth'] = 'legacy';
        return $legacyPayload;
    }

    /**
     * Returns KPI registry context for log enrichment — never throws.
     * @return array<string,mixed>
     */
    private function kpiRegistryContext(): array
    {
        try {
            $ctx = new KpiContextService();
            return [
                'kpi_active'     => $ctx->summary()['active'],
                'kpi_permission' => $ctx->permissionForDomain('sales'),
                'kpi_source'     => $ctx->primaryKpiForDomain('sales')?->sourceOfTruth ?? 'unknown',
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
