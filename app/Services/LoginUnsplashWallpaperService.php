<?php

declare(strict_types=1);

namespace BIomie\Services;

/**
 * Fetches a random photo from the Unsplash API and caches it locally.
 *
 * Environment variables consumed (never exposed in output or logs):
 *   UNSPLASH_ACCESS_KEY         — Client-ID for Authorization header
 *   UNSPLASH_UTM_SOURCE         — UTM source for attribution links (default: biomie_mag)
 *   UNSPLASH_QUERY              — Comma-separated photo query (default: modern office,...)
 *   UNSPLASH_CACHE_TTL_HOURS    — Cache lifetime in hours (default: 12)
 *   UNSPLASH_ENABLED            — Set to 0 to force fallback immediately (default: 1)
 *
 * Cache file: storage/cache/login_wallpaper.json
 */
final class LoginUnsplashWallpaperService
{
    private const CACHE_FILE   = __DIR__ . '/../../storage/cache/login_wallpaper.json';
    private const API_URL      = 'https://api.unsplash.com/photos/random';
    private const DEFAULT_TTL  = 43200;  // 12 hours — successful fetch
    private const FALLBACK_TTL = 3600;   // 1 hour  — retry after fallback
    private const CONNECT_TO   = 2;
    private const TOTAL_TO     = 3;

    private const IMG_WHITELIST  = [
        'https://images.unsplash.com/',
        'https://plus.unsplash.com/',
    ];
    private const LINK_WHITELIST = [
        'https://unsplash.com/',
        'https://api.unsplash.com/',
    ];

    private const DEFAULT_QUERY  = 'modern office,business workspace,corporate interior,analytics dashboard,team work,office furniture';
    private const DEFAULT_UTM    = 'biomie_mag';

    private string $lastError = '';

    public function getWallpaper(): array
    {
        // Feature flag — if disabled, return fallback immediately without touching the network.
        if ((string) getenv('UNSPLASH_ENABLED') === '0') {
            return $this->buildFallback('disabled');
        }

        $key = (string) getenv('UNSPLASH_ACCESS_KEY');
        if ($key === '') {
            return $this->buildFallback('no_key');
        }

        // Cache hit — avoids Unsplash rate limits.
        $cached = $this->loadCache();
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        // Fresh fetch from Unsplash.
        $fresh = $this->fetchFromUnsplash($key);
        if ($fresh !== null) {
            $this->saveCache($fresh);
            return $fresh;
        }

        // API failed — cache fallback for FALLBACK_TTL so we retry in 1h.
        $fallback = $this->buildFallback('api_error');
        $this->saveCache($fallback);
        return $fallback;
    }

    /**
     * Prepare but do NOT call automatically — only on explicit user action.
     * When called, it pings Unsplash's download endpoint to comply with API guidelines.
     */
    public function triggerUnsplashDownload(string $downloadLocation): void
    {
        if (!$this->isLinkAllowed($downloadLocation)) {
            return;
        }
        $key = (string) getenv('UNSPLASH_ACCESS_KEY');
        if ($key === '' || !function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($downloadLocation);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Client-ID ' . $key,
                'Accept-Version: v1',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    private function loadCache(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }
        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['cached_at'])) {
            // Corrupt — delete so we fetch fresh next time.
            @unlink(self::CACHE_FILE);
            return null;
        }
        $cachedTs = (int) ($data['_cached_at_ts'] ?? strtotime((string) $data['cached_at']));
        $ttl      = (($data['source'] ?? '') === 'fallback') ? self::FALLBACK_TTL : $this->getTtl();
        if ($cachedTs <= 0 || (time() - $cachedTs) > $ttl) {
            return null;
        }
        return $data;
    }

    private function saveCache(array $data): void
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::CACHE_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    // ── Fetch from Unsplash ───────────────────────────────────────────────────

    private function fetchFromUnsplash(string $key): ?array
    {
        $utm   = $this->getUtmSource();
        $query = $this->getQuery();

        $params = http_build_query([
            'query'          => $query,
            'orientation'    => 'landscape',
            'content_filter' => 'high',
        ]);

        $raw = $this->curlGet(self::API_URL . '?' . $params, [
            'Authorization: Client-ID ' . $key,
            'Accept-Version: v1',
        ]);

        if ($raw === null) {
            return null;
        }

        $photo = json_decode($raw, true);
        if (!is_array($photo)) {
            $this->lastError = 'unsplash: JSON invalido';
            $this->logError('json_invalid', 'photos/random');
            return null;
        }

        // Extract and validate image URL — prefer raw+size-params for quality.
        $rawUrl = (string) ($photo['urls']['raw'] ?? '');
        if ($rawUrl !== '' && $this->isImageAllowed($rawUrl)) {
            $sep = str_contains($rawUrl, '?') ? '&' : '?';
            $url = $rawUrl . $sep . 'w=1920&q=85&auto=format&fit=crop';
        } else {
            $url = (string) ($photo['urls']['regular'] ?? $photo['urls']['full'] ?? '');
        }

        if (!$this->isImageAllowed($url)) {
            $this->lastError = 'unsplash: URL da imagem fora da whitelist';
            $this->logError('url_not_allowed', 'images domain');
            return null;
        }

        // Attribution — required by Unsplash guidelines.
        $photographerName = (string) ($photo['user']['name'] ?? '');
        $photographerHtml = (string) ($photo['user']['links']['html'] ?? '');
        $photographerUrl  = '';
        if ($photographerHtml !== '' && $this->isLinkAllowed($photographerHtml)) {
            $sep = str_contains($photographerHtml, '?') ? '&' : '?';
            $photographerUrl = $photographerHtml . $sep . 'utm_source=' . $utm . '&utm_medium=referral';
        }

        $unsplashUrl     = 'https://unsplash.com/?utm_source=' . $utm . '&utm_medium=referral';
        $downloadLocation = (string) ($photo['links']['download_location'] ?? '');
        if (!$this->isLinkAllowed($downloadLocation)) {
            $downloadLocation = '';
        }

        $now     = time();
        $ttl     = $this->getTtl();
        $payload = [
            'provider'          => 'unsplash',
            'source'            => 'unsplash',
            'url'               => $url,
            'blur_hash'         => (string) ($photo['blur_hash'] ?? ''),
            'color'             => $this->sanitizeColor((string) ($photo['color'] ?? '#0f172a')),
            'description'       => substr(strip_tags((string) ($photo['description'] ?? '')), 0, 200),
            'alt_description'   => substr(strip_tags((string) ($photo['alt_description'] ?? '')), 0, 200),
            'photographer_name' => substr(strip_tags($photographerName), 0, 120),
            'photographer_url'  => $photographerUrl,
            'unsplash_url'      => $unsplashUrl,
            'download_location' => $downloadLocation,
            'cached_at'         => date(DATE_ATOM, $now),
            'expires_at'        => date(DATE_ATOM, $now + $ttl),
            'query'             => $query,
            '_cached_at_ts'     => $now,  // internal — used for TTL math
        ];

        return $payload;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function curlGet(string $url, array $headers = []): ?string
    {
        if (!function_exists('curl_init')) {
            $this->lastError = 'cURL nao disponivel';
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TOTAL_TO,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TO,
            CURLOPT_USERAGENT      => 'BIomie/1.0 (compatible)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,  // never follow redirects from API
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $this->lastError = 'curl_errno:' . $errno . ' [' . parse_url($url, PHP_URL_HOST) . ']';
            $this->logError('curl_error', 'errno=' . $errno);
            return null;
        }

        if ($http === 401 || $http === 403) {
            $this->lastError = 'HTTP ' . $http . ' — access key invalida ou permissao negada';
            $this->logError('http_auth', 'status=' . $http);
            return null;
        }

        if ($http === 429) {
            $this->lastError = 'HTTP 429 — rate limit Unsplash atingido';
            $this->logError('rate_limit', 'status=429');
            return null;
        }

        if ($http < 200 || $http >= 300) {
            $this->lastError = 'HTTP ' . $http . ' de ' . parse_url($url, PHP_URL_HOST);
            $this->logError('http_error', 'status=' . $http);
            return null;
        }

        if ($raw === false || $raw === '') {
            $this->lastError = 'resposta vazia de ' . parse_url($url, PHP_URL_HOST);
            $this->logError('empty_response', 'host=' . parse_url($url, PHP_URL_HOST));
            return null;
        }

        return $raw;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isImageAllowed(string $url): bool
    {
        if ($url === '') return false;
        foreach (self::IMG_WHITELIST as $prefix) {
            if (str_starts_with($url, $prefix)) return true;
        }
        return false;
    }

    private function isLinkAllowed(string $url): bool
    {
        if ($url === '') return false;
        foreach (self::LINK_WHITELIST as $prefix) {
            if (str_starts_with($url, $prefix)) return true;
        }
        return false;
    }

    private function sanitizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#0f172a';
    }

    private function getTtl(): int
    {
        $hours = (int) getenv('UNSPLASH_CACHE_TTL_HOURS');
        return ($hours > 0) ? ($hours * 3600) : self::DEFAULT_TTL;
    }

    private function getQuery(): string
    {
        $q = (string) getenv('UNSPLASH_QUERY');
        $q = $q !== '' ? $q : self::DEFAULT_QUERY;

        // Unsplash /photos/random accepts a single search term only.
        // If comma-separated terms are configured, pick one randomly for variety.
        if (str_contains($q, ',')) {
            $terms = array_values(array_filter(array_map('trim', explode(',', $q))));
            if ($terms !== []) {
                return $terms[array_rand($terms)];
            }
        }

        return $q;
    }

    private function getUtmSource(): string
    {
        $utm = (string) getenv('UNSPLASH_UTM_SOURCE');
        return $utm !== '' ? $utm : self::DEFAULT_UTM;
    }

    /** Sanitized log — never includes access key or sensitive data. */
    private function logError(string $reason, string $detail): void
    {
        error_log('[BIomie][LoginWallpaper] provider=unsplash reason=' . $reason . ' detail=' . $detail);
    }

    private function buildFallback(string $reason = 'error'): array
    {
        $now = time();
        $result = [
            'provider'          => 'unsplash',
            'source'            => 'fallback',
            'url'               => 'assets/img/login-fallback.svg',
            'blur_hash'         => '',
            'color'             => '#0f172a',
            'description'       => 'Fallback visual BIomie MAG',
            'alt_description'   => 'Fundo abstrato corporativo',
            'photographer_name' => '',
            'photographer_url'  => '',
            'unsplash_url'      => '',
            'download_location' => '',
            'cached_at'         => date(DATE_ATOM, $now),
            'expires_at'        => date(DATE_ATOM, $now + self::FALLBACK_TTL),
            'query'             => '',
            '_cached_at_ts'     => $now,
        ];
        if ($this->lastError !== '') {
            $result['_last_error']    = $this->lastError;
            $result['_last_error_at'] = date('Y-m-d H:i:s');
        }
        return $result;
    }
}
