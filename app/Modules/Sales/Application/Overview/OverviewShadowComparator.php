<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Logger;
use BIomie\TargetsRepository;
use Throwable;

final class OverviewShadowComparator
{
    public function __construct(
        private readonly CanonicalOverviewPayloadBuilder $canonicalBuilder = new CanonicalOverviewPayloadBuilder(),
        private readonly OverviewDiagnosticsBuilder $diagnostics = new OverviewDiagnosticsBuilder(),
        private readonly OverviewMetaEnricher $meta = new OverviewMetaEnricher(),
        private readonly OverviewMonthComparisonBuilder $monthComparison = new OverviewMonthComparisonBuilder(),
    ) {
    }

    /**
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>|null
     */
    public function buildCanonicalShadowPayload(
        Config $config,
        Logger $logger,
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

            $start = $range['start'];
            $end = $range['end'];
            $coverage = $canonical->commercialCoverageDiagnostics($start, $end);
            if (!$this->meta->canonicalCoverageIsComplete($coverage, $start, $end)) {
                return null;
            }

            $targetsRepository = null;
            try {
                $targetsRepository = new TargetsRepository($pdo, $logger);
            } catch (Throwable) {
                $targetsRepository = null;
            }

            $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
            $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia'] ?? null);
            if ($snapshotYm !== null) {
                $payload = $historical->loadPayload('overview', $snapshotYm);
                if (is_array($payload)) {
                    $payload['month_comparison'] = $this->monthComparison->build(
                        $pdo,
                        $logger,
                        $canonical,
                        $targetsRepository,
                        $start,
                        $saturdayWeight
                    );
                    if (!$this->diagnostics->historicalHasStrongSignal($payload)) {
                        return null;
                    }

                    if (!$force && ($this->diagnostics->historicalHasStrongSignal($payload) || $snapshot === null)) {
                        return $freshness->attach('overview', $payload);
                    }
                }
            }

            $payload = $this->canonicalBuilder->build(
                $pdo,
                $logger,
                $canonical,
                $targetsRepository,
                $snapshot,
                $range,
                $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM),
                $saturdayWeight,
                $snapshotError
            );
            $payload = $freshness->attach('overview', $payload);

            return ($this->diagnostics->canonicalHasSignal($payload) || $snapshot === null) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
