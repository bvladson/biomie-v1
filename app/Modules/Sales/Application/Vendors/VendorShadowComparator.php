<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Logger;
use BIomie\TargetsRepository;
use Throwable;

final class VendorShadowComparator
{
    public function __construct(
        private readonly CanonicalVendorPayloadBuilder $canonicalBuilder = new CanonicalVendorPayloadBuilder(),
    ) {
    }

    /**
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @return array<string,mixed>|null
     */
    public function buildCanonicalShadowPayload(
        Config $config,
        Logger $logger,
        DatasetFreshnessService $freshness,
        array $range,
        bool $forceRefresh = false
    ): ?array {
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
                if (!$forceRefresh && is_array($payload) && VendorSupport::canonicalPayloadHasSignal($payload)) {
                    return $freshness->attach('vendors', $payload);
                }
            }

            $lastSync = $repo->lastSyncAt($start, $end) ?: date(DATE_ATOM);
            $payload = $this->canonicalBuilder->build(
                $repo,
                $targetsRepository,
                $start,
                $end,
                $lastSync,
                (float) $config->get('flight_plan.saturday_weight', 0.33),
                50,
                $pdo,
                false
            );
            $payload = $freshness->attach('vendors', $payload);

            return VendorSupport::canonicalPayloadHasSignal($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
