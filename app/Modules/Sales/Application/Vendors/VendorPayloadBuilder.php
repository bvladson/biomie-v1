<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

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
use Throwable;

final class VendorPayloadBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SalesAnalyticsService $analytics,
        private readonly LegacyVendorPayloadBuilder $legacyBuilder = new LegacyVendorPayloadBuilder(),
        private readonly CanonicalVendorPayloadBuilder $canonicalBuilder = new CanonicalVendorPayloadBuilder(),
        private readonly VendorShadowComparator $shadowComparator = new VendorShadowComparator(),
        private readonly VendorMetaEnricher $meta = new VendorMetaEnricher(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(VendorRequest $request): array
    {
        $freshness = DatasetFreshnessService::boot($this->config, $this->logger);
        $shadow = ShadowValidationService::boot($this->config, $this->logger);
        $flagsContext = $this->collectFlags();

        $range = $request->range;
        $start = $request->start();
        $end = $request->end();
        $canonicalFallbackReason = null;
        $canonicalCoverage = null;

        $enforceScope = (bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false);
        if (($this->config->isCanonicalEnabled('vendors') && (bool) $this->config->get('features.canonical_fiscal_ready', false)) || $enforceScope) {
            try {
                $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

                if (!Database::isConfigured($this->config, $connection)) {
                    $canonicalFallbackReason = 'Conexao canonical nao configurada.';
                } else {
                    $pdo = Database::connect($this->config, $connection);
                    $repo = new CanonicalRepository($pdo, $this->logger);
                    $targetsRepository = null;

                    if (!$repo->isAvailable()) {
                        $canonicalFallbackReason = 'Camada canonical indisponivel.';
                    } else {
                        $canonicalCoverage = $repo->commercialCoverageDiagnostics($start, $end);
                        try {
                            $targetsRepository = new TargetsRepository($pdo, $this->logger);
                        } catch (Throwable) {
                            $targetsRepository = null;
                        }

                        $historical = new CommercialCompetenciaSnapshotService(
                            $pdo,
                            $this->logger,
                            $repo,
                            $targetsRepository,
                            (float) $this->config->get('flight_plan.saturday_weight', 0.33)
                        );
                        $snapshotYm = $historical->shouldUseSnapshot($start, $end, $request->competencia());
                        if ($snapshotYm !== null) {
                            $payload = $historical->loadPayload('vendors', $snapshotYm);
                            if (!is_array($payload) || $request->forceRefresh) {
                                $this->logger->warning('Vendors: snapshot historico nao materializado durante request; seguindo com leitura canonical.', [
                                    'competencia' => $snapshotYm,
                                    'force' => $request->forceRefresh,
                                    'request_id' => RequestContext::id(),
                                ]);
                            } else {
                                $historicalPayload = $freshness->attach('vendors', $payload, ['observe_request' => true]);
                                $historicalPayload = $this->meta->enrich($historicalPayload, $canonicalCoverage ?? [], null);
                                $shadow->defer(
                                    'vendors',
                                    function () use ($range, $request, $freshness): ?array {
                                        $snapshot = $this->analytics->getSnapshot($range['start'], $range['end'], $request->forceRefresh);
                                        return $freshness->attach('vendors', $this->legacyBuilder->build($snapshot, $range, 'legacy'));
                                    },
                                    static fn (): ?array => $historicalPayload,
                                    [
                                        'start' => $start,
                                        'end' => $end,
                                        'competencia' => $request->competencia(),
                                        'official_mode' => 'canonical_historical',
                                    ]
                                );
                                return $historicalPayload;
                            }
                        }

                        $lastSync = (string) (($canonicalCoverage['last_sync_at'] ?? null) ?: $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM));
                        $canonicalPayload = $this->canonicalBuilder->build(
                            $repo,
                            $targetsRepository,
                            $start,
                            $end,
                            $lastSync,
                            (float) $this->config->get('flight_plan.saturday_weight', 0.33),
                            $request->vendorLimit
                        );
                        $canonicalPayload = $freshness->attach('vendors', $canonicalPayload, ['observe_request' => true]);
                        $canonicalPayload = $this->meta->enrich($canonicalPayload, $canonicalCoverage ?? [], null);
                        $shadow->defer(
                            'vendors',
                            function () use ($range, $request, $freshness): ?array {
                                $snapshot = $this->analytics->getSnapshot($range['start'], $range['end'], $request->forceRefresh);
                                return $freshness->attach('vendors', $this->legacyBuilder->build($snapshot, $range, 'legacy'));
                            },
                            static fn (): ?array => $canonicalPayload,
                            [
                                'start' => $start,
                                'end' => $end,
                                'competencia' => $request->competencia(),
                                'official_mode' => 'canonical_live',
                            ]
                        );
                        return $canonicalPayload;
                    }
                }
            } catch (Throwable $canonicalException) {
                $canonicalFallbackReason = VendorSupport::summarizeThrowable($canonicalException);

                $this->logger->error('Falha no caminho canonical de vendors; usando fallback legado.', [
                    'message' => $canonicalException->getMessage(),
                    'trace' => $canonicalException->getTraceAsString(),
                    'exception_class' => $canonicalException::class,
                    'competencia' => $request->competencia(),
                    'period' => ['start' => $start, 'end' => $end],
                    'source_attempted' => 'canonical',
                    'flags' => $flagsContext,
                    'kpi_registry' => $this->kpiRegistryContext(),
                    'request_id' => RequestContext::id(),
                ]);
            }

            if ($canonicalFallbackReason !== null) {
                $this->logger->warning('Vendors canonical indisponivel para o periodo; usando fallback legado.', [
                    'reason' => $canonicalFallbackReason,
                    'competencia' => $request->competencia(),
                    'period' => ['start' => $start, 'end' => $end],
                    'source_attempted' => 'canonical',
                    'flags' => $flagsContext,
                    'request_id' => RequestContext::id(),
                ]);
            }
        }

        $snapshot = $this->analytics->getSnapshot($start, $end, $request->forceRefresh);
        $fallbackSource = $canonicalFallbackReason !== null ? 'emergency_legacy' : 'legacy';
        $legacyPayload = $freshness->attach(
            'vendors',
            $this->legacyBuilder->build($snapshot, $range, $fallbackSource, $canonicalFallbackReason),
            ['observe_request' => true]
        );
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
            'vendors',
            static fn (): ?array => $legacyPayload,
            function () use ($freshness, $range, $request): ?array {
                return $this->shadowComparator->buildCanonicalShadowPayload(
                    $this->config,
                    $this->logger,
                    $freshness,
                    $range,
                    $request->forceRefresh
                );
            },
            [
                'start' => $start,
                'end' => $end,
                'competencia' => $request->competencia(),
                'official_mode' => 'legacy',
            ]
        );

        return $legacyPayload;
    }

    /**
     * @return array<string,bool>
     */
    public function collectFlags(): array
    {
        return [
            'canonical_reads_vendors' => (bool) $this->config->get('features.canonical_reads_vendors', false),
            'canonical_reads_orders' => (bool) $this->config->get('features.canonical_reads_orders', false),
            'canonical_reads_overview' => (bool) $this->config->get('features.canonical_reads_overview', false),
        ];
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
