<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Observability\RequestPerformanceContext;
use BIomie\Support\TenantContext;

final class Cache
{
    /** @var array<string,string> Prefix-generation fingerprint observed on read. */
    private array $observedGenerations = [];

    /**
     * @param bool $isolated When true the cache dir is already company-specific;
     *                       scopedKey() skips the "company_N_canal_X_" prefix.
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly bool   $isolated = false,
    ) {
    }

    /**
     * Creates a Cache rooted in storage/companies/{slug}/cache/.
     * The directory is created on demand. Returned instance is isolated —
     * no company/canal prefix is added to keys.
     */
    public static function forCompanySlug(string $slug): self
    {
        $dir = \BIomie\Company\CompanyStorage::ensure($slug, 'cache');
        return new self($dir, isolated: true);
    }

    public function directory(): string
    {
        return $this->cacheDir;
    }

    /**
     * R2.1 — Enforced Company Storage Isolation: com tenant bootado, o cache
     * vive em storage/companies/{slug}/cache/ (nunca arquivos de empresa no
     * cache global). Resolvido por acesso porque o bootstrap cria o Cache
     * ANTES do TenantContext::boot().
     */
    private function resolvedDir(): string
    {
        if ($this->isolated) {
            return $this->cacheDir;
        }

        if (!TenantContext::isBooted()) {
            throw new \BIomie\Support\TenantContextUnavailableException(
                'context_required_for_cache',
                403,
                'Cache operacional exige TenantContext explícito.',
            );
        }

        return \BIomie\Company\CompanyStorage::ensure(TenantContext::slug(), 'cache');
    }

    private function filePath(string $key): string
    {
        return rtrim($this->resolvedDir(), '/\\') . '/'
            . $this->safeScopedKey($key)
            . '.json';
    }

    private function safeScopedKey(string $key): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $this->scopedKey($key));
    }

    private function safeLogicalKey(string $key): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    }

    private function keyGenerationId(string $key, string $dir): string
    {
        return rtrim($dir, '/\\') . '|' . $this->safeScopedKey($key);
    }

    private function generationPath(string $dir): string
    {
        return rtrim($dir, '/\\') . '/.cache-generations.json';
    }

    private function invalidationLockPath(string $dir): string
    {
        return rtrim($dir, '/\\') . '/.cache-invalidation.lock';
    }

    /** @return array<string,int> */
    private function generationState(string $dir): array
    {
        $value = @file_get_contents($this->generationPath($dir));
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $state = [];
        foreach ($decoded as $prefix => $generation) {
            if (is_string($prefix) && $prefix !== '' && is_numeric($generation)) {
                $state[$prefix] = max(0, (int) $generation);
            }
        }
        return $state;
    }

    private function generationFingerprint(string $key, string $dir): string
    {
        $safeKey = $this->safeScopedKey($key);
        $matching = [];
        foreach ($this->generationState($dir) as $prefix => $generation) {
            $isExact = str_starts_with($prefix, '=');
            $matches = $isExact
                ? $safeKey === substr($prefix, 1)
                : str_starts_with($safeKey, $prefix);
            if ($matches) {
                $matching[$prefix] = $generation;
            }
        }
        if ($matching === []) {
            return '0';
        }
        ksort($matching, SORT_STRING);
        return hash('sha256', (string) json_encode($matching, JSON_UNESCAPED_SLASHES));
    }

    private function incrementGeneration(string $dir, string $safePrefix): bool
    {
        $state = $this->generationState($dir);
        $state[$safePrefix] = ($state[$safePrefix] ?? 0) + 1;
        ksort($state, SORT_STRING);
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        return is_string($json)
            && @file_put_contents($this->generationPath($dir), $json, LOCK_EX) !== false;
    }

    /** @return resource|false */
    private function acquireInvalidationGuard(string $dir, int $operation)
    {
        $handle = @fopen($this->invalidationLockPath($dir), 'c');
        if ($handle === false || !@flock($handle, $operation)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            return false;
        }
        return $handle;
    }

    /** @param resource|false $handle */
    private function releaseInvalidationGuard($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function scopedKey(string $key): string
    {
        // Isolated caches already live in a company-specific directory.
        if ($this->isolated) {
            return $key;
        }

        if (!TenantContext::isBooted()) {
            throw new \BIomie\Support\TenantContextUnavailableException(
                'context_required_for_cache',
                403,
                'Chave de cache operacional exige TenantContext explícito.',
            );
        }

        // O diretório resolvido já é da empresa; a chave só separa o canal.
        return sprintf('%s__ctx_%s', $key, $this->tenantScopeFingerprint());
    }

    /**
     * Partição estável da autorização efetiva, sem usar o ID efêmero do ctx.
     * Contextos equivalentes podem reutilizar cache; escopos diferentes nunca
     * compartilham a mesma chave.
     */
    private function tenantScopeFingerprint(): string
    {
        $allowedCompanyIds = TenantContext::allowedCompanyIds();
        sort($allowedCompanyIds, SORT_NUMERIC);
        $identity = [
            'type' => TenantContext::contextType(),
            'companies' => $allowedCompanyIds,
            'allowed_business_scopes' => TenantContext::allowedBusinessScopes(),
            'business_scope' => TenantContext::businessScope(),
            'user_id' => TenantContext::userId(),
            'role' => TenantContext::role(),
            'seller_scope_hash' => TenantContext::sellerScopeHash(),
            'permissions_version' => TenantContext::permissionsVersion(),
        ];
        $encoded = json_encode($identity, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Não foi possível particionar o cache do contexto.');
        }

        return TenantContext::contextType()
            . '_'
            . substr(hash('sha256', $encoded), 0, 24);
    }

    public function get(string $key, int $ttlSeconds): ?array
    {
        $dir = $this->resolvedDir();
        $guard = is_dir($dir) ? $this->acquireInvalidationGuard($dir, LOCK_SH) : false;
        try {
            $this->observedGenerations[$this->keyGenerationId($key, $dir)] =
                $this->generationFingerprint($key, $dir);
        } finally {
            $this->releaseInvalidationGuard($guard);
        }
        $path = $this->filePath($key);
        if (!is_file($path)) {
            RequestPerformanceContext::increment('cache_misses');
            return null;
        }

        $modified = filemtime($path) ?: 0;
        if ((time() - $modified) > $ttlSeconds) {
            RequestPerformanceContext::increment('cache_stale_misses');
            return null;
        }

        RequestPerformanceContext::increment('cache_hits');
        return $this->read($key);
    }

    public function put(string $key, array $value): void
    {
        $dir = $this->resolvedDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $keyLock = @fopen(
            rtrim($dir, '/\\') . '/' . $this->safeScopedKey($key) . '.lock',
            'c',
        );
        if ($keyLock === false || !@flock($keyLock, LOCK_EX)) {
            if (is_resource($keyLock)) {
                @fclose($keyLock);
            }
            return;
        }

        try {
            $this->putWhileKeyLocked($key, $value, $dir);
        } finally {
            @flock($keyLock, LOCK_UN);
            @fclose($keyLock);
        }
    }

    /** @param array<mixed> $value */
    private function putWhileKeyLocked(string $key, array $value, string $dir): void
    {
        $guard = $this->acquireInvalidationGuard($dir, LOCK_SH);
        if ($guard === false) {
            return;
        }

        try {
            $generationId = $this->keyGenerationId($key, $dir);
            $currentGeneration = $this->generationFingerprint($key, $dir);
            $observedGeneration = $this->observedGenerations[$generationId] ?? $currentGeneration;
            if ($observedGeneration !== $currentGeneration) {
                return;
            }

            $this->writeValue($key, $value);
            $this->observedGenerations[$generationId] = $currentGeneration;
        } finally {
            $this->releaseInvalidationGuard($guard);
        }
    }

    /** @param array<mixed> $value */
    private function writeValue(string $key, array $value): void
    {

        $path = $this->filePath($key);
        $temp = $path . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return;
        }
        RequestPerformanceContext::increment('cache_writes');
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
        $dir = $this->resolvedDir();
        if (!is_dir($dir)) {
            return;
        }

        $guard = $this->acquireInvalidationGuard($dir, LOCK_EX);
        if ($guard === false) {
            return;
        }

        $safeLogicalKey = $this->safeLogicalKey($key);
        $generationPrefix = $this->isolated
            ? '=' . $this->safeScopedKey($key)
            : $safeLogicalKey . '__ctx_';
        try {
            if (!$this->incrementGeneration($dir, $generationPrefix)) {
                return;
            }
            $files = $this->isolated
                ? [$this->filePath($key)]
                : (glob(rtrim($dir, '/\\') . '/' . $safeLogicalKey . '__ctx_*.json') ?: []);
            foreach ($files as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        } finally {
            $this->releaseInvalidationGuard($guard);
        }
    }

    /** @return array{matched:int,deleted:int,failed:int} */
    public function invalidatePrefix(string $prefix): array
    {
        $dir = $this->resolvedDir();
        $safePrefix = $this->safeLogicalKey($prefix);
        if (!is_dir($dir)) {
            return ['matched' => 0, 'deleted' => 0, 'failed' => 0];
        }

        $result = ['matched' => 0, 'deleted' => 0, 'failed' => 0];
        $guard = $this->acquireInvalidationGuard($dir, LOCK_EX);
        if ($guard === false) {
            return ['matched' => 0, 'deleted' => 0, 'failed' => 1];
        }

        try {
            // A generation protects get()+compute()+put() callers even when
            // no JSON existed at the moment invalidation started.
            if (!$this->incrementGeneration($dir, (string) $safePrefix)) {
                $result['failed']++;
                return $result;
            }
            $files = glob(rtrim($dir, '/\\') . '/' . $safePrefix . '*.json');
            if ($files) {
                foreach ($files as $file) {
                    $result['matched']++;
                    if (@unlink($file) || !is_file($file)) {
                        $result['deleted']++;
                    } else {
                        $result['failed']++;
                    }
                }
            }
        } finally {
            $this->releaseInvalidationGuard($guard);
        }

        return $result;
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

        $lockDir  = $this->resolvedDir();
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $lockPath = rtrim($lockDir, '/\\') . '/' . $this->safeScopedKey($key) . '.lock';

        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            // Cannot lock — compute without caching
            return $compute();
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return $compute();
        }

        try {
            // Re-check: another worker may have filled the cache while we waited
            $refetch = $this->get($key, $ttlSeconds);
            if ($refetch !== null) {
                return $refetch;
            }

            $value = $compute();
            $this->putWhileKeyLocked($key, $value, $lockDir);
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
            RequestPerformanceContext::increment('cache_stale_misses');
            return null;
        }

        $modified = filemtime($path) ?: 0;
        if ((time() - $modified) > $maxStaleness) {
            RequestPerformanceContext::increment('cache_stale_misses');
            return null;
        }

        RequestPerformanceContext::increment('cache_stale_hits');
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
