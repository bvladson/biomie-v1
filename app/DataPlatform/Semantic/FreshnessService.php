<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

use PDO;
use PDOException;

/**
 * Reports data freshness for KPI domains and individual metrics.
 *
 * Reads from biomie_pipeline_jobs to determine when data was last refreshed.
 * Never blocks authorization — returns 'unknown' when DB is unavailable.
 */
final class FreshnessService
{
    /** Job name written by GoldRefreshCoordinator. */
    private const JOB_NAME = 'gold_mart_refresh';

    /** Minutes before a domain is considered stale. */
    private const DEFAULT_STALE_MINUTES = 60;

    /** Maps KPI freshness_domain values to stale thresholds. */
    private const DOMAIN_STALE_MINUTES = [
        // storage tiers
        'gold'        => 60,
        'canonical'   => 120,
        'operational' => 30,
        'legacy'      => 1440,
        'external'    => 240,
        // business domain aliases (all share gold_mart_refresh)
        'sales'       => 60,
        'fiscal'      => 60,
        'finance'     => 60,
        'customers'   => 120,
        'geo'         => 240,
        'cnae'        => 240,
        'omie'        => 120,
        'operations'  => 30,
        'shadow'      => 120,
    ];

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Returns freshness status for all KPIs in a freshness domain.
     *
     * @return array{status:string, last_success_at:string|null, last_reconcile_at:string|null, stale_after_minutes:int, cause:string}
     */
    public function forDomain(string $domain): array
    {
        $staleMinutes = self::DOMAIN_STALE_MINUTES[$domain] ?? self::DEFAULT_STALE_MINUTES;

        try {
            [$lastSuccessAt, $lastReconcileAt] = $this->queryJobTimestamps();
        } catch (PDOException $e) {
            return $this->unknownResult($staleMinutes, 'db_unavailable: ' . $e->getMessage());
        }

        $status = $this->statusFromTimestamps($lastSuccessAt, $lastReconcileAt, $staleMinutes);

        return [
            'status'              => $status,
            'last_success_at'     => $lastSuccessAt,
            'last_reconcile_at'   => $lastReconcileAt,
            'stale_after_minutes' => $staleMinutes,
            'cause'               => '',
        ];
    }

    /**
     * Returns freshness status for a specific KPI, using its freshness_domain.
     *
     * @return array{status:string, last_success_at:string|null, last_reconcile_at:string|null, stale_after_minutes:int, cause:string, kpi_id:string, freshness_domain:string}
     */
    public function forMetric(string $metricId): array
    {
        $registry = MetricRegistry::build();

        if (!$registry->has($metricId)) {
            return array_merge(
                $this->unknownResult(self::DEFAULT_STALE_MINUTES, "kpi_not_found: {$metricId}"),
                ['kpi_id' => $metricId, 'freshness_domain' => ''],
            );
        }

        $kpi    = $registry->get($metricId);
        $domain = $kpi->freshnessDomain;
        $result = $this->forDomain($domain);

        return array_merge($result, [
            'kpi_id'           => $metricId,
            'freshness_domain' => $domain,
        ]);
    }

    /**
     * Derives a status string from job timestamps.
     *
     * @param string|null $lastSuccessAt    completed_at of last successful job
     * @param string|null $lastReconcileAt  completed_at of last job regardless of status
     */
    public function statusFromTimestamps(
        ?string $lastSuccessAt,
        ?string $lastReconcileAt,
        int     $staleAfterMinutes,
    ): string {
        if ($lastSuccessAt === null && $lastReconcileAt === null) {
            return 'unknown';
        }

        if ($lastSuccessAt === null && $lastReconcileAt !== null) {
            return 'failed';
        }

        $successAge = $this->ageInMinutes($lastSuccessAt);

        if ($successAge === null) {
            return 'unknown';
        }

        if ($successAge > $staleAfterMinutes) {
            if ($lastReconcileAt !== null && $lastReconcileAt > $lastSuccessAt) {
                return 'partial';
            }
            return 'stale';
        }

        return 'fresh';
    }

    // -------------------------------------------------------------------------

    /** @return array{string|null, string|null}  [lastSuccessAt, lastReconcileAt] */
    private function queryJobTimestamps(): array
    {
        $sql = "
            SELECT
                MAX(CASE WHEN status = 'completed' THEN completed_at END) AS last_success_at,
                MAX(completed_at) AS last_reconcile_at
            FROM biomie_pipeline_jobs
            WHERE job_name = :job
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':job' => self::JOB_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [null, null];
        }

        return [
            $row['last_success_at']   ?: null,
            $row['last_reconcile_at'] ?: null,
        ];
    }

    private function ageInMinutes(string $timestamp): ?int
    {
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return null;
        }
        return (int) round((time() - $ts) / 60);
    }

    /** @return array{status:string, last_success_at:null, last_reconcile_at:null, stale_after_minutes:int, cause:string} */
    private function unknownResult(int $staleAfterMinutes, string $cause): array
    {
        return [
            'status'              => 'unknown',
            'last_success_at'     => null,
            'last_reconcile_at'   => null,
            'stale_after_minutes' => $staleAfterMinutes,
            'cause'               => $cause,
        ];
    }
}
