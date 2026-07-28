<?php

declare(strict_types=1);

// Public endpoint — no authentication or session required.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// Load BIomie autoloader (project-native PSR-4 loader; no Composer required on this server).
require_once __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

// Expose Unsplash env vars from local config file so getenv() works in the service.
// This endpoint skips the full api/bootstrap.php (no auth), so we bootstrap env manually.
// System environment variables always take priority over the local file.
(static function (): void {
    $localCfg = __DIR__ . '/../config/.env.local.php';
    if (!is_file($localCfg)) {
        return;
    }
    $secrets = (array) include $localCfg;
    $keys = ['UNSPLASH_ACCESS_KEY', 'UNSPLASH_ENABLED', 'UNSPLASH_UTM_SOURCE', 'UNSPLASH_QUERY', 'UNSPLASH_CACHE_TTL_HOURS'];
    foreach ($keys as $k) {
        if (isset($secrets[$k]) && $secrets[$k] !== '' && getenv($k) === false) {
            putenv($k . '=' . $secrets[$k]);
        }
    }
})();

use BIomie\Services\LoginUnsplashWallpaperService;

$errorData = [
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
    'cached_at'         => '',
    'expires_at'        => '',
    'query'             => '',
];

try {
    $service = new LoginUnsplashWallpaperService();
    $result  = $service->getWallpaper();

    // Strip internal fields before sending to frontend.
    unset($result['_cached_at_ts'], $result['_last_error'], $result['_last_error_at']);

    echo json_encode(
        ['ok' => true, 'data' => $result],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable) {
    echo json_encode(
        ['ok' => false, 'data' => $errorData],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
