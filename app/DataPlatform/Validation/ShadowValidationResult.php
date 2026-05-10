<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

final class ShadowValidationResult
{
    /**
     * @param list<ShadowValidationIssue> $issues
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $domain,
        public readonly string $metric,
        public readonly mixed $legacyValue,
        public readonly mixed $canonicalValue,
        public readonly mixed $delta = null,
        public readonly mixed $deltaPercent = null,
        public readonly string $severity = 'none',
        public readonly string $message = '',
        public readonly string $sourceOfTruth = '',
        public readonly string $periodStart = '',
        public readonly string $periodEnd = '',
        public readonly string $checkedAt = '',
        public readonly array $issues = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'domain' => $this->domain,
            'metric' => $this->metric,
            'legacy_value' => $this->legacyValue,
            'canonical_value' => $this->canonicalValue,
            'delta' => $this->delta,
            'delta_percent' => $this->deltaPercent,
            'severity' => $this->severity,
            'message' => $this->message,
            'source_of_truth' => $this->sourceOfTruth,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'checked_at' => $this->checkedAt,
            'issues' => array_map(static fn (ShadowValidationIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
