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
                    $repo = new CanonicalRepository(Database::connect($config, $connection), $logger);
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
            $pipeline ?? SyncPipelineRepository::boot($config, $logger),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function buildDashboard(int $hours = 24): array
    {
        $hours = max(1, min($hours, 720));
        $freshness = DatasetFreshnessService::boot($this->config, $this->logger, $this->canonical, $this->pipeline);
        $pipelineSummary = $this->pipeline?->summarizeOperationalState(50);
        $telemetry = $this->telemetryRepository;
        $spanRows = $telemetry?->recentSpans($hours, 500) ?? [];
        $eventRows = $telemetry?->recentEvents($hours, 100) ?? [];

        return [
            'generated_at' => date(DATE_ATOM),
            'trace' => RequestContext::traceContext(),
            'window_hours' => $hours,
            'jobs' => $this->summarizeJobs($pipelineSummary, $spanRows),
            'datasets' => $this->summarizeDatasets($freshness),
            'shadow' => $telemetry?->summarizeShadowDiffs($hours) ?? ['critical_open_total' => 0, 'by_endpoint' => []],
            'divergences' => $this->summarizeDivergences(),
            'omie_methods' => $this->summarizeOmieMethodTimings($telemetry, $hours),
            'freshness_hits' => $this->summarizeFreshnessHits($telemetry, $hours),
            'recent_failures' => $this->recentFailures($spanRows, $eventRows),
            'recent_events' => array_slice($eventRows, 0, 25),
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
        $byDomain = [];
        foreach ($recentRuns as $run) {
            if (!is_array($run)) {
                continue;
            }

            $domain = trim((string) ($run['domain_name'] ?? 'unknown'));
            $status = trim((string) ($run['status'] ?? 'unknown'));
            $startedAt = trim((string) ($run['started_at'] ?? ''));
            $finishedAt = trim((string) ($run['finished_at'] ?? ''));
            $durationMs = $this->durationMs($startedAt, $finishedAt);

            $byDomain[$domain]['domain'] = $domain;
            $byDomain[$domain]['last_status'] = $status;
            $byDomain[$domain]['last_started_at'] = $startedAt !== '' ? $startedAt : null;
            $byDomain[$domain]['last_finished_at'] = $finishedAt !== '' ? $finishedAt : null;
            $byDomain[$domain]['durations'][] = $durationMs;
            $byDomain[$domain]['runs'] = (int) (($byDomain[$domain]['runs'] ?? 0) + 1);
        }

        foreach ($byDomain as $domain => $row) {
            $durations = is_array($row['durations'] ?? null) ? $row['durations'] : [];
            $byDomain[$domain]['p95_duration_ms'] = $this->percentile($durations, 95);
            $byDomain[$domain]['avg_duration_ms'] = $this->average($durations);
            unset($byDomain[$domain]['durations']);
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
            'pipeline' => $pipelineSummary,
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
        $lastSync = $this->canonical?->lastSyncAt($start, $end);

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

        $summary = $this->canonical->summarizeDivergenceQueue();
        $openRows = $this->canonical->openDivergences(300);
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

        $rows = $telemetry->metricRows('omie_request_duration_ms', $hours, 3000);
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
     * @return array<string,mixed>
     */
    private function summarizeFreshnessHits(?TelemetryRepository $telemetry, int $hours): array
    {
        if ($telemetry === null) {
            return ['total' => 0, 'by_mode' => []];
        }

        $rows = $telemetry->metricRows('dataset_request_total', $hours, 3000);
        $byMode = [];
        foreach ($rows as $row) {
            $labels = $this->decodeJsonObject((string) ($row['labels_json'] ?? ''));
            $mode = trim((string) ($labels['data_mode'] ?? 'unknown'));
            $byMode[$mode] = (int) (($byMode[$mode] ?? 0) + (int) ($row['numeric_value'] ?? 0));
        }

        return [
            'total' => array_sum($byMode),
            'by_mode' => $byMode,
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
            $failedSpans[] = $row;
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
            $errorEvents[] = $row;
            if (count($errorEvents) >= 20) {
                break;
            }
        }

        return [
            'failed_spans' => $failedSpans,
            'error_events' => $errorEvents,
        ];
    }

    private function durationMs(string $startedAt, string $finishedAt): int
    {
        if ($startedAt === '' || $finishedAt === '') {
            return 0;
        }

        $start = strtotime($startedAt);
        $end = strtotime($finishedAt);
        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, (int) (($end - $start) * 1000));
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
}
