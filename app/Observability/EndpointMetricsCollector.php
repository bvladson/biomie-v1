<?php

declare(strict_types=1);

namespace BIomie\Observability;

use Throwable;

final class EndpointMetricsCollector
{
    private const MAX_READ_LINES = 50_000;
    private const READ_CHUNK_BYTES = 65_536;
    private const MAX_LOG_LINE_BYTES = 1_048_576;

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly string $logPath,
        private readonly ?string $browserLogPath = null,
    ) {}

    public static function registerShutdownHook(string $logPath): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        $startTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $endpoint = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown.php'));
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        register_shutdown_function(static function () use ($logPath, $startTime, $endpoint, $method): void {
            try {
                $context = [
                    'request_id' => \BIomie\RequestContext::id(),
                    'memory_peak_bytes' => memory_get_peak_usage(true),
                ];
                try {
                    $context['company_id'] = \BIomie\Support\TenantContext::companyIdOrNull();
                    $context['context_type'] = \BIomie\Support\TenantContext::contextType();
                    $context['business_scope'] = \BIomie\Support\TenantContext::canal();
                    $context['seller_scope_hash'] = \BIomie\Support\TenantContext::sellerScopeHash();
                } catch (Throwable) {
                    // Public/non-tenant endpoints legitimately have no context.
                }
                try {
                    $user = \BIomie\Auth::currentUser();
                    $context['user_role'] = is_array($user) ? (string) ($user['role'] ?? '') : null;
                } catch (Throwable) {
                    $context['user_role'] = null;
                }
                $line = json_encode([
                    'ep' => $endpoint,
                    'm' => $method,
                    'sc' => (int) (http_response_code() ?: 200),
                    'ms' => (int) round((microtime(true) - $startTime) * 1000),
                    'rt' => RequestPerformanceContext::snapshot(),
                    'ctx' => $context,
                    'src' => 'server',
                    'ts' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
            } catch (Throwable) {
            }
        });
    }

    /**
     * Server samples are authoritative. Browser samples are used only for
     * endpoint/method pairs without a server sample in the requested window.
     *
     * @return list<array<string,mixed>>
     */
    public function summarize(int $hours = 24): array
    {
        $browserPath = $this->browserLogPath ?? dirname($this->logPath) . '/endpoint_browser.jsonl';
        if (!is_file($this->logPath) && !is_file($browserPath)) {
            return [];
        }

        $cutoff = time() - ($hours * 3600);
        $serverRows = $this->readRows($this->logPath, $cutoff);
        $browserRows = $this->readRows($browserPath, $cutoff);
        $serverKeys = [];
        foreach ($serverRows as $row) {
            $serverKeys[$this->rowKey($row)] = true;
        }

        /** @var array<string,array<string,mixed>> $grouped */
        $grouped = [];
        foreach ([
            ['source' => 'server', 'rows' => $serverRows],
            ['source' => 'browser', 'rows' => $browserRows],
        ] as $input) {
            foreach ($input['rows'] as $row) {
                $key = $this->rowKey($row);
                if ($input['source'] === 'browser' && isset($serverKeys[$key])) {
                    continue;
                }

                $ep = (string) ($row['ep'] ?? 'unknown');
                $method = strtoupper((string) ($row['m'] ?? 'GET'));
                $status = (int) ($row['sc'] ?? 200);
                $duration = max(0, (int) ($row['ms'] ?? 0));
                $grouped[$key]['endpoint'] = $ep;
                $grouped[$key]['method'] = $method;
                $grouped[$key]['sample_source'] = $input['source'];
                $grouped[$key]['durations'][] = $duration;
                $grouped[$key]['total'] = (int) ($grouped[$key]['total'] ?? 0) + 1;
                $grouped[$key]['errors'] = (int) ($grouped[$key]['errors'] ?? 0) + ($status >= 500 ? 1 : 0);

                $runtime = is_array($row['rt'] ?? null) ? $row['rt'] : [];
                $counters = is_array($runtime['counters'] ?? null) ? $runtime['counters'] : [];
                $tags = is_array($runtime['tags'] ?? null) ? $runtime['tags'] : [];
                if ($runtime !== [] || $input['source'] === 'browser') {
                    $grouped[$key]['instrumented_total'] = (int) ($grouped[$key]['instrumented_total'] ?? 0) + 1;
                }
                foreach (['external_calls', 'external_get_violations', 'omie_network_calls', 'db_connect_calls', 'db_pool_hits', 'db_connections_opened'] as $counter) {
                    $grouped[$key][$counter] = (int) ($grouped[$key][$counter] ?? 0) + (int) ($counters[$counter] ?? 0);
                }
                $source = trim((string) ($tags['source'] ?? ''));
                if ($source !== '') {
                    $grouped[$key]['source_counts'][$source] = (int) (($grouped[$key]['source_counts'][$source] ?? 0) + 1);
                }
            }
        }

        $result = [];
        foreach ($grouped as $data) {
            $durations = $data['durations'];
            sort($durations, SORT_NUMERIC);
            $count = count($durations);
            $total = (int) ($data['total'] ?? 0);
            $errors = (int) ($data['errors'] ?? 0);
            $result[] = [
                'endpoint' => $data['endpoint'],
                'method' => $data['method'],
                'sample_source' => (string) ($data['sample_source'] ?? 'server'),
                'total' => $total,
                'errors' => $errors,
                'error_rate' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
                'avg_ms' => $count > 0 ? (int) round(array_sum($durations) / $count) : 0,
                'p50_ms' => $count > 0 ? $durations[(int) floor($count * 0.50)] : 0,
                'p95_ms' => $count > 0 ? $durations[(int) min(floor($count * 0.95), $count - 1)] : 0,
                'max_ms' => $count > 0 ? max($durations) : 0,
                'instrumented_total' => (int) ($data['instrumented_total'] ?? 0),
                'external_calls' => (int) ($data['external_calls'] ?? 0),
                'external_get_violations' => (int) ($data['external_get_violations'] ?? 0),
                'omie_network_calls' => (int) ($data['omie_network_calls'] ?? 0),
                'db_connect_calls' => (int) ($data['db_connect_calls'] ?? 0),
                'db_pool_hits' => (int) ($data['db_pool_hits'] ?? 0),
                'db_connections_opened' => (int) ($data['db_connections_opened'] ?? 0),
                'source_counts' => (array) ($data['source_counts'] ?? []),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    public function appendBrowserRecords(array $records): int
    {
        $path = $this->browserLogPath ?? dirname($this->logPath) . '/endpoint_browser.jsonl';
        $lines = [];
        foreach (array_slice($records, -50) as $record) {
            $endpoint = basename((string) ($record['endpoint'] ?? $record['name'] ?? ''));
            $method = strtoupper((string) ($record['method'] ?? 'GET'));
            $duration = max(0, min(300_000, (int) round((float) ($record['duration_ms'] ?? 0))));
            $status = max(0, min(599, (int) ($record['status'] ?? 0)));
            if ($endpoint === '' || !str_ends_with($endpoint, '.php') || $duration <= 0) {
                continue;
            }
            $lines[] = json_encode([
                'ep' => $endpoint,
                'm' => $method,
                'sc' => $status,
                'ms' => $duration,
                'src' => 'browser',
                'ts' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($lines === []) {
            return 0;
        }
        @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
        $this->rotatePath($path);
        return count($lines);
    }

    public function rotate(int $maxBytes = 10_485_760): void
    {
        $this->rotatePath($this->logPath, $maxBytes);
        $this->rotatePath($this->browserLogPath ?? dirname($this->logPath) . '/endpoint_browser.jsonl', $maxBytes);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRows(string $path, int $cutoff): array
    {
        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $linesSeen = 0;
        $buffer = '';
        $oversizedLine = false;
        $limitReached = false;

        /**
         * Lines are counted before JSON parsing to retain the previous
         * array_slice(..., -50_000) semantics, including malformed records.
         */
        $consumeLine = static function (?string $line, bool $oversized = false) use (
            &$rows,
            &$linesSeen,
            $cutoff,
        ): bool {
            if (!$oversized) {
                $line = rtrim((string) $line, "\r");
                if ($line === '') {
                    return false;
                }
            }

            $linesSeen++;
            if (!$oversized) {
                $row = json_decode((string) $line, true);
                if (is_array($row)) {
                    $timestamp = isset($row['ts']) ? (int) strtotime((string) $row['ts']) : 0;
                    if ($timestamp >= $cutoff) {
                        $rows[] = $row;
                    }
                }
            }

            return $linesSeen >= self::MAX_READ_LINES;
        };

        try {
            if (@fseek($handle, 0, SEEK_END) !== 0) {
                return [];
            }
            $position = @ftell($handle);
            if (!is_int($position) || $position <= 0) {
                return [];
            }

            while ($position > 0 && !$limitReached) {
                $chunkStart = max(0, $position - self::READ_CHUNK_BYTES);
                $chunkLength = $position - $chunkStart;
                if (@fseek($handle, $chunkStart, SEEK_SET) !== 0) {
                    break;
                }
                $chunk = @fread($handle, $chunkLength);
                if (!is_string($chunk) || strlen($chunk) !== $chunkLength) {
                    break;
                }
                $position = $chunkStart;

                // A malformed record without a newline must not allow the
                // carry buffer to grow with the size of the whole file.
                if ($oversizedLine) {
                    $lineBoundary = strrpos($chunk, "\n");
                    if ($lineBoundary === false) {
                        continue;
                    }
                    $limitReached = $consumeLine(null, true);
                    $oversizedLine = false;
                    $buffer = '';
                    if ($limitReached) {
                        break;
                    }
                    $chunk = substr($chunk, 0, $lineBoundary + 1);
                }

                $data = $chunk . $buffer;
                $parts = explode("\n", $data);
                if (count($parts) === 1) {
                    if (strlen($data) > self::MAX_LOG_LINE_BYTES) {
                        $buffer = '';
                        $oversizedLine = true;
                    } else {
                        $buffer = $data;
                    }
                    continue;
                }

                $buffer = (string) array_shift($parts);
                for ($index = count($parts) - 1; $index >= 0; $index--) {
                    $line = (string) $parts[$index];
                    $limitReached = $consumeLine(
                        $line,
                        strlen($line) > self::MAX_LOG_LINE_BYTES,
                    );
                    if ($limitReached) {
                        break;
                    }
                }
            }

            if (!$limitReached) {
                if ($oversizedLine) {
                    $consumeLine(null, true);
                } elseif ($buffer !== '') {
                    $consumeLine($buffer);
                }
            }

            // Reverse scanning collects newest rows first. Restore the same
            // oldest-to-newest order returned by file() + array_slice().
            return array_reverse($rows);
        } catch (Throwable) {
            return [];
        } finally {
            @fclose($handle);
        }
    }

    /** @param array<string,mixed> $row */
    private function rowKey(array $row): string
    {
        return (string) ($row['ep'] ?? 'unknown') . ':' . strtoupper((string) ($row['m'] ?? 'GET'));
    }

    private function rotatePath(string $path, int $maxBytes = 10_485_760): void
    {
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if ($size === false || $size < $maxBytes) {
            return;
        }
        try {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $kept = array_slice($lines, -10_000);
            @file_put_contents($path, implode("\n", $kept) . "\n", LOCK_EX);
        } catch (Throwable) {
        }
    }
}
