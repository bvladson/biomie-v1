<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

use BIomie\RequestContext;

final class ShadowValidationCoordinator
{
    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(
        private readonly array $settings,
        private readonly ShadowValidationRunner $runner,
        private readonly ShadowValidationFormatter $formatter,
    ) {
    }

    /**
     * @param callable():mixed $runLegacy
     * @param callable():mixed $runCandidate
     * @param array<string,mixed> $queryContext
     * @return null|callable():void
     */
    public function deferTask(string $endpoint, callable $runLegacy, callable $runCandidate, array $queryContext = []): ?callable
    {
        if (!$this->isEnabledForEndpoint($endpoint)) {
            return null;
        }

        $sampling = $this->samplingDecision($endpoint, $queryContext);
        if (!$sampling['selected']) {
            return null;
        }

        return function () use ($endpoint, $runLegacy, $runCandidate, $queryContext, $sampling): void {
            $this->runner->run(
                $endpoint,
                $runLegacy,
                $runCandidate,
                $queryContext,
                $sampling,
                $this->shouldPersistMatches()
            );
        };
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
        $queryContextJson = $this->formatter->encodeJson($this->formatter->normalizeForHash($queryContext));
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
}
