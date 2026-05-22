<?php

declare(strict_types=1);

namespace BIomie;

final class Cache
{
    public function __construct(private readonly string $cacheDir)
    {
    }

    public function directory(): string
    {
        return $this->cacheDir;
    }

    private function filePath(string $key): string
    {
        return rtrim($this->cacheDir, '/\\') . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    }

    public function get(string $key, int $ttlSeconds): ?array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) {
            return null;
        }

        $modified = filemtime($path) ?: 0;
        if ((time() - $modified) > $ttlSeconds) {
            return null;
        }

        return $this->read($key);
    }

    public function put(string $key, array $value): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $path = $this->filePath($key);
        $temp = $path . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            return;
        }
        @rename($temp, $path);
    }

    public function getMeta(string $key): array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) {
            return ['exists' => false, 'modified_at' => null, 'age_seconds' => null];
        }

        $modified = filemtime($path) ?: null;
        return [
            'exists' => true,
            'modified_at' => $modified ? date(DATE_ATOM, $modified) : null,
            'age_seconds' => $modified ? time() - $modified : null,
        ];
    }

    public function read(string $key): ?array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) return null;
        $json = @file_get_contents($path);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function delete(string $key): void
    {
        $path = $this->filePath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function invalidatePrefix(string $prefix): void
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix);
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob(rtrim($this->cacheDir, '/\\') . '/' . $safePrefix . '*.json');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Fetches from cache or computes with stampede prevention via file lock.
     *
     * If the key is fresh (within TTL), returns cached data immediately.
     * If stale, acquires an exclusive lock, re-checks freshness (another worker may
     * have refreshed while we waited), then calls $compute() and stores the result.
     *
     * @param callable(): array $compute
     */
    public function getOrCompute(string $key, int $ttlSeconds, callable $compute): array
    {
        $fresh = $this->get($key, $ttlSeconds);
        if ($fresh !== null) {
            return $fresh;
        }

        // Acquire lock to prevent stampede
        $lockPath = rtrim($this->cacheDir, '/\\') . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.lock';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            // Cannot lock — compute without caching
            return $compute();
        }

        flock($fh, LOCK_EX);

        try {
            // Re-check: another worker may have filled the cache while we waited
            $refetch = $this->get($key, $ttlSeconds);
            if ($refetch !== null) {
                return $refetch;
            }

            $value = $compute();
            $this->put($key, $value);
            return $value;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Returns cached data regardless of TTL (stale-while-revalidate support).
     *
     * Returns the cached array if it exists and its age is within $maxStaleness seconds,
     * otherwise null. Use alongside getOrCompute() to serve stale data while refreshing.
     *
     * @param int $maxStaleness Maximum age in seconds to still consider "acceptable stale"
     */
    public function getStale(string $key, int $maxStaleness = 3600): ?array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) {
            return null;
        }

        $modified = filemtime($path) ?: 0;
        if ((time() - $modified) > $maxStaleness) {
            return null;
        }

        return $this->read($key);
    }

    /**
     * Returns an adaptive TTL based on how far in the past the period end is.
     *
     * Periods closed more than 2 days ago are fully historical and change only
     * when a sync runs — they get a long TTL. Current/recent periods get a short TTL.
     *
     * @param string $periodEnd    YYYY-MM-DD end of the requested period
     * @param int    $freshTtl     TTL in seconds for recent/current periods (default 60s)
     * @param int    $historicalTtl TTL in seconds for closed historical periods (default 3600s)
     */
    public static function adaptiveTtl(string $periodEnd, int $freshTtl = 60, int $historicalTtl = 3600): int
    {
        $endTs = strtotime($periodEnd . ' 23:59:59');
        if ($endTs === false) {
            return $freshTtl;
        }

        $ageSeconds = time() - $endTs;

        // Period ended more than 2 days ago → historical, long cache
        if ($ageSeconds > 172800) {
            return $historicalTtl;
        }

        return $freshTtl;
    }
}
