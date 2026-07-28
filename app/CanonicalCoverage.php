<?php

declare(strict_types=1);

namespace BIomie;

final class CanonicalCoverage
{
    /**
     * @param array<string,mixed> $coverage
     */
    public static function canServe(array $coverage): bool
    {
        return self::hasRows($coverage) || self::hasSync($coverage);
    }

    /**
     * @param array<string,mixed> $coverage
     */
    public static function signalStatus(array $coverage): string
    {
        $hasRows = self::hasRows($coverage);
        $hasSync = self::hasSync($coverage);

        if (!$hasRows && !$hasSync) {
            return 'sync_missing';
        }

        if (!$hasRows && $hasSync) {
            return 'empty_after_sync';
        }

        $requestedEnd = trim((string) ($coverage['requested_end'] ?? ''));
        $actualEnd = trim((string) ($coverage['actual_end'] ?? $coverage['invoice_max_date'] ?? $coverage['pending_max_date'] ?? ''));
        if ($requestedEnd !== '' && $actualEnd !== '' && strcmp($actualEnd, $requestedEnd) < 0) {
            return 'partial_window';
        }

        return 'rows_present';
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $coverage
     * @return array<string,mixed>
     */
    public static function decorateMeta(array $meta, array $coverage, ?string $fallbackReason = null): array
    {
        $meta['coverage_requested'] = [
            'start' => $coverage['requested_start'] ?? null,
            'end' => $coverage['requested_end'] ?? null,
        ];
        $meta['coverage_actual'] = self::actualCoverage($coverage);
        $meta['signal_status'] = self::signalStatus($coverage);
        $meta['fallback_reason'] = $fallbackReason;
        $meta['source_of_truth'] = self::resolveSourceOfTruth($fallbackReason, $coverage);

        return $meta;
    }

    /**
     * Determina source_of_truth com base na razao de fallback e disponibilidade de dados canonicos.
     * Valores possiveis: canonical | legacy | snapshot | unknown
     *
     * @param array<string,mixed> $coverage
     */
    public static function resolveSourceOfTruth(?string $fallbackReason, array $coverage): string
    {
        if ($fallbackReason !== null && $fallbackReason !== '') {
            return 'legacy';
        }

        if (self::hasRows($coverage) || self::hasSync($coverage)) {
            return 'canonical';
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed> $coverage
     * @return array<string,mixed>
     */
    public static function actualCoverage(array $coverage): array
    {
        $actual = [
            'start' => $coverage['actual_start'] ?? $coverage['invoice_min_date'] ?? $coverage['pending_min_date'] ?? null,
            'end' => $coverage['actual_end'] ?? $coverage['invoice_max_date'] ?? $coverage['pending_max_date'] ?? null,
            'has_any_rows' => self::hasRows($coverage),
            'last_sync_at' => $coverage['last_sync_at'] ?? null,
        ];

        foreach ([
            'invoice_min_date',
            'invoice_max_date',
            'invoice_days_with_data',
            'invoice_notes_count',
            'authorized_min_date',
            'authorized_max_date',
            'authorized_days_with_data',
            'authorized_notes_count',
            'cancelled_min_date',
            'cancelled_max_date',
            'cancelled_days_with_data',
            'cancelled_notes_count',
            'pending_min_date',
            'pending_max_date',
            'pending_days_with_data',
            'pending_orders_count',
            'quote_min_date',
            'quote_max_date',
            'quote_days_with_data',
            'quote_orders_count',
            'authorized_orders_count',
        ] as $key) {
            if (array_key_exists($key, $coverage)) {
                $actual[$key] = $coverage[$key];
            }
        }

        if (is_array($coverage['invoice'] ?? null)) {
            $actual['invoice'] = self::actualCoverage($coverage['invoice']);
        }
        if (is_array($coverage['pending'] ?? null)) {
            $actual['pending'] = self::actualCoverage($coverage['pending']);
        }

        return $actual;
    }

    /**
     * @param array<string,mixed> $coverage
     */
    private static function hasRows(array $coverage): bool
    {
        return (bool) ($coverage['has_any_rows'] ?? false);
    }

    /**
     * @param array<string,mixed> $coverage
     */
    private static function hasSync(array $coverage): bool
    {
        return trim((string) ($coverage['last_sync_at'] ?? '')) !== '';
    }
}
