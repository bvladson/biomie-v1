<?php

declare(strict_types=1);

namespace BIomie\Modules\Finance\Application;

use Closure;
use Throwable;

/**
 * Selects the finance source for the commercial dashboard without allowing a
 * routine page view to call the upstream ERP.
 */
final class CommercialHealthSourceResolver
{
    /** @var list<string> */
    private const NON_AUTHORITATIVE_CANONICAL_REASONS = [
        'finance_table_missing',
        'finance_table_empty',
        'query_error',
    ];

    /**
     * An empty selected period is authoritative when the canonical table is
     * healthy. Missing/empty tables and query failures are not.
     *
     * @param array<string,mixed> $financeSummary
     */
    public static function canonicalIsAuthoritative(array $financeSummary): bool
    {
        $reason = trim((string) ($financeSummary['reason_code'] ?? ''));

        return !in_array($reason, self::NON_AUTHORITATIVE_CANONICAL_REASONS, true);
    }

    /**
     * @param array<string,mixed>|null $canonicalPayload
     * @param array<string,mixed>|null $cachedPayload
     * @param array<string,mixed>      $unavailablePayload
     * @param Closure():array<string,mixed>|null $liveLoader
     *
     * @return array<string,mixed>
     */
    public function resolve(
        bool $force,
        ?array $canonicalPayload,
        ?array $cachedPayload,
        array $unavailablePayload,
        ?Closure $liveLoader = null,
    ): array {
        if (!$force) {
            if ($canonicalPayload !== null) {
                return $this->decorate($canonicalPayload, 'canonical', false);
            }
            if ($cachedPayload !== null) {
                return $this->decorate($cachedPayload, 'cache-fallback', false, 'canonical_unavailable');
            }

            return $this->decorate($unavailablePayload, 'unavailable', false, 'canonical_and_cache_unavailable');
        }

        if ($liveLoader !== null) {
            try {
                $livePayload = $liveLoader();

                return $this->decorate($livePayload, 'live-force', true);
            } catch (Throwable $exception) {
                $fallback = $canonicalPayload ?? $cachedPayload ?? $unavailablePayload;
                $selected = $canonicalPayload !== null
                    ? 'canonical-fallback'
                    : ($cachedPayload !== null ? 'cache-fallback' : 'unavailable');

                return $this->decorate($fallback, $selected, true, self::liveFailureCode($exception));
            }
        }

        $fallback = $canonicalPayload ?? $cachedPayload ?? $unavailablePayload;
        $selected = $canonicalPayload !== null
            ? 'canonical'
            : ($cachedPayload !== null ? 'cache-fallback' : 'unavailable');

        return $this->decorate($fallback, $selected, false, 'live_unconfigured');
    }

    public static function liveFailureCode(Throwable $exception): string
    {
        $message = strtoupper($exception->getMessage());
        if (str_contains($message, 'REDUNDANT') || str_contains($message, 'CONSUMO REDUNDANTE')) {
            return 'omie_redundant';
        }
        if (str_contains($message, 'TIMEOUT') || str_contains($message, 'TIMED OUT')) {
            return 'upstream_timeout';
        }

        return 'live_refresh_failed';
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function decorate(
        array $payload,
        string $selectedSource,
        bool $liveAttempted,
        ?string $fallbackReason = null,
    ): array {
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['source_strategy'] = 'canonical-first';
        $payload['meta']['selected_source'] = $selectedSource;
        $payload['meta']['live_attempted'] = $liveAttempted;
        if ($fallbackReason !== null) {
            $payload['meta']['fallback_reason'] = $fallbackReason;
        } else {
            unset($payload['meta']['fallback_reason']);
        }

        return $payload;
    }
}
