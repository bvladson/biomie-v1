<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

final class ShadowValidationService
{
    private static bool $shutdownRegistered = false;

    /** @var list<callable():void> */
    private static array $deferredTasks = [];

    /**
     * @param array<string,mixed> $settings
     */
    private function __construct(
        private readonly Logger $logger,
        private readonly array $settings,
        private readonly ?PDO $pdo = null,
        private readonly string $fallbackLogFile = '',
    ) {
    }

    public static function boot(Config $config, Logger $logger, ?PDO $pdo = null): self
    {
        self::registerShutdownRunner();

        return new self(
            $logger,
            [
                'enabled' => (bool) $config->get('shadow_validation.enabled', false),
                'sample_rate' => max(0.0, min(100.0, (float) $config->get('shadow_validation.sample_rate', 0))),
                'persist_matches' => (bool) $config->get('shadow_validation.persist_matches', true),
                'endpoints' => is_array($config->get('shadow_validation.endpoints', []))
                    ? $config->get('shadow_validation.endpoints', [])
                    : [],
            ],
            $pdo ?? self::connectDefaultPdo($config, $logger),
            Bootstrap::rootPath('storage/logs/shadow_validation.log')
        );
    }

    /**
     * @param callable():mixed $runLegacy
     * @param callable():mixed $runCandidate
     * @param array<string,mixed> $queryContext
     */
    public function defer(string $endpoint, callable $runLegacy, callable $runCandidate, array $queryContext = []): void
    {
        if (!$this->isEnabledForEndpoint($endpoint)) {
            return;
        }

        $sampling = $this->samplingDecision($endpoint, $queryContext);
        if (!$sampling['selected']) {
            return;
        }

        self::$deferredTasks[] = function () use ($endpoint, $runLegacy, $runCandidate, $queryContext, $sampling): void {
            $this->runValidation($endpoint, $runLegacy, $runCandidate, $queryContext, $sampling);
        };
    }

    /**
     * @param callable():mixed $runLegacy
     * @param callable():mixed $runCandidate
     * @param array<string,mixed> $queryContext
     * @param array{selected:bool,sample_rate:float,sample_bucket:int,query_signature:string,query_context_json:string} $sampling
     */
    private function runValidation(
        string $endpoint,
        callable $runLegacy,
        callable $runCandidate,
        array $queryContext,
        array $sampling,
    ): void {
        $record = [
            'endpoint_name' => $endpoint,
            'query_signature' => $sampling['query_signature'],
            'query_context_json' => $sampling['query_context_json'],
            'legacy_payload_hash' => null,
            'candidate_payload_hash' => null,
            'diff_type' => 'shadow_skipped',
            'severity' => 'none',
            'summary' => 'Shadow nao executado.',
            'diff_json' => $this->encodeJson([
                'request_id' => RequestContext::id(),
                'query_context' => $queryContext,
            ]),
            'sample_rate' => $sampling['sample_rate'],
            'sample_bucket' => $sampling['sample_bucket'],
            'request_id' => RequestContext::id(),
            'legacy_source' => null,
            'candidate_source' => null,
        ];

        try {
            $legacyPayload = $runLegacy();
            $candidatePayload = $runCandidate();

            if (!is_array($legacyPayload) || $legacyPayload === []) {
                $record['diff_type'] = 'legacy_unavailable';
                $record['severity'] = 'medium';
                $record['summary'] = 'Leitura legacy indisponivel para shadow.';
                $this->persistRecord($record);
                return;
            }

            $record['legacy_payload_hash'] = $this->payloadHash($legacyPayload);
            $record['legacy_source'] = trim((string) ($legacyPayload['source'] ?? $legacyPayload['mode'] ?? 'legacy'));

            if (!is_array($candidatePayload) || $candidatePayload === []) {
                $record['diff_type'] = 'candidate_unavailable';
                $record['severity'] = 'low';
                $record['summary'] = 'Leitura candidata indisponivel para shadow.';
                $this->persistRecord($record);
                return;
            }

            $record['candidate_payload_hash'] = $this->payloadHash($candidatePayload);
            $record['candidate_source'] = trim((string) ($candidatePayload['source'] ?? $candidatePayload['mode'] ?? 'candidate'));

            $comparison = $this->comparePayloads($endpoint, $legacyPayload, $candidatePayload);
            $record['diff_type'] = $comparison['diff_type'];
            $record['severity'] = $comparison['severity'];
            $record['summary'] = $comparison['summary'];
            $record['diff_json'] = $this->encodeJson([
                'request_id' => RequestContext::id(),
                'query_context' => $queryContext,
                'details' => $comparison['details'],
            ]);

            if ($comparison['diff_type'] === 'match' && !$this->shouldPersistMatches()) {
                return;
            }

            $this->persistRecord($record);
        } catch (Throwable $exception) {
            $record['diff_type'] = 'candidate_error';
            $record['severity'] = 'high';
            $record['summary'] = 'Shadow falhou: ' . $this->truncate($exception->getMessage(), 180);
            $record['diff_json'] = $this->encodeJson([
                'request_id' => RequestContext::id(),
                'query_context' => $queryContext,
                'error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ]);
            $this->persistRecord($record);
            $this->logger->warning('Shadow validation falhou.', [
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
    }

    private function isEnabledForEndpoint(string $endpoint): bool
    {
        if (!(bool) ($this->settings['enabled'] ?? false)) {
            return false;
        }

        $endpointSettings = $this->endpointSettings($endpoint);
        if (array_key_exists('enabled', $endpointSettings)) {
            return (bool) $endpointSettings['enabled'];
        }

        return true;
    }

    private function shouldPersistMatches(): bool
    {
        return (bool) ($this->settings['persist_matches'] ?? true);
    }

    /**
     * @param array<string,mixed> $queryContext
     * @return array{selected:bool,sample_rate:float,sample_bucket:int,query_signature:string,query_context_json:string}
     */
    private function samplingDecision(string $endpoint, array $queryContext): array
    {
        $queryContext['endpoint'] = $endpoint;
        $queryContextJson = $this->encodeJson($this->normalizeForHash($queryContext));
        $querySignature = hash('sha256', $queryContextJson);
        $sampleRate = $this->sampleRateForEndpoint($endpoint);
        $seed = $endpoint . '|' . RequestContext::id() . '|' . $querySignature;
        $sampleBucket = (int) (hexdec(substr(hash('sha256', $seed), 0, 8)) % 100);

        return [
            'selected' => $sampleRate > 0 && $sampleBucket < $sampleRate,
            'sample_rate' => $sampleRate,
            'sample_bucket' => $sampleBucket,
            'query_signature' => $querySignature,
            'query_context_json' => $queryContextJson,
        ];
    }

    private function sampleRateForEndpoint(string $endpoint): float
    {
        $endpointSettings = $this->endpointSettings($endpoint);
        if (array_key_exists('sample_rate', $endpointSettings)) {
            return max(0.0, min(100.0, (float) $endpointSettings['sample_rate']));
        }

        return max(0.0, min(100.0, (float) ($this->settings['sample_rate'] ?? 0)));
    }

    /**
     * @return array<string,mixed>
     */
    private function endpointSettings(string $endpoint): array
    {
        $endpoints = is_array($this->settings['endpoints'] ?? null) ? $this->settings['endpoints'] : [];
        $settings = $endpoints[$endpoint] ?? [];
        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function comparePayloads(string $endpoint, array $legacy, array $candidate): array
    {
        return match ($endpoint) {
            'overview' => $this->compareOverview($legacy, $candidate),
            'orders' => $this->compareOrders($legacy, $candidate),
            'vendors' => $this->compareVendors($legacy, $candidate),
            'finance_overview' => $this->compareFinanceOverview($legacy, $candidate),
            'finance_titles' => $this->compareFinanceTitles($legacy, $candidate),
            'reconciliation' => $this->compareReconciliation($legacy, $candidate),
            default => $this->compareGeneric($legacy, $candidate),
        };
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareOverview(array $legacy, array $candidate): array
    {
        $summaryDiffs = $this->numericDiffs($legacy, $candidate, [
            'summary.revenue',
            'summary.gross_revenue',
            'summary.orders',
            'summary.pending_orders',
            'summary.cancelled_orders',
            'summary.active_vendors',
            'summary.avg_ticket',
        ]);
        $rankingDiff = $this->compareKeySets(
            $this->extractKeys($legacy['vendor_ranking'] ?? [], ['vendor_id', 'vendor_name']),
            $this->extractKeys($candidate['vendor_ranking'] ?? [], ['vendor_id', 'vendor_name']),
            12
        );
        $divergenceDiff = $this->compareKeySets(
            $this->extractKeys($legacy['divergences'] ?? [], ['key', 'entity_key', 'message']),
            $this->extractKeys($candidate['divergences'] ?? [], ['key', 'entity_key', 'message']),
            10
        );

        return $this->buildComparisonResult('overview', [
            'summary' => $summaryDiffs,
            'vendor_ranking' => $rankingDiff,
            'divergences' => $divergenceDiff,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareOrders(array $legacy, array $candidate): array
    {
        $summaryDiffs = $this->numericDiffs($legacy, $candidate, [
            'summary.revenue',
            'summary.orders',
            'summary.pending_orders',
            'summary.cancelled_orders',
            'summary.active_vendors',
        ]);

        $ordersDiff = $this->compareKeySets(
            $this->extractKeys($legacy['orders'] ?? [], ['codigo', 'codigo_pedido', 'order_id', 'pedido_id_omie']),
            $this->extractKeys($candidate['orders'] ?? [], ['codigo', 'codigo_pedido', 'order_id', 'pedido_id_omie']),
            30
        );

        return $this->buildComparisonResult('orders', [
            'summary' => $summaryDiffs,
            'orders' => $ordersDiff,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareVendors(array $legacy, array $candidate): array
    {
        $summaryDiffs = $this->numericDiffs($legacy, $candidate, [
            'summary.revenue',
            'summary.orders',
            'summary.pending_orders',
            'summary.cancelled_orders',
            'summary.active_vendors',
            'summary_status.billed_value',
            'summary_status.authorized_value',
            'summary_status.cancelled_value',
        ]);

        $matrixDiff = $this->compareKeySets(
            $this->extractKeys($legacy['vendor_status_matrix'] ?? $legacy['vendors'] ?? [], ['vendor_id', 'vendor_name']),
            $this->extractKeys($candidate['vendor_status_matrix'] ?? $candidate['vendors'] ?? [], ['vendor_id', 'vendor_name']),
            20
        );

        return $this->buildComparisonResult('vendors', [
            'summary' => $summaryDiffs,
            'vendors' => $matrixDiff,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareFinanceOverview(array $legacy, array $candidate): array
    {
        $summaryDiffs = $this->numericDiffs($legacy, $candidate, [
            'summary.received',
            'summary.open',
            'summary.overdue',
            'summary.received_titles',
            'summary.open_titles',
            'summary.overdue_titles',
            'summary.vendors',
        ]);

        $receivedDiff = $this->compareKeySets(
            $this->extractKeys($legacy['received_rows'] ?? [], ['title_id', 'document', 'document_label']),
            $this->extractKeys($candidate['received_rows'] ?? [], ['title_id', 'document', 'document_label']),
            20
        );
        $openDiff = $this->compareKeySets(
            $this->extractKeys($legacy['open_titles'] ?? [], ['title_id', 'document', 'document_label']),
            $this->extractKeys($candidate['open_titles'] ?? [], ['title_id', 'document', 'document_label']),
            20
        );
        $overdueDiff = $this->compareKeySets(
            $this->extractKeys($legacy['overdue_titles'] ?? [], ['title_id', 'document', 'document_label']),
            $this->extractKeys($candidate['overdue_titles'] ?? [], ['title_id', 'document', 'document_label']),
            20
        );

        return $this->buildComparisonResult('finance_overview', [
            'summary' => $summaryDiffs,
            'received_rows' => $receivedDiff,
            'open_titles' => $openDiff,
            'overdue_titles' => $overdueDiff,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareFinanceTitles(array $legacy, array $candidate): array
    {
        $bucketLegacy = trim((string) ($legacy['bucket'] ?? 'open'));
        $bucketCandidate = trim((string) ($candidate['bucket'] ?? 'open'));
        $bucketMismatch = $bucketLegacy !== $bucketCandidate
            ? ['legacy' => $bucketLegacy, 'candidate' => $bucketCandidate]
            : [];

        $rowDiff = $this->compareKeySets(
            $this->extractKeys($legacy['rows'] ?? [], ['title_id', 'document', 'document_label']),
            $this->extractKeys($candidate['rows'] ?? [], ['title_id', 'document', 'document_label']),
            30
        );

        $totals = [
            'row_count' => [
                'legacy' => is_array($legacy['rows'] ?? null) ? count($legacy['rows']) : 0,
                'candidate' => is_array($candidate['rows'] ?? null) ? count($candidate['rows']) : 0,
            ],
            'amount_total' => [
                'legacy' => $this->sumRowAmounts($legacy['rows'] ?? []),
                'candidate' => $this->sumRowAmounts($candidate['rows'] ?? []),
            ],
        ];

        return $this->buildComparisonResult('finance_titles', [
            'bucket' => $bucketMismatch,
            'rows' => $rowDiff,
            'totals' => $totals,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareReconciliation(array $legacy, array $candidate): array
    {
        $totalsDiffs = $this->numericDiffs($legacy, $candidate, [
            'totals.faturado',
            'totals.autorizado',
            'totals.cancelado',
            'totals.pedidos_faturados',
            'totals.pedidos_autorizados',
            'totals.pedidos_cancelados',
            'divergences_count',
        ]);

        $vendorDiff = $this->compareKeySets(
            $this->extractKeys($legacy['vendors'] ?? [], ['codigo_vendedor', 'nome']),
            $this->extractKeys($candidate['vendors'] ?? [], ['codigo_vendedor', 'nome']),
            20
        );
        $divergenceDiff = $this->compareKeySets(
            $this->extractKeys($legacy['divergences'] ?? [], ['key', 'entity_key', 'message']),
            $this->extractKeys($candidate['divergences'] ?? [], ['key', 'entity_key', 'message']),
            20
        );

        return $this->buildComparisonResult('reconciliation', [
            'totals' => $totalsDiffs,
            'vendors' => $vendorDiff,
            'divergences' => $divergenceDiff,
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function compareGeneric(array $legacy, array $candidate): array
    {
        $legacyHash = $this->payloadHash($legacy);
        $candidateHash = $this->payloadHash($candidate);
        $details = [
            'legacy_hash' => $legacyHash,
            'candidate_hash' => $candidateHash,
        ];

        if ($legacyHash === $candidateHash) {
            return [
                'diff_type' => 'match',
                'severity' => 'none',
                'summary' => 'Shadow sem diferencas materiais.',
                'details' => $details,
            ];
        }

        return [
            'diff_type' => 'payload_mismatch',
            'severity' => 'medium',
            'summary' => 'Shadow encontrou diferenca de payload bruto.',
            'details' => $details,
        ];
    }

    /**
     * @param array<string,mixed> $sections
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    private function buildComparisonResult(string $endpoint, array $sections): array
    {
        $material = [];
        $maxSeverity = 'none';

        foreach ($sections as $name => $section) {
            if (!$this->sectionHasDiff($section)) {
                continue;
            }

            $material[$name] = $section;
            $maxSeverity = $this->maxSeverity($maxSeverity, $this->severityForSection($section));
        }

        if ($material === []) {
            return [
                'diff_type' => 'match',
                'severity' => 'none',
                'summary' => sprintf('Shadow do endpoint %s sem diferencas materiais.', $endpoint),
                'details' => $sections,
            ];
        }

        $summary = sprintf(
            'Shadow do endpoint %s encontrou diferenca em %d bloco(s): %s.',
            $endpoint,
            count($material),
            implode(', ', array_keys($material))
        );

        return [
            'diff_type' => 'material_difference',
            'severity' => $maxSeverity,
            'summary' => $summary,
            'details' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $section
     */
    private function severityForSection(array $section): string
    {
        if (($section['missing_count'] ?? 0) > 0 || ($section['extra_count'] ?? 0) > 0) {
            return 'high';
        }

        if (($section['mismatches_count'] ?? 0) > 0) {
            return 'medium';
        }

        if (($section['legacy'] ?? null) !== ($section['candidate'] ?? null)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<string,mixed> $section
     */
    private function sectionHasDiff(array $section): bool
    {
        if ($section === []) {
            return false;
        }

        if (($section['missing_count'] ?? 0) > 0 || ($section['extra_count'] ?? 0) > 0 || ($section['mismatches_count'] ?? 0) > 0) {
            return true;
        }

        if (array_key_exists('legacy', $section) && array_key_exists('candidate', $section)) {
            if (is_numeric($section['legacy']) || is_numeric($section['candidate'])) {
                return abs((float) $section['legacy'] - (float) $section['candidate']) > 0.01;
            }

            return $section['legacy'] !== $section['candidate'];
        }

        return false;
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    private function numericDiffs(array $legacy, array $candidate, array $paths): array
    {
        $mismatches = [];

        foreach ($paths as $path) {
            $legacyValue = Support::toFloat(Support::get($legacy, $path, 0));
            $candidateValue = Support::toFloat(Support::get($candidate, $path, 0));
            if (abs($legacyValue - $candidateValue) <= 0.01) {
                continue;
            }

            $mismatches[$path] = [
                'legacy' => $legacyValue,
                'candidate' => $candidateValue,
                'delta' => Support::money($candidateValue - $legacyValue),
            ];
        }

        return [
            'mismatches_count' => count($mismatches),
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @param list<string> $legacyKeys
     * @param list<string> $candidateKeys
     * @return array<string,mixed>
     */
    private function compareKeySets(array $legacyKeys, array $candidateKeys, int $previewLimit = 20): array
    {
        $legacyKeys = array_values(array_unique(array_filter($legacyKeys, static fn (string $key): bool => trim($key) !== '')));
        $candidateKeys = array_values(array_unique(array_filter($candidateKeys, static fn (string $key): bool => trim($key) !== '')));

        $missing = array_values(array_diff($legacyKeys, $candidateKeys));
        $extra = array_values(array_diff($candidateKeys, $legacyKeys));

        return [
            'legacy_count' => count($legacyKeys),
            'candidate_count' => count($candidateKeys),
            'missing_count' => count($missing),
            'extra_count' => count($extra),
            'missing' => array_slice($missing, 0, $previewLimit),
            'extra' => array_slice($extra, 0, $previewLimit),
        ];
    }

    /**
     * @param mixed $rows
     * @param list<string> $keys
     * @return list<string>
     */
    private function extractKeys(mixed $rows, array $keys): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $extracted = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $extracted[] = $value;
                    break;
                }
            }
        }

        return $extracted;
    }

    /**
     * @param array<string,mixed> $legacy
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function compareFreshness(array $legacy, array $candidate): array
    {
        $paths = [
            'data_contract.freshness_at',
            'data_contract.coverage_start',
            'data_contract.coverage_end',
            'data_contract.reconciled_until',
            'data_contract.data_mode',
        ];

        $diffs = [];
        foreach ($paths as $path) {
            $legacyValue = Support::get($legacy, $path, null);
            $candidateValue = Support::get($candidate, $path, null);
            if ($legacyValue === $candidateValue) {
                continue;
            }
            $diffs[$path] = [
                'legacy' => $legacyValue,
                'candidate' => $candidateValue,
            ];
        }

        return [
            'mismatches_count' => count($diffs),
            'mismatches' => $diffs,
        ];
    }

    /**
     * @param mixed $rows
     */
    private function sumRowAmounts(mixed $rows): float
    {
        if (!is_array($rows)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sum += Support::toFloat($row['amount_open'] ?? $row['amount_net'] ?? 0);
        }

        return Support::money($sum);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function persistRecord(array $record): void
    {
        $telemetry = Telemetry::global();
        $telemetry?->recordMetric('shadow_validation_total', 1, 'counter', [
            'endpoint' => (string) ($record['endpoint_name'] ?? 'unknown'),
            'diff_type' => (string) ($record['diff_type'] ?? 'match'),
            'severity' => (string) ($record['severity'] ?? 'none'),
        ], 'shadow', 'shadow_validation');

        if ((string) ($record['diff_type'] ?? 'match') !== 'match') {
            $telemetry?->recordEvent(
                (string) (($record['severity'] ?? 'none') === 'critical' ? 'ERROR' : 'WARNING'),
                'shadow.diff',
                (string) ($record['summary'] ?? 'Diferenca detectada em shadow validation.'),
                [
                    'endpoint' => $record['endpoint_name'] ?? null,
                    'diff_type' => $record['diff_type'] ?? null,
                    'severity' => $record['severity'] ?? null,
                    'request_id' => $record['request_id'] ?? null,
                ],
                'shadow',
                'shadow_validation'
            );
        }

        if ($this->pdo instanceof PDO) {
            try {
                $this->ensureSchema();
                $stmt = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO biomie_shadow_validations (
                        endpoint_name, query_signature, query_context_json,
                        legacy_payload_hash, candidate_payload_hash,
                        diff_type, severity, summary, diff_json,
                        sample_rate, sample_bucket, request_id,
                        legacy_source, candidate_source, executed_at
                    ) VALUES (
                        :endpoint_name, :query_signature, :query_context_json,
                        :legacy_payload_hash, :candidate_payload_hash,
                        :diff_type, :severity, :summary, :diff_json,
                        :sample_rate, :sample_bucket, :request_id,
                        :legacy_source, :candidate_source, NOW()
                    )
                SQL);
                $stmt->execute([
                    'endpoint_name' => $record['endpoint_name'],
                    'query_signature' => $record['query_signature'],
                    'query_context_json' => $record['query_context_json'],
                    'legacy_payload_hash' => $record['legacy_payload_hash'],
                    'candidate_payload_hash' => $record['candidate_payload_hash'],
                    'diff_type' => $record['diff_type'],
                    'severity' => $record['severity'],
                    'summary' => $this->truncate((string) $record['summary'], 250),
                    'diff_json' => $record['diff_json'],
                    'sample_rate' => $record['sample_rate'],
                    'sample_bucket' => $record['sample_bucket'],
                    'request_id' => $record['request_id'],
                    'legacy_source' => $record['legacy_source'],
                    'candidate_source' => $record['candidate_source'],
                ]);
                return;
            } catch (Throwable $exception) {
                $this->logger->warning('Persistencia do shadow em banco falhou; usando log.', [
                    'message' => $exception->getMessage(),
                    'endpoint' => $record['endpoint_name'] ?? null,
                ]);
            }
        }

        $dir = dirname($this->fallbackLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $this->fallbackLogFile,
            $this->encodeJson($record) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function ensureSchema(): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_shadow_validations (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              endpoint_name VARCHAR(64) NOT NULL,
              query_signature VARCHAR(64) NOT NULL,
              query_context_json LONGTEXT NULL,
              legacy_payload_hash VARCHAR(64) NULL,
              candidate_payload_hash VARCHAR(64) NULL,
              diff_type VARCHAR(64) NOT NULL DEFAULT 'match',
              severity ENUM('none','low','medium','high','critical') NOT NULL DEFAULT 'none',
              summary VARCHAR(255) NOT NULL DEFAULT '',
              diff_json LONGTEXT NULL,
              sample_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
              sample_bucket TINYINT UNSIGNED NOT NULL DEFAULT 0,
              request_id VARCHAR(64) NULL,
              legacy_source VARCHAR(64) NULL,
              candidate_source VARCHAR(64) NULL,
              executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_shadow_endpoint_executed (endpoint_name, executed_at),
              KEY idx_shadow_signature (query_signature),
              KEY idx_shadow_diff (diff_type, severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', $this->encodeJson($this->normalizeForHash($payload)));
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    private function maxSeverity(string $left, string $right): string
    {
        $priority = [
            'none' => 0,
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
        ];

        return ($priority[$right] ?? 0) > ($priority[$left] ?? 0) ? $right : $left;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, max(0, $maxLength - 3)) . '...';
    }

    private static function registerShutdownRunner(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            if (self::$deferredTasks === []) {
                return;
            }

            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            foreach (self::$deferredTasks as $task) {
                try {
                    $task();
                } catch (Throwable) {
                    // Falha isolada: nunca afetar a resposta oficial.
                }
            }

            self::$deferredTasks = [];
        });
    }

    private static function connectDefaultPdo(Config $config, Logger $logger): ?PDO
    {
        try {
            $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            if (!Database::isConfigured($config, $connection)) {
                return null;
            }

            return Database::connect($config, $connection);
        } catch (Throwable $exception) {
            $logger->warning('Shadow validation sem banco configurado; usando fallback em log.', [
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }
}
