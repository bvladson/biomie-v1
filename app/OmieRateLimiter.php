<?php

declare(strict_types=1);

namespace BIomie;

use RuntimeException;

/**
 * File-backed, cross-process limiter for Omie calls.
 *
 * It keeps the actual API consumption below Omie's per-method budget without
 * relying on a single PHP process. Web contexts never sleep here; long waits
 * must happen in cron/worker jobs.
 */
final class OmieRateLimiter
{
    private string $stateDir;
    private string $appKeyHash;

    public function __construct(
        Cache $cache,
        private readonly Logger $logger,
        string $appKey,
        private readonly string $executionContext,
    ) {
        $this->stateDir = rtrim($cache->directory(), '/\\') . '/omie-rate-limit';
        $this->appKeyHash = substr(hash('sha256', $appKey), 0, 16);
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public function throttle(string $call, array $policy, string $operationType = 'request'): array
    {
        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        $maxSleepMs = max(0, (int) ($runtime['max_sleep_ms'] ?? 0));
        if ($maxSleepMs <= 0) {
            return [
                'enforced' => false,
                'reason' => 'no_sleep_context',
                'context' => $this->executionContext,
                'call' => $call,
                'operation_type' => $operationType,
            ];
        }

        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0775, true);
        }

        $perMinute = max(1, (int) ($policy['rate_limit_per_minute'] ?? 240));
        $minIntervalMs = max(
            (int) ceil(60000 / $perMinute),
            (int) ($policy['rate_limit_min_interval_ms'] ?? 250)
        );
        $maxConcurrency = max(1, min(4, (int) ($policy['max_concurrency'] ?? 1)));
        $stateKey = $this->stateKey($call);
        $statePath = $this->pathFor($stateKey, 'json');
        $lockPath = $this->pathFor($stateKey, 'lock');
        $sleptMs = 0;

        while (true) {
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) {
                return [
                    'enforced' => false,
                    'reason' => 'lock_unavailable',
                    'call' => $call,
                    'operation_type' => $operationType,
                ];
            }

            flock($lock, LOCK_EX);

            try {
                $nowMs = (int) floor(microtime(true) * 1000);
                $state = $this->readState($statePath);
                $requests = array_values(array_filter(
                    array_map('intval', (array) ($state['requests_ms'] ?? [])),
                    static fn (int $timestamp): bool => ($nowMs - $timestamp) < 60000
                ));
                $lastRequestMs = (int) ($state['last_request_ms'] ?? 0);
                $waitMs = 0;

                if ($requests !== [] && count($requests) >= $perMinute) {
                    $oldest = min($requests);
                    $waitMs = max($waitMs, 60000 - ($nowMs - $oldest) + 50);
                }

                if ($lastRequestMs > 0 && ($nowMs - $lastRequestMs) < $minIntervalMs) {
                    $waitMs = max($waitMs, $minIntervalMs - ($nowMs - $lastRequestMs));
                }

                if ($waitMs <= 0) {
                    $requests[] = $nowMs;
                    $state = [
                        'app_key_hash' => $this->appKeyHash,
                        'call' => $call,
                        'context' => $this->executionContext,
                        'operation_type' => $operationType,
                        'rate_limit_per_minute' => $perMinute,
                        'max_concurrency' => $maxConcurrency,
                        'min_interval_ms' => $minIntervalMs,
                        'last_request_ms' => $nowMs,
                        'last_request_at' => date(DATE_ATOM),
                        'requests_ms' => $requests,
                        'request_count_window' => count($requests),
                    ];
                    $this->writeState($statePath, $state);

                    return [
                        'enforced' => true,
                        'slept_ms' => $sleptMs,
                        'call' => $call,
                        'operation_type' => $operationType,
                        'context' => $this->executionContext,
                        'rate_limit_per_minute' => $perMinute,
                        'max_concurrency' => $maxConcurrency,
                        'request_count_window' => count($requests),
                    ];
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            $remainingMs = $maxSleepMs - $sleptMs;
            if ($remainingMs <= 0) {
                $message = 'Rate limit local da Omie ativo; reagende a operacao em background.';
                $this->logger->warning($message, [
                    'call' => $call,
                    'context' => $this->executionContext,
                    'operation_type' => $operationType,
                    'wait_ms' => $waitMs,
                    'slept_ms' => $sleptMs,
                ]);
                throw new RuntimeException($message);
            }

            $sleepMs = min($waitMs, $remainingMs);
            usleep($sleepMs * 1000);
            $sleptMs += $sleepMs;
        }
    }

    /**
     * @param array<string,array<string,mixed>> $policies
     * @return list<array<string,mixed>>
     */
    public static function summarizePolicies(array $policies): array
    {
        $summary = [];
        foreach ($policies as $call => $policy) {
            $perMinute = max(1, (int) ($policy['rate_limit_per_minute'] ?? 240));
            $summary[] = [
                'call' => (string) $call,
                'domain' => (string) ($policy['domain'] ?? 'generic'),
                'page_size_target' => max(1, min(100, (int) ($policy['page_size_target'] ?? 100))),
                'rate_limit_per_minute' => $perMinute,
                'min_interval_ms' => max((int) ceil(60000 / $perMinute), (int) ($policy['rate_limit_min_interval_ms'] ?? 250)),
                'max_concurrency' => max(1, min(4, (int) ($policy['max_concurrency'] ?? 1))),
                'cache_ttl_seconds' => max(0, (int) ($policy['redundant_cache_ttl_seconds'] ?? 0)),
                'cache_id_keys' => array_values(array_map('strval', (array) ($policy['redundant_id_keys'] ?? []))),
                'window_strategy' => (string) (($policy['window']['strategy'] ?? '') ?: 'on_demand'),
            ];
        }

        usort($summary, static fn (array $a, array $b): int => [$a['domain'], $a['call']] <=> [$b['domain'], $b['call']]);
        return $summary;
    }

    private function stateKey(string $call): string
    {
        return 'omie_rl_' . $this->appKeyHash . '_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $call);
    }

    private function pathFor(string $key, string $extension): string
    {
        return $this->stateDir . '/' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key) . '.' . $extension;
    }

    /**
     * @return array<string,mixed>
     */
    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($path, $json, LOCK_EX);
    }
}
