<?php

declare(strict_types=1);

namespace BIomie;

final class SyncCursorState
{
    /**
     * @param array<string,mixed>|null $current
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function merge(?array $current, string $domain, string $endpointGroup, array $values = []): array
    {
        $current ??= [];

        return [
            'domain_name' => $domain,
            'endpoint_group' => $endpointGroup,
            'last_event_at' => self::resolveCursorDate($current['last_event_at'] ?? null, $values, 'last_event_at', 'max'),
            'last_source_id' => self::resolveCursorSourceId($current['last_source_id'] ?? null, $values),
            'repair_from' => self::resolveCursorDate($current['repair_from'] ?? null, $values, 'repair_from', 'min'),
            'repair_to' => self::resolveCursorDate($current['repair_to'] ?? null, $values, 'repair_to', 'max'),
            'next_delta_at' => self::resolvePlainValue($current['next_delta_at'] ?? null, $values, 'next_delta_at'),
            'safety_window_minutes' => (int) ($values['safety_window_minutes'] ?? $current['safety_window_minutes'] ?? 10),
            'last_success_at' => self::resolveCursorDate($current['last_success_at'] ?? null, $values, 'last_success_at', 'max'),
            'last_run_started_at' => self::resolveCursorDate($current['last_run_started_at'] ?? null, $values, 'last_run_started_at', 'max'),
            'last_run_finished_at' => self::resolveCursorDate($current['last_run_finished_at'] ?? null, $values, 'last_run_finished_at', 'max'),
            'rule_version' => (string) ($values['rule_version'] ?? $current['rule_version'] ?? 'phase4-v1'),
            'status' => (string) ($values['status'] ?? $current['status'] ?? 'idle'),
            'last_error' => self::resolvePlainValue($current['last_error'] ?? null, $values, 'last_error'),
            'cursor_meta_json' => self::resolveJsonValue($current['cursor_meta_json'] ?? null, $values, 'cursor_meta'),
        ];
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function resolveCursorDate(?string $current, array $values, string $key, string $mode): ?string
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        $incoming = $values[$key];
        if ($incoming === null || $incoming === '') {
            return null;
        }

        $incomingValue = (string) $incoming;
        if ($current === null || $current === '') {
            return $incomingValue;
        }

        if ($mode === 'min') {
            return strcmp($incomingValue, $current) < 0 ? $incomingValue : $current;
        }

        return strcmp($incomingValue, $current) > 0 ? $incomingValue : $current;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function resolveCursorSourceId(?string $current, array $values): ?string
    {
        if (!array_key_exists('last_source_id', $values)) {
            return $current;
        }

        $incoming = $values['last_source_id'];
        if ($incoming === null || $incoming === '') {
            return null;
        }

        return (string) $incoming;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function resolvePlainValue(mixed $current, array $values, string $key): mixed
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        return $values[$key];
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function resolveJsonValue(?string $current, array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        $incoming = $values[$key];
        if ($incoming === null || $incoming === []) {
            return null;
        }

        if (is_string($incoming)) {
            return $incoming;
        }

        return OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($incoming));
    }
}
