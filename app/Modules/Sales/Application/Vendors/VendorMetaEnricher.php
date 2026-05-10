<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\CanonicalCoverage;

final class VendorMetaEnricher
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
}
