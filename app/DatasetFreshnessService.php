<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;
use Throwable;

final class DatasetFreshnessService
{
    /** @var array<string,list<string>> */
    private const DATASET_DOMAINS = [
        'overview' => ['commercial'],
        'orders' => ['commercial'],
        'vendors' => ['commercial'],
        'finance_overview' => ['finance'],
        'finance_titles' => ['finance'],
        'reconciliation' => ['commercial', 'finance'],
    ];

    /** @var array<string,int> */
    private const DEFAULT_STALE_AFTER_MINUTES = [
        'overview' => 180,
        'orders' => 180,
        'vendors' => 180,
        'finance_overview' => 360,
        'finance_titles' => 360,
        'reconciliation' => 720,
    ];

    /** @var list<array<string,mixed>>|null */
    private ?array $recentRunsCache = null;

    /** @var array<string,mixed>|null */
    private ?array $pipelineSummaryCache = null;

    /** @var array<string,mixed>|null */
    private ?array $divergenceSummaryCache = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?CanonicalRepository $canonical = null,
        private readonly ?SyncPipelineRepository $pipeline = null,
    ) {
    }

    public static function boot(
        Config $config,
        Logger $logger,
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
            } catch (Throwable) {
                $resolvedCanonical = null;
            }
        }

        $resolvedPipeline = $pipeline;
        if ($resolvedPipeline === null) {
            try {
                $resolvedPipeline = SyncPipelineRepository::boot($config, $logger);
            } catch (Throwable) {
                $resolvedPipeline = null;
            }
        }

        return new self($config, $logger, $resolvedCanonical, $resolvedPipeline);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function attach(string $dataset, array $payload, array $options = []): array
    {
        $contract = $this->buildContract($dataset, $payload, $options);
        $trace = RequestContext::traceContext();

        $payload['data_contract'] = $contract;
        $payload['source_of_truth'] = $contract['source_of_truth'];
        $payload['freshness_at'] = $contract['freshness_at'];
        $payload['coverage_start'] = $contract['coverage_start'];
        $payload['coverage_end'] = $contract['coverage_end'];
        $payload['last_success_at'] = $contract['last_success_at'];
        $payload['last_reconcile_at'] = $contract['last_reconcile_at'];
        $payload['reconciled_until'] = $contract['reconciled_until'];
        $payload['staleness_reason'] = $contract['staleness_reason'];
        $payload['trace'] = $trace;

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['data_contract'] = $contract;
        $payload['meta']['source_of_truth'] = $contract['source_of_truth'];
        $payload['meta']['freshness_at'] = $contract['freshness_at'];
        $payload['meta']['coverage_start'] = $contract['coverage_start'];
        $payload['meta']['coverage_end'] = $contract['coverage_end'];
        $payload['meta']['last_success_at'] = $contract['last_success_at'];
        $payload['meta']['last_reconcile_at'] = $contract['last_reconcile_at'];
        $payload['meta']['reconciled_until'] = $contract['reconciled_until'];
        $payload['meta']['staleness_reason'] = $contract['staleness_reason'];
        $payload['meta']['reconcile_status'] = $contract['reconcile_status'];
        $payload['meta']['reconciliation_pct'] = $contract['reconciliation_pct'];
        $payload['meta']['open_divergences'] = $contract['open_divergences'];
        $payload['meta']['has_retry_or_delay'] = $contract['has_retry_or_delay'];
        $payload['meta']['data_mode'] = $contract['data_mode'];
        $payload['meta']['trace'] = $trace;

        if (!isset($payload['source']) || trim((string) $payload['source']) === '') {
            $payload['source'] = $contract['source'];
        }

        if (!empty($options['observe_request'])) {
            $telemetry = Telemetry::global();
            $telemetry?->recordMetric('dataset_request_total', 1, 'counter', [
                'dataset' => $dataset,
                'data_mode' => (string) $contract['data_mode'],
                'reconcile_status' => (string) $contract['reconcile_status'],
                'staleness_reason' => (string) ($contract['staleness_reason'] ?? 'none'),
                'source' => (string) $contract['source'],
            ], 'dataset', 'freshness');

            $lagSeconds = (int) ($contract['freshness_lag_seconds'] ?? 0);
            if ($lagSeconds > 0) {
                $telemetry?->recordMetric('dataset_freshness_lag_seconds', $lagSeconds, 'histogram', [
                    'dataset' => $dataset,
                    'data_mode' => (string) $contract['data_mode'],
                ], 'dataset', 'freshness');
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function buildContract(string $dataset, array $payload, array $options = []): array
    {
        $period = $this->resolvePeriod($payload, $options);
        $domains = $this->resolveDomains($dataset, $options);
        $source = $this->resolveSource($payload, $options);
        $servedFrom = $this->resolveServedFrom($payload);
        $freshnessAt = $this->resolveFreshnessAt($payload);
        $operationState = $this->resolveOperationState($domains);
        $openDivergences = $this->resolveOpenDivergences($payload);
        $reconciledUntil = $this->resolveReconciledUntil($payload, $period, $operationState, $freshnessAt);
        $reconcileStatus = $this->resolveReconcileStatus($period, $reconciledUntil);
        $reconciliationPct = $this->resolveReconciliationPct($reconcileStatus, $openDivergences);
        $stalenessReason = $this->resolveStalenessReason(
            $dataset,
            $servedFrom,
            $period,
            $freshnessAt,
            $reconciledUntil,
            $reconcileStatus,
            $operationState
        );
        $dataMode = $this->resolveDataMode($servedFrom, $reconcileStatus, $stalenessReason);
        $freshnessLagSeconds = $this->ageSeconds($freshnessAt);
        $hasRetryOrDelay = (bool) ($operationState['retry_present'] ?? false) || (bool) ($operationState['delay_present'] ?? false);
        $completenessStatus = $this->resolveCompletenessStatus($stalenessReason, $hasRetryOrDelay, $openDivergences);

        return [
            'dataset' => $dataset,
            'source' => $source,
            'source_of_truth' => $this->resolveSourceOfTruth($source, $servedFrom),
            'origin' => $servedFrom,
            'freshness_at' => $freshnessAt,
            'freshness_lag_seconds' => $freshnessLagSeconds,
            'coverage_start' => $period['start'],
            'coverage_end' => $period['end'],
            'last_success_at' => $operationState['last_success_at'] ?? null,
            'last_reconcile_at' => $operationState['last_reconcile_at'] ?? null,
            'reconciled_until' => $reconciledUntil,
            'reconcile_status' => $reconcileStatus,
            'reconciliation_pct' => $reconciliationPct,
            'open_divergences' => $openDivergences,
            'has_retry_or_delay' => $hasRetryOrDelay,
            'retry_present' => (bool) ($operationState['retry_present'] ?? false),
            'delay_present' => (bool) ($operationState['delay_present'] ?? false),
            'pending_backlog_total' => (int) ($operationState['pending_backlog_total'] ?? 0),
            'pending_backlog_age_seconds' => $operationState['pending_backlog_age_seconds'] ?? null,
            'staleness_reason' => $stalenessReason,
            'data_mode' => $dataMode,
            'completeness_status' => $completenessStatus,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array{start:?string,end:?string}
     */
    private function resolvePeriod(array $payload, array $options): array
    {
        $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];

        return [
            'start' => $this->normalizeDate($options['coverage_start'] ?? $period['start'] ?? null),
            'end' => $this->normalizeDate($options['coverage_end'] ?? $period['end'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private function resolveDomains(string $dataset, array $options): array
    {
        $domains = $options['domains'] ?? self::DATASET_DOMAINS[$dataset] ?? [];
        if (!is_array($domains)) {
            return [];
        }

        $normalized = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim((string) $domain));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private function resolveSource(array $payload, array $options): string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $candidates = [
            $options['source'] ?? null,
            $payload['source'] ?? null,
            $meta['snapshot_source'] ?? null,
            $meta['source'] ?? null,
            $meta['served_from'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveServedFrom(array $payload): string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $servedFrom = trim((string) ($meta['served_from'] ?? $payload['source'] ?? 'unknown'));
        return $servedFrom !== '' ? $servedFrom : 'unknown';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveFreshnessAt(array $payload): ?string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $candidates = [
            $meta['freshness_at'] ?? null,
            $meta['last_sync'] ?? null,
            $meta['snapshot_last_sync'] ?? null,
            $payload['last_sync'] ?? null,
            $meta['generated_at'] ?? null,
            $meta['snapshot_generated_at'] ?? null,
            $payload['generated_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeTimestamp($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param list<string> $domains
     * @return array<string,mixed>
     */
    private function resolveOperationState(array $domains): array
    {
        $retryPresent = false;
        $delayPresent = false;
        $lastSuccessAt = null;
        $lastReconcileAt = null;

        $cursorRows = $this->pipeline !== null ? $this->pipeline->listCursors($domains) : [];
        foreach ($cursorRows as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, ['failed_retryable', 'completed_with_warnings'], true)) {
                $retryPresent = true;
            }

            $lastSuccessAt = $this->pickMaxTimestamp($lastSuccessAt, $row['last_success_at'] ?? null);
        }

        $recentRuns = $this->recentRuns();
        foreach ($recentRuns as $row) {
            $domain = strtolower(trim((string) ($row['domain_name'] ?? '')));
            if ($domains !== [] && !in_array($domain, $domains, true)) {
                continue;
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, ['failed_retryable', 'completed_with_warnings'], true)) {
                $retryPresent = true;
            }

            $runType = strtolower(trim((string) ($row['run_type'] ?? '')));
            if ($runType === 'reconcile' && in_array($status, ['completed', 'completed_with_warnings'], true)) {
                $lastReconcileAt = $this->pickMaxTimestamp($lastReconcileAt, $row['period_end'] ?? $row['finished_at'] ?? null);
            }
        }

        $pipelineSummary = $this->pipelineSummary();
        $pendingTotal = (int) ($pipelineSummary['webhook_inbox']['pending_total'] ?? 0);
        $pendingAgeSeconds = $pipelineSummary['webhook_inbox']['oldest_pending_age_seconds'] ?? null;
        $delayPresent = $pendingTotal > 0;

        return [
            'last_success_at' => $lastSuccessAt,
            'last_reconcile_at' => $lastReconcileAt,
            'retry_present' => $retryPresent,
            'delay_present' => $delayPresent,
            'pending_backlog_total' => $pendingTotal,
            'pending_backlog_age_seconds' => is_numeric($pendingAgeSeconds) ? (int) $pendingAgeSeconds : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveOpenDivergences(array $payload): int
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $candidates = [
            $payload['divergences_count'] ?? null,
            $meta['divergences_count'] ?? null,
            $payload['queue_count'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(0, (int) $candidate);
            }
        }

        $summary = $this->divergenceSummary();
        return (int) ($summary['active_total'] ?? 0);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{start:?string,end:?string} $period
     * @param array<string,mixed> $operationState
     */
    private function resolveReconciledUntil(
        array $payload,
        array $period,
        array $operationState,
        ?string $freshnessAt,
    ): ?string {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $candidates = [
            $payload['reconciled_until'] ?? null,
            $meta['reconciled_until'] ?? null,
            $operationState['last_reconcile_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeTimestamp($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $servedFrom = strtolower($this->resolveServedFrom($payload));
        if (
            $period['end'] !== null
            && (
                str_contains($servedFrom, 'historical')
                || str_contains($servedFrom, 'snapshot')
                || str_contains($servedFrom, 'canonical-db')
            )
            && $this->isClosedCoverage($period['end'])
        ) {
            return $period['end'] . ' 23:59:59';
        }

        return $freshnessAt;
    }

    /**
     * @param array{start:?string,end:?string} $period
     */
    private function resolveReconcileStatus(array $period, ?string $reconciledUntil): string
    {
        if ($period['end'] === null) {
            return $reconciledUntil !== null ? 'partial' : 'unavailable';
        }

        $coverageEnd = $period['end'] . ' 23:59:59';
        if ($reconciledUntil !== null && strcmp($reconciledUntil, $coverageEnd) >= 0) {
            return 'reconciled';
        }

        if ($reconciledUntil !== null) {
            return 'partial';
        }

        return $this->isClosedCoverage($period['end']) ? 'pending' : 'in_progress';
    }

    private function resolveReconciliationPct(string $reconcileStatus, int $openDivergences): float
    {
        $summary = $this->divergenceSummary();
        $byStatus = is_array($summary['by_status'] ?? null) ? $summary['by_status'] : [];
        $total = 0;
        foreach ($byStatus as $count) {
            $total += (int) $count;
        }

        if ($total > 0) {
            $resolved = (int) ($byStatus['resolvida'] ?? 0) + (int) ($byStatus['ignorada_regra'] ?? 0);
            return round(($resolved / $total) * 100, 2);
        }

        if ($openDivergences > 0) {
            return 0.0;
        }

        return $reconcileStatus === 'reconciled' ? 100.0 : 0.0;
    }

    /**
     * @param array{start:?string,end:?string} $period
     * @param array<string,mixed> $operationState
     */
    private function resolveStalenessReason(
        string $dataset,
        string $servedFrom,
        array $period,
        ?string $freshnessAt,
        ?string $reconciledUntil,
        string $reconcileStatus,
        array $operationState,
    ): ?string {
        $normalizedServedFrom = strtolower($servedFrom);
        if (str_contains($normalizedServedFrom, 'cache-stale') || str_contains($normalizedServedFrom, 'stale-if-error')) {
            return 'cache_fallback';
        }

        if ($freshnessAt === null) {
            return 'missing_freshness_marker';
        }

        if ((bool) ($operationState['retry_present'] ?? false) || (bool) ($operationState['delay_present'] ?? false)) {
            return 'pending_retry_or_backlog';
        }

        if ($period['end'] !== null && $this->isClosedCoverage($period['end']) && $reconcileStatus !== 'reconciled') {
            return 'reconcile_pending';
        }

        $ageSeconds = $this->ageSeconds($freshnessAt);
        $thresholdSeconds = max(1, $this->staleAfterMinutes($dataset)) * 60;
        if ($ageSeconds !== null && !$this->isClosedCoverage($period['end']) && $ageSeconds > $thresholdSeconds) {
            return 'freshness_sla_exceeded';
        }

        if ($period['end'] !== null && $reconciledUntil !== null) {
            $coverageEnd = $period['end'] . ' 23:59:59';
            if (strcmp($reconciledUntil, $coverageEnd) < 0 && $this->isClosedCoverage($period['end'])) {
                return 'coverage_not_fully_reconciled';
            }
        }

        return null;
    }

    private function resolveDataMode(string $servedFrom, string $reconcileStatus, ?string $stalenessReason): string
    {
        if ($stalenessReason !== null) {
            return 'stale';
        }

        if ($reconcileStatus === 'reconciled') {
            return 'reconciled';
        }

        $normalized = strtolower($servedFrom);
        if (str_contains($normalized, 'live') || str_contains($normalized, 'manual')) {
            return 'live';
        }

        return 'delta';
    }

    /**
     * Normaliza a fonte efetiva do dado em um dos valores do contrato:
     * canonical | legacy | snapshot | unknown
     */
    private function resolveSourceOfTruth(string $source, string $servedFrom): string
    {
        $normalized = strtolower(trim($source !== '' ? $source : $servedFrom));

        if (str_contains($normalized, 'canonical') || str_contains($normalized, 'canonical-db')) {
            return 'canonical';
        }

        if (str_contains($normalized, 'snapshot') || str_contains($normalized, 'historical')) {
            return 'snapshot';
        }

        if (str_contains($normalized, 'legacy') || str_contains($normalized, 'omie-direct') || str_contains($normalized, 'fallback')) {
            return 'legacy';
        }

        return 'unknown';
    }

    private function resolveCompletenessStatus(?string $stalenessReason, bool $hasRetryOrDelay, int $openDivergences): string
    {
        if ($stalenessReason !== null || $hasRetryOrDelay || $openDivergences > 0) {
            return 'partial';
        }

        return 'complete';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentRuns(): array
    {
        if ($this->recentRunsCache !== null) {
            return $this->recentRunsCache;
        }

        if ($this->pipeline === null) {
            $this->recentRunsCache = [];
            return $this->recentRunsCache;
        }

        try {
            $this->recentRunsCache = $this->pipeline->listRecentRuns(120);
        } catch (Throwable) {
            $this->recentRunsCache = [];
        }

        return $this->recentRunsCache;
    }

    /**
     * @return array<string,mixed>
     */
    private function pipelineSummary(): array
    {
        if ($this->pipelineSummaryCache !== null) {
            return $this->pipelineSummaryCache;
        }

        if ($this->pipeline === null) {
            $this->pipelineSummaryCache = [];
            return $this->pipelineSummaryCache;
        }

        try {
            $this->pipelineSummaryCache = $this->pipeline->summarizeOperationalState(60);
        } catch (Throwable) {
            $this->pipelineSummaryCache = [];
        }

        return $this->pipelineSummaryCache;
    }

    /**
     * @return array<string,mixed>
     */
    private function divergenceSummary(): array
    {
        if ($this->divergenceSummaryCache !== null) {
            return $this->divergenceSummaryCache;
        }

        if ($this->canonical === null) {
            $this->divergenceSummaryCache = [];
            return $this->divergenceSummaryCache;
        }

        try {
            $this->divergenceSummaryCache = $this->canonical->summarizeDivergenceQueue();
        } catch (Throwable) {
            $this->divergenceSummaryCache = [];
        }

        return $this->divergenceSummaryCache;
    }

    private function staleAfterMinutes(string $dataset): int
    {
        $configured = $this->config->get('data_contract.stale_after_minutes', []);
        if (is_array($configured) && isset($configured[$dataset]) && is_numeric($configured[$dataset])) {
            return max(1, (int) $configured[$dataset]);
        }

        return self::DEFAULT_STALE_AFTER_MINUTES[$dataset] ?? 360;
    }

    private function pickMaxTimestamp(?string $current, mixed $candidate): ?string
    {
        $normalized = $this->normalizeTimestamp($candidate);
        if ($normalized === null) {
            return $current;
        }

        if ($current === null || strcmp($normalized, $current) > 0) {
            return $normalized;
        }

        return $current;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function ageSeconds(?string $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            return max(0, time() - (new DateTimeImmutable($timestamp))->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }

    private function isClosedCoverage(?string $coverageEnd): bool
    {
        if ($coverageEnd === null) {
            return false;
        }

        try {
            $coverageDate = new DateTimeImmutable($coverageEnd . ' 23:59:59');
            $today = new DateTimeImmutable('today');
            return $coverageDate < $today;
        } catch (Throwable) {
            return false;
        }
    }
}
