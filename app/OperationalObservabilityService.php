<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;

final class OperationalObservabilityService
{
    /** @var list<string> */
    private const DASHBOARD_DATASETS = [
        'overview',
        'orders',
        'vendors',
        'finance_overview',
        'finance_titles',
        'reconciliation',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?TelemetryRepository $telemetryRepository = null,
        private readonly ?CanonicalRepository $canonical = null,
        private readonly ?SyncPipelineRepository $pipeline = null,
    ) {
    }

    public static function boot(
        Config $config,
        Logger $logger,
        ?TelemetryRepository $telemetryRepository = null,
        ?CanonicalRepository $canonical = null,
        ?SyncPipelineRepository $pipeline = null,
    ): self {
        $resolvedCanonical = $canonical;
        if ($resolvedCanonical === null) {
            try {
                $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
                if (Database::isConfigured($config, $connection)) {
                    $repo = new CanonicalRepository(
                        Database::connect($config, $connection),
                        $logger,
                        readOnly: true,
                    );
                    $resolvedCanonical = $repo->isAvailable() ? $repo : null;
                }
            } catch (\Throwable) {
                $resolvedCanonical = null;
            }
        }

        return new self(
            $config,
            $logger,
            $telemetryRepository ?? TelemetryRepository::boot($config, $logger),
            $resolvedCanonical,
            $pipeline ?? SyncPipelineRepository::bootReadOnly($config, $logger),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function buildDashboard(int $hours = 24): array
    {
        $hours = max(1, min($hours, 720));
        $freshness = DatasetFreshnessService::boot($this->config, $this->logger, $this->canonical, $this->pipeline);
        try {
            $pipelineSummary = $this->pipeline?->summarizeOperationalState(120);
        } catch (\Throwable $exception) {
            $this->logger->warning('Resumo operacional do pipeline indisponivel para leitura.', [
                'message' => $exception->getMessage(),
            ]);
            $pipelineSummary = null;
        }
        $freshness->primePipelineSnapshot($pipelineSummary);
        $divergences = $this->summarizeDivergences();
        $freshness->primeDivergenceSnapshot(
            is_array($divergences['summary'] ?? null) ? $divergences['summary'] : null,
        );
        $telemetry = $this->telemetryRepository;
        $spanRows = $telemetry?->recentSpanSummaries($hours, 300) ?? [];
        $eventRows = $telemetry?->recentEventSummaries($hours, 80) ?? [];

        // ── Fase 11 additions ────────────────────────────────────────────────
        $pdo             = $this->getPdo();
        $coverage        = null;
        $activeAlerts    = [];
        $pipelineHealth  = [];

        if ($pdo !== null) {
            $coverageAgg  = new \BIomie\Observability\CoverageAggregator($pdo);
            $alertEngine  = new \BIomie\Observability\AlertEngine($pdo);
            $coverage     = $this->projectCoverage($coverageAgg->summarize());
            $activeAlerts = $this->projectActiveAlerts($alertEngine->getActiveAlerts());
            $pipelineHealth = $this->summarizePipelineJobsHealth($pdo);
        }

        $metricsLog       = dirname(__DIR__) . '/storage/logs/endpoint_requests.jsonl';
        $metricsCollector = new \BIomie\Observability\EndpointMetricsCollector($metricsLog);
        $endpointLatency  = $this->projectEndpointLatency($metricsCollector->summarize($hours));
        // ────────────────────────────────────────────────────────────────────

        $shadow = $telemetry?->summarizeShadowDiffs($hours) ?? ['critical_open_total' => 0, 'by_endpoint' => []];

        return [
            'generated_at'    => date(DATE_ATOM),
            'trace'           => ['trace_id' => RequestContext::traceId()],
            'window_hours'    => $hours,
            'jobs'            => $this->summarizeJobs($pipelineSummary, $spanRows),
            'datasets'        => $this->summarizeDatasets($freshness),
            'shadow'          => $this->projectShadowSummary($shadow),
            'divergences'     => $divergences,
            'omie_methods'    => $this->summarizeOmieMethodTimings($telemetry, $hours),
            'recent_failures' => $this->recentFailures($spanRows, $eventRows),
            // Fase 11
            'coverage'         => $coverage,
            'active_alerts'    => $activeAlerts,
            'pipeline_health'  => $pipelineHealth,
            'endpoint_latency' => $endpointLatency,
        ];
    }

    /**
     * @param array<string,mixed>|null $pipelineSummary
     * @param list<array<string,mixed>> $spanRows
     * @return array<string,mixed>
     */
    private function summarizeJobs(?array $pipelineSummary, array $spanRows): array
    {
        $recentRuns = is_array($pipelineSummary['recent_runs'] ?? null) ? $pipelineSummary['recent_runs'] : [];
        $webhookInbox = is_array($pipelineSummary['webhook_inbox'] ?? null) ? $pipelineSummary['webhook_inbox'] : [];
        $byDomain = [];
        foreach ($recentRuns as $run) {
            if (!is_array($run)) {
                continue;
            }

            $domain = trim((string) ($run['domain_name'] ?? 'unknown'));
            $status = trim((string) ($run['status'] ?? 'unknown'));
            $domain = $domain !== '' ? $domain : 'unknown';
            $status = $status !== '' ? $status : 'unknown';
            $startedAt = trim((string) ($run['started_at'] ?? ''));
            $finishedAt = trim((string) ($run['finished_at'] ?? ''));
            $rawDurationMs = $run['duration_ms'] ?? null;
            $durationMs = is_numeric($rawDurationMs)
                ? max(0.0, (float) $rawDurationMs)
                : $this->durationMs($startedAt, $finishedAt);

            $latestTimestamp = $this->runTimestamp($startedAt, $finishedAt);
            $latestId = is_numeric($run['id'] ?? null) ? (int) $run['id'] : PHP_INT_MIN;
            $currentTimestamp = (int) ($byDomain[$domain]['_latest_timestamp'] ?? PHP_INT_MIN);
            $currentId = (int) ($byDomain[$domain]['_latest_id'] ?? PHP_INT_MIN);
            $isLatest = !isset($byDomain[$domain])
                || $latestTimestamp > $currentTimestamp
                || ($latestTimestamp === $currentTimestamp && $latestId > $currentId);

            $byDomain[$domain]['domain'] = $domain;
            if ($isLatest) {
                $byDomain[$domain]['last_status'] = $status;
                $byDomain[$domain]['last_started_at'] = $startedAt !== '' ? $startedAt : null;
                $byDomain[$domain]['last_finished_at'] = $finishedAt !== '' ? $finishedAt : null;
                $byDomain[$domain]['_latest_timestamp'] = $latestTimestamp;
                $byDomain[$domain]['_latest_id'] = $latestId;
            }
            if ($durationMs !== null) {
                $byDomain[$domain]['durations'][] = $durationMs;
            }
            $byDomain[$domain]['runs'] = (int) (($byDomain[$domain]['runs'] ?? 0) + 1);
        }

        foreach ($byDomain as $domain => $row) {
            $durations = is_array($row['durations'] ?? null) ? $row['durations'] : [];
            $byDomain[$domain]['p95_duration_ms'] = $this->percentile($durations, 95);
            $byDomain[$domain]['avg_duration_ms'] = $this->average($durations);
            unset($byDomain[$domain]['durations'], $byDomain[$domain]['_latest_timestamp'], $byDomain[$domain]['_latest_id']);
        }

        $jobsFailed = 0;
        foreach ($spanRows as $span) {
            $name = (string) ($span['span_name'] ?? '');
            $status = (string) ($span['status_name'] ?? '');
            if (str_starts_with($name, 'sync.') && $status !== 'ok' && $status !== 'completed') {
                $jobsFailed++;
            }
        }

        return [
            'pipeline' => [
                'pending_total' => (int) ($webhookInbox['pending_total'] ?? 0),
                'oldest_pending_age_seconds' => isset($webhookInbox['oldest_pending_age_seconds'])
                    ? max(0, (int) $webhookInbox['oldest_pending_age_seconds'])
                    : null,
            ],
            'by_domain' => array_values($byDomain),
            'failed_spans' => $jobsFailed,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function summarizeDatasets(DatasetFreshnessService $freshness): array
    {
        $rows = [];
        $today = new DateTimeImmutable('today');
        $start = $today->modify('first day of this month')->format('Y-m-d');
        $end = $today->modify('last day of this month')->format('Y-m-d');
        try {
            $lastSync = $this->canonical?->lastSyncAt($start, $end);
        } catch (\Throwable) {
            $lastSync = null;
        }

        foreach (self::DASHBOARD_DATASETS as $dataset) {
            $contract = $freshness->buildContract($dataset, [
                'source' => 'operational_dashboard',
                'period' => ['start' => $start, 'end' => $end],
                'meta' => [
                    'served_from' => 'operational_dashboard',
                    'last_sync' => $lastSync,
                ],
            ], [
                'coverage_start' => $start,
                'coverage_end' => $end,
            ]);

            $rows[] = [
                'dataset' => $dataset,
                'freshness_at' => $contract['freshness_at'],
                'freshness_lag_seconds' => $contract['freshness_lag_seconds'],
                'data_mode' => $contract['data_mode'],
                'reconcile_status' => $contract['reconcile_status'],
                'staleness_reason' => $contract['staleness_reason'],
                'open_divergences' => $contract['open_divergences'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeDivergences(): array
    {
        if ($this->canonical === null) {
            return [
                'summary' => ['active_total' => 0, 'by_status' => [], 'by_severity' => []],
                'by_rule' => [],
            ];
        }

        try {
            $summary = $this->canonical->summarizeDivergenceQueue();
            $openRows = $this->canonical->openDivergences(300);
        } catch (\Throwable $exception) {
            $this->logger->warning('Divergencias indisponiveis para leitura operacional.', [
                'message' => $exception->getMessage(),
            ]);
            return [
                'summary' => ['active_total' => 0, 'by_status' => [], 'by_severity' => []],
                'by_rule' => [],
            ];
        }
        $byRule = [];
        foreach ($openRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rule = trim((string) ($row['rule_code'] ?? $row['tipo'] ?? 'unknown'));
            $severity = trim((string) ($row['severity'] ?? 'medium'));
            $byRule[$rule]['rule_code'] = $rule;
            $byRule[$rule]['total'] = (int) (($byRule[$rule]['total'] ?? 0) + 1);
            $byRule[$rule]['by_severity'][$severity] = (int) (($byRule[$rule]['by_severity'][$severity] ?? 0) + 1);
        }

        $byRule = array_values($byRule);
        usort($byRule, static fn (array $left, array $right): int => (int) ($right['total'] ?? 0) <=> (int) ($left['total'] ?? 0));

        return [
            'summary' => $summary,
            'by_rule' => $byRule,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function summarizeOmieMethodTimings(?TelemetryRepository $telemetry, int $hours): array
    {
        if ($telemetry === null) {
            return [];
        }

        $rows = $telemetry->metricSamples('omie_request_duration_ms', $hours, 3000);
        $grouped = [];
        foreach ($rows as $row) {
            $labels = $this->decodeJsonObject((string) ($row['labels_json'] ?? ''));
            $call = trim((string) ($labels['call'] ?? 'unknown'));
            $grouped[$call]['call'] = $call;
            $grouped[$call]['durations'][] = (float) ($row['numeric_value'] ?? 0);
            $grouped[$call]['requests'] = (int) (($grouped[$call]['requests'] ?? 0) + 1);
            $grouped[$call]['errors'] = (int) (($grouped[$call]['errors'] ?? 0) + ((string) ($labels['outcome'] ?? '') === 'error' ? 1 : 0));
        }

        foreach ($grouped as $call => $data) {
            $durations = is_array($data['durations'] ?? null) ? $data['durations'] : [];
            $grouped[$call]['avg_ms'] = $this->average($durations);
            $grouped[$call]['p95_ms'] = $this->percentile($durations, 95);
            unset($grouped[$call]['durations']);
        }

        $grouped = array_values($grouped);
        usort($grouped, static fn (array $left, array $right): int => (float) ($right['p95_ms'] ?? 0) <=> (float) ($left['p95_ms'] ?? 0));
        return $grouped;
    }

    /**
     * @param array<string,mixed> $coverage
     * @return array<string,int|float>
     */
    private function projectCoverage(array $coverage): array
    {
        return [
            'total_clients' => (int) ($coverage['total_clients'] ?? 0),
            'geo_covered' => (int) ($coverage['geo_covered'] ?? 0),
            'geo_coverage_pct' => (float) ($coverage['geo_coverage_pct'] ?? 0),
            'cnae_covered' => (int) ($coverage['cnae_covered'] ?? 0),
            'cnae_coverage_pct' => (float) ($coverage['cnae_coverage_pct'] ?? 0),
            'enriched_covered' => (int) ($coverage['enriched_covered'] ?? 0),
            'enriched_pct' => (float) ($coverage['enriched_pct'] ?? 0),
            'active_3m_clients' => (int) ($coverage['active_3m_clients'] ?? 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $alerts
     * @return list<array<string,mixed>>
     */
    private function projectActiveAlerts(array $alerts): array
    {
        $result = [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $result[] = [
                'rule_code' => (string) ($alert['rule_code'] ?? ''),
                'severity' => (string) ($alert['severity'] ?? 'info'),
                'title' => (string) ($alert['title'] ?? ''),
                'message' => (string) ($alert['message'] ?? ''),
                'triggered_at' => $alert['triggered_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function projectEndpointLatency(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'method' => (string) ($row['method'] ?? ''),
                'total' => (int) ($row['total'] ?? 0),
                'errors' => (int) ($row['errors'] ?? 0),
                'error_rate' => (float) ($row['error_rate'] ?? 0),
                'avg_ms' => (int) ($row['avg_ms'] ?? 0),
                'p50_ms' => (int) ($row['p50_ms'] ?? 0),
                'p95_ms' => (int) ($row['p95_ms'] ?? 0),
                'max_ms' => (int) ($row['max_ms'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function projectShadowSummary(array $summary): array
    {
        $byEndpoint = [];
        $rows = is_array($summary['by_endpoint'] ?? null) ? $summary['by_endpoint'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $severities = [];
            foreach ((array) ($row['by_severity'] ?? []) as $severity => $total) {
                $severities[(string) $severity] = (int) $total;
            }
            $byEndpoint[] = [
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'total' => (int) ($row['total'] ?? 0),
                'by_severity' => $severities,
            ];
        }

        return [
            'critical_open_total' => (int) ($summary['critical_open_total'] ?? 0),
            'by_endpoint' => $byEndpoint,
        ];
    }

    /**
     * @param list<array<string,mixed>> $spanRows
     * @param list<array<string,mixed>> $eventRows
     * @return array<string,mixed>
     */
    private function recentFailures(array $spanRows, array $eventRows): array
    {
        $failedSpans = [];
        foreach ($spanRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status_name'] ?? 'ok');
            if (in_array($status, ['ok', 'completed'], true)) {
                continue;
            }
            $failedSpans[] = [
                'span_name' => (string) ($row['span_name'] ?? ''),
                'status_name' => $status,
                'correlation_id' => (string) ($row['correlation_id'] ?? ''),
                'started_at' => $row['started_at'] ?? null,
            ];
            if (count($failedSpans) >= 20) {
                break;
            }
        }

        $errorEvents = [];
        foreach ($eventRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $level = strtoupper((string) ($row['level_name'] ?? 'INFO'));
            if (!in_array($level, ['ERROR', 'WARNING'], true)) {
                continue;
            }
            $errorEvents[] = [
                'event_name' => (string) ($row['event_name'] ?? ''),
                'level_name' => $level,
                'correlation_id' => (string) ($row['correlation_id'] ?? ''),
                'occurred_at' => $row['occurred_at'] ?? null,
            ];
            if (count($errorEvents) >= 20) {
                break;
            }
        }

        return [
            'failed_spans' => $failedSpans,
            'error_events' => $errorEvents,
        ];
    }

    private function durationMs(string $startedAt, string $finishedAt): ?float
    {
        if ($startedAt === '' || $finishedAt === '') {
            return null;
        }

        $start = strtotime($startedAt);
        $end = strtotime($finishedAt);
        if ($start === false || $end === false) {
            return null;
        }

        return max(0.0, (float) (($end - $start) * 1000));
    }

    private function runTimestamp(string $startedAt, string $finishedAt): int
    {
        $timestamp = $startedAt !== '' ? strtotime($startedAt) : false;
        if ($timestamp === false && $finishedAt !== '') {
            $timestamp = strtotime($finishedAt);
        }

        return $timestamp !== false ? $timestamp : PHP_INT_MIN;
    }

    /**
     * @param list<int|float> $values
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $index = (int) ceil((max(1, min($percentile, 100)) / 100) * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return round((float) $values[$index], 2);
    }

    /**
     * @param list<int|float> $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ── Fase 11: helpers ─────────────────────────────────────────────────────

    private function getPdo(): ?\PDO
    {
        $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($this->config, $connection)) {
            return null;
        }

        try {
            return Database::connect($this->config, $connection);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sumariza o estado de saúde dos jobs da camada gold (biomie_pipeline_jobs).
     * Retorna um registro por job_name com: último status, idade, taxa de erro 7d.
     *
     * @return list<array<string,mixed>>
     */
    private function summarizePipelineJobsHealth(\PDO $pdo): array
    {
        $knownJobs = [
            'gold_mart_refresh',
            'compute_opportunities',
            'compute_forecasts',
            'enrich_clients_governance',
        ];

        $placeholders = implode(', ', array_fill(0, count($knownJobs), '?'));
        $lastByJob = [];
        $statsByJob = [];

        try {
            $stmt = $pdo->prepare(
                'SELECT jobs.job_name, jobs.status, jobs.started_at, jobs.completed_at, jobs.duration_ms,
                        COALESCE(stats.total, 0) AS runs_7d,
                        COALESCE(stats.failed, 0) AS failed_7d
                   FROM biomie_pipeline_jobs jobs
                   INNER JOIN (
                       SELECT job_name, MAX(id) AS latest_id
                         FROM biomie_pipeline_jobs
                        WHERE job_name IN (' . $placeholders . ')
                         GROUP BY job_name
                   ) latest ON latest.latest_id = jobs.id AND latest.job_name = jobs.job_name
                   LEFT JOIN (
                       SELECT job_name,
                              COUNT(*) AS total,
                              SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) AS failed
                         FROM biomie_pipeline_jobs
                        WHERE job_name IN (' . $placeholders . ')
                          AND started_at >= NOW() - INTERVAL 7 DAY
                        GROUP BY job_name
                   ) stats ON stats.job_name = jobs.job_name'
            );
            $stmt->execute(array_merge($knownJobs, $knownJobs));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $jobName = (string) ($row['job_name'] ?? '');
                if ($jobName !== '') {
                    $lastByJob[$jobName] = $row;
                    $statsByJob[$jobName] = [
                        'total' => (int) ($row['runs_7d'] ?? 0),
                        'failed' => (int) ($row['failed_7d'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable) {
            return array_map(fn (string $jobName): array => $this->unknownPipelineJob($jobName), $knownJobs);
        }

        $result = [];
        foreach ($knownJobs as $jobName) {
            $last = $lastByJob[$jobName] ?? null;
            $stats = $statsByJob[$jobName] ?? [];
            $completedAt = is_array($last) ? ($last['completed_at'] ?? null) : null;
            $completedTimestamp = is_string($completedAt) && $completedAt !== '' ? strtotime($completedAt) : false;
            $ageSeconds = $completedTimestamp !== false ? max(0, time() - $completedTimestamp) : null;
            $total7d = (int) ($stats['total'] ?? 0);
            $failed7d = (int) ($stats['failed'] ?? 0);

            $derivedStatus = 'pendente';
            if (is_array($last)) {
                $derivedStatus = match ($last['status'] ?? '') {
                    'completed' => ($ageSeconds !== null && $ageSeconds < 90_000) ? 'pronto' : 'atrasado',
                    'running' => 'rodando',
                    default => 'incompleto',
                };
            }

            $result[] = [
                'job_name' => $jobName,
                'status' => $derivedStatus,
                'last_run_at' => $completedAt,
                'age_hours' => $ageSeconds !== null ? round($ageSeconds / 3600, 1) : null,
                'duration_ms' => is_array($last) && is_numeric($last['duration_ms'] ?? null)
                    ? max(0, (int) $last['duration_ms'])
                    : null,
                'runs_7d' => $total7d,
                'failed_7d' => $failed7d,
                'error_rate_7d' => $total7d > 0 ? round($failed7d / $total7d * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function unknownPipelineJob(string $jobName): array
    {
        return [
            'job_name' => $jobName,
            'status' => 'desconhecido',
            'last_run_at' => null,
            'age_hours' => null,
            'duration_ms' => null,
            'runs_7d' => 0,
            'failed_7d' => 0,
            'error_rate_7d' => 0.0,
        ];
    }
}
