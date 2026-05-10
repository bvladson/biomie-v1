<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalCoverage;

final class OverviewMetaEnricher
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $coverage
     * @return array<string,mixed>
     */
    public function enrich(array $payload, array $coverage, ?string $fallbackReason): array
    {
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta'] = CanonicalCoverage::decorateMeta($payload['meta'], $coverage, $fallbackReason);
        $payload['meta']['sync_state'] = [
            'reconcile_status' => $payload['meta']['reconcile_status'] ?? null,
            'has_retry_or_delay' => $payload['meta']['has_retry_or_delay'] ?? null,
            'open_divergences' => $payload['meta']['open_divergences'] ?? null,
            'data_mode' => $payload['meta']['data_mode'] ?? null,
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $coverage
     */
    public function canonicalCoverageEnd(array $coverage): string
    {
        foreach (['coverage_end', 'actual_end', 'invoice_max_date', 'max_competencia'] as $key) {
            $value = trim((string) ($coverage[$key] ?? ''));
            if ($value !== '') {
                return substr($value, 0, 10);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed>|null $coverage
     */
    public function canonicalCoverageIsComplete(?array $coverage, string $start, string $end): bool
    {
        if ($coverage === null || $coverage === []) {
            return false;
        }

        $coverageEnd = $this->canonicalCoverageEnd($coverage);
        if ($coverageEnd === '') {
            return false;
        }

        $requestedEnd = substr($end, 0, 10);

        return strcmp($coverageEnd, $requestedEnd) >= 0;
    }
}
