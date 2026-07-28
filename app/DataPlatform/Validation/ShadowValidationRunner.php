<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

use BIomie\Logger;
use BIomie\RequestContext;
use Throwable;

final class ShadowValidationRunner
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ShadowComparisonEngine $comparisonEngine,
        private readonly ShadowValidationRepository $repository,
        private readonly ShadowValidationFormatter $formatter,
    ) {
    }

    /**
     * @param callable():mixed $runLegacy
     * @param callable():mixed $runCandidate
     * @param array<string,mixed> $queryContext
     * @param array{selected:bool,sample_rate:float,sample_bucket:int,query_signature:string,query_context_json:string} $sampling
     */
    public function run(
        string $endpoint,
        callable $runLegacy,
        callable $runCandidate,
        array $queryContext,
        array $sampling,
        bool $persistMatches,
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
            'diff_json' => $this->formatter->encodeJson([
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
                $this->repository->persistRecord($record);
                return;
            }

            $record['legacy_payload_hash'] = $this->formatter->payloadHash($legacyPayload);
            $record['legacy_source'] = trim((string) ($legacyPayload['source'] ?? $legacyPayload['mode'] ?? 'legacy'));

            if (!is_array($candidatePayload) || $candidatePayload === []) {
                $record['diff_type'] = 'candidate_unavailable';
                $record['severity'] = 'low';
                $record['summary'] = 'Leitura candidata indisponivel para shadow.';
                $this->repository->persistRecord($record);
                return;
            }

            $record['candidate_payload_hash'] = $this->formatter->payloadHash($candidatePayload);
            $record['candidate_source'] = trim((string) ($candidatePayload['source'] ?? $candidatePayload['mode'] ?? 'candidate'));

            $comparison = $this->comparisonEngine->comparePayloads($endpoint, $legacyPayload, $candidatePayload);
            $record['diff_type'] = $comparison['diff_type'];
            $record['severity'] = $comparison['severity'];
            $record['summary'] = $comparison['summary'];
            $record['diff_json'] = $this->formatter->encodeJson([
                'request_id' => RequestContext::id(),
                'query_context' => $queryContext,
                'details' => $comparison['details'],
            ]);

            if ($comparison['diff_type'] === 'match' && !$persistMatches) {
                return;
            }

            $this->repository->persistRecord($record);
        } catch (Throwable $exception) {
            $record['diff_type'] = 'candidate_error';
            $record['severity'] = 'high';
            $record['summary'] = 'Shadow falhou: ' . $this->formatter->truncate($exception->getMessage(), 180);
            $record['diff_json'] = $this->formatter->encodeJson([
                'request_id' => RequestContext::id(),
                'query_context' => $queryContext,
                'error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ]);
            $this->repository->persistRecord($record);
            $this->logger->warning('Shadow validation falhou.', [
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
    }
}
