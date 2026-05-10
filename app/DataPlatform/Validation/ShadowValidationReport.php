<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

final class ShadowValidationReport
{
    /**
     * @param list<ShadowValidationResult> $results
     * @param list<ShadowValidationIssue> $issues
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $period,
        public readonly int $domainsChecked,
        public readonly int $totalChecks,
        public readonly int $passed,
        public readonly int $failed,
        public readonly int $warnings,
        public readonly array $issues = [],
        public readonly array $results = [],
        public readonly int $durationMs = 0,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'period' => $this->period,
            'domains_checked' => $this->domainsChecked,
            'total_checks' => $this->totalChecks,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'warnings' => $this->warnings,
            'issues' => array_map(static fn (ShadowValidationIssue $issue): array => $issue->toArray(), $this->issues),
            'results' => array_map(static fn (ShadowValidationResult $result): array => $result->toArray(), $this->results),
            'duration_ms' => $this->durationMs,
        ];
    }
}
