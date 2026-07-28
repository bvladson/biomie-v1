<?php

declare(strict_types=1);

/**
 * Login Wallpaper — Diagnóstico CLI
 *
 * Uso:
 *   php tools/dev/login_wallpaper_debug.php
 *   php tools/dev/login_wallpaper_debug.php --json
 *   php tools/dev/login_wallpaper_debug.php --clear-cache
 *   php tools/dev/login_wallpaper_debug.php --fetch
 *
 * Nunca exibe UNSPLASH_ACCESS_KEY.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__, 2);
chdir($root);

// Bootstrap project autoloader and env
require_once $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$localCfg = $root . '/config/.env.local.php';
if (is_file($localCfg)) {
    $secrets = (array) include $localCfg;
    foreach (['UNSPLASH_ACCESS_KEY', 'UNSPLASH_ENABLED', 'UNSPLASH_UTM_SOURCE', 'UNSPLASH_QUERY', 'UNSPLASH_CACHE_TTL_HOURS'] as $k) {
        if (isset($secrets[$k]) && $secrets[$k] !== '' && getenv($k) === false) {
            putenv($k . '=' . $secrets[$k]);
        }
    }
}

$args       = array_slice($argv, 1);
$wantJson   = in_array('--json', $args, true);
$clearCache = in_array('--clear-cache', $args, true);
$doFetch    = in_array('--fetch', $args, true);

$cacheFile = $root . '/storage/cache/login_wallpaper.json';

// ── Clear cache ────────────────────────────────────────────────────────────────

if ($clearCache) {
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
        echo $wantJson ? json_encode(['action' => 'clear_cache', 'ok' => true]) . PHP_EOL
                       : "Cache removido: $cacheFile\n";
    } else {
        echo $wantJson ? json_encode(['action' => 'clear_cache', 'ok' => true, 'note' => 'nao existia']) . PHP_EOL
                       : "Cache nao existia.\n";
    }
    if (!$doFetch) exit(0);
}

// ── Force fresh fetch ──────────────────────────────────────────────────────────

if ($doFetch) {
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
    $svc    = new \BIomie\Services\LoginUnsplashWallpaperService();
    $result = $svc->getWallpaper();
    unset($result['_cached_at_ts'], $result['_last_error'], $result['_last_error_at']);
    $result['_fetched_at'] = date('Y-m-d H:i:s');
    echo $wantJson ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
                   : formatPlain('Resultado do fetch', $result);
    exit($result['source'] === 'unsplash' ? 0 : 1);
}

// ── Collect diagnostics ────────────────────────────────────────────────────────

$keyRaw     = getenv('UNSPLASH_ACCESS_KEY');
$keyPresent = $keyRaw !== false && $keyRaw !== '';
$enabled    = getenv('UNSPLASH_ENABLED');
$query      = getenv('UNSPLASH_QUERY');
$ttl        = getenv('UNSPLASH_CACHE_TTL_HOURS');

$cacheData     = null;
$cachePresent  = false;
$cacheProvider = '-';
$cacheSource   = '-';
$cacheExpires  = '-';
$cacheAge      = '-';
$cacheLastErr  = '-';
$cacheUrlDomain = '-';

if (file_exists($cacheFile)) {
    $cachePresent = true;
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
        $cacheData = json_decode($raw, true);
        if (is_array($cacheData)) {
            $cacheProvider = (string) ($cacheData['provider'] ?? '-');
            $cacheSource   = (string) ($cacheData['source']   ?? '-');
            $cacheExpires  = (string) ($cacheData['expires_at'] ?? '-');
            $url           = (string) ($cacheData['url'] ?? '');
            $cacheUrlDomain = $url === '' ? '-'
                : (str_starts_with($url, 'assets/') ? 'local (fallback)'
                : (string) (parse_url($url, PHP_URL_HOST) ?: '-'));
            $cacheLastErr  = (string) ($cacheData['_last_error'] ?? '-');
            $ts = (int) ($cacheData['_cached_at_ts'] ?? 0);
            if ($ts > 0) {
                $cacheAge = fmtAge(time() - $ts);
            }
        }
    }
}

// Compute recommended CSP snippet
$csp = implode(' ', [
    "default-src 'self';",
    "img-src 'self' data: https://images.unsplash.com https://plus.unsplash.com;",
    "connect-src 'self';",
    "script-src 'self';",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;",
    "font-src 'self' https://fonts.gstatic.com data:;",
    "frame-ancestors 'none';",
    "form-action 'self'",
]);

$report = [
    'env' => [
        'UNSPLASH_ENABLED'          => $enabled !== false ? $enabled : '(nao definida)',
        'UNSPLASH_ACCESS_KEY'       => $keyPresent ? 'sim (presente, ' . strlen($keyRaw) . ' chars)' : 'NAO CONFIGURADA',
        'UNSPLASH_UTM_SOURCE'       => getenv('UNSPLASH_UTM_SOURCE') ?: '(padrao: biomie_mag)',
        'UNSPLASH_QUERY'            => $query ?: '(padrao do servico)',
        'UNSPLASH_CACHE_TTL_HOURS'  => $ttl ?: '(padrao: 12h)',
    ],
    'cache' => [
        'existe'          => $cachePresent ? 'sim' : 'nao',
        'provider'        => $cacheProvider,
        'source'          => $cacheSource,
        'expires_at'      => $cacheExpires,
        'idade'           => $cacheAge,
        'url_dominio'     => $cacheUrlDomain,
        'ultimo_erro'     => $cacheLastErr,
    ],
    'diagnostics' => [
        'endpoint_source_esperado' => !$keyPresent ? 'no_key (fallback)'
            : ($enabled === '0' ? 'disabled (fallback)' : 'unsplash ou cache'),
        'csp_recomendada'          => $csp,
    ],
];

if ($wantJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "\n=== Login Wallpaper — Diagnóstico ===\n";
    echo "\n--- ENV ---\n";
    foreach ($report['env'] as $k => $v) {
        printf("  %-30s %s\n", $k . ':', $v);
    }
    echo "\n--- CACHE ---\n";
    foreach ($report['cache'] as $k => $v) {
        printf("  %-30s %s\n", $k . ':', $v);
    }
    echo "\n--- DIAGNÓSTICO ---\n";
    printf("  %-30s %s\n", 'source_esperado:', $report['diagnostics']['endpoint_source_esperado']);
    echo "\n  CSP recomendada:\n  " . $csp . "\n\n";
    if (!$keyPresent) {
        echo "  ⚠  UNSPLASH_ACCESS_KEY ausente — configure em config/.env.local.php\n";
    }
    if ($cacheSource === 'fallback' && $cacheLastErr !== '-' && $cacheLastErr !== '') {
        echo "  ⚠  Cache contém fallback por erro: $cacheLastErr\n";
        echo "     Limpe com: php tools/dev/login_wallpaper_debug.php --clear-cache\n";
    }
    echo "\n";
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtAge(int $s): string
{
    if ($s < 0)    return '0s';
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return (int) ($s / 60) . 'min';
    $h = (int) ($s / 3600);
    $m = (int) (($s % 3600) / 60);
    return $h . 'h' . ($m > 0 ? ' ' . $m . 'min' : '');
}

function formatPlain(string $title, array $data): string
{
    $out = "\n=== $title ===\n";
    foreach ($data as $k => $v) {
        if (str_starts_with($k, '_')) continue;
        $out .= sprintf("  %-24s %s\n", $k . ':', is_string($v) ? $v : json_encode($v));
    }
    return $out . "\n";
}
