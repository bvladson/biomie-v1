<?php

declare(strict_types=1);

namespace BIomie;

/**
 * Reads sales snapshots from local cache without queueing or calling Omie.
 */
final class SalesSnapshotCacheReader
{
    /**
     * @return array<string,mixed>|null
     */
    public static function read(Cache $cache, Config $config, string $start, string $end): ?array
    {
        $key = SalesAnalyticsService::cacheKey($start, $end);
        $ttlMinutes = (int) $config->get('analytics.cache_ttl_minutes', 60);
        $fresh = $cache->get($key, max($ttlMinutes, 1) * 60);
        if (is_array($fresh)) {
            return self::decorate($cache, $key, $fresh, 'cache-fresh-local-only');
        }

        $maxStalenessSeconds = max($ttlMinutes * 24, 720) * 60;
        $stale = $cache->getStale($key, $maxStalenessSeconds);
        if (!is_array($stale)) {
            return null;
        }

        return self::decorate($cache, $key, $stale, 'cache-stale-local-only');
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function decorate(Cache $cache, string $key, array $snapshot, string $servedFrom): array
    {
        $snapshot['meta'] = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
        $snapshot['meta']['cache'] = $cache->getMeta($key);
        $snapshot['meta']['served_from'] = $servedFrom;
        return $snapshot;
    }
}
