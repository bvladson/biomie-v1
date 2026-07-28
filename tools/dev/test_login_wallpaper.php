<?php

/**
 * tools/dev/test_login_wallpaper.php — Diagnóstico de conectividade do Bing Wallpaper.
 *
 * Uso:
 *   php tools/dev/test_login_wallpaper.php
 *   php tools/dev/test_login_wallpaper.php --clear   (limpa cache antes de testar)
 */

declare(strict_types=1);

$clearCache = in_array('--clear', $argv ?? [], true);

$cacheFile = __DIR__ . '/../../storage/cache/login_wallpaper.json';
$proxyUrl  = 'https://bing.biturl.top/?resolution=1920x1080&format=json&image_format=webp&index=0&mkt=pt-BR';
$bingUrl   = 'https://www.bing.com/HPImageArchive.aspx?format=js&idx=0&n=1&mkt=pt-BR';
$whitelist = ['https://www.bing.com/', 'https://bing.biturl.top/'];

echo "\n=== Login Wallpaper — Diagnóstico de Conectividade ===\n\n";

// ── 0. Clear cache ────────────────────────────────────────────────────────────
if ($clearCache && file_exists($cacheFile)) {
    unlink($cacheFile);
    echo "[cache] Limpo.\n\n";
}

// ── 1. Estado do cache atual ──────────────────────────────────────────────────
echo "── 1. Cache atual ─────────────────────────────────────────\n";
if (!file_exists($cacheFile)) {
    echo "  Arquivo não encontrado: {$cacheFile}\n";
} else {
    $raw  = file_get_contents($cacheFile);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        echo "  CORROMPIDO (JSON inválido)\n";
    } else {
        $age = time() - (int) ($data['cached_at'] ?? 0);
        printf("  source        : %s\n", $data['source']    ?? '?');
        printf("  cached_at     : %s (%s atrás)\n", date('d/m H:i', (int) ($data['cached_at'] ?? 0)), fmtAge($age));
        printf("  url           : %s\n", $data['url'] !== '' ? $data['url'] : '(vazio)');
        if (!empty($data['last_error'])) {
            printf("  last_error    : %s\n", $data['last_error']);
            printf("  last_error_at : %s\n", $data['last_error_at'] ?? '?');
        }
    }
}

// ── 2. cURL disponível ────────────────────────────────────────────────────────
echo "\n── 2. cURL ─────────────────────────────────────────────────\n";
if (!function_exists('curl_init')) {
    echo "  FALHA: extensão cURL não disponível.\n";
    exit(1);
}
echo "  OK — cURL disponível (" . curl_version()['version'] . ")\n";

// ── 3. Testar proxy bing.biturl.top ───────────────────────────────────────────
echo "\n── 3. Proxy bing.biturl.top ────────────────────────────────\n";
$proxyResult = curlFetch($proxyUrl);
if ($proxyResult['error']) {
    echo "  FALHA: " . $proxyResult['error'] . "\n";
} else {
    printf("  HTTP %d — %d bytes\n", $proxyResult['http'], strlen($proxyResult['body']));
    $d = json_decode($proxyResult['body'], true);
    if (!is_array($d)) {
        echo "  FALHA: resposta não é JSON válido\n";
        echo "  Raw (100 chars): " . substr($proxyResult['body'], 0, 100) . "\n";
    } else {
        $url = (string) ($d['url'] ?? '');
        echo "  url retornada : " . ($url ?: '(campo vazio)') . "\n";
        echo "  whitelist ok  : " . (isSafe($url, $whitelist) ? 'SIM' : 'NÃO ← ajustar whitelist') . "\n";
        echo "  copyright     : " . ($d['copyright'] ?? '—') . "\n";
    }
}

// ── 4. Testar Bing direto ─────────────────────────────────────────────────────
echo "\n── 4. Bing direto (HPImageArchive) ─────────────────────────\n";
$bingResult = curlFetch($bingUrl);
if ($bingResult['error']) {
    echo "  FALHA: " . $bingResult['error'] . "\n";
} else {
    printf("  HTTP %d — %d bytes\n", $bingResult['http'], strlen($bingResult['body']));
    $d = json_decode($bingResult['body'], true);
    if (!is_array($d) || empty($d['images'][0])) {
        echo "  FALHA: estrutura inesperada\n";
        echo "  Raw (100 chars): " . substr($bingResult['body'], 0, 100) . "\n";
    } else {
        $img  = $d['images'][0];
        $path = (string) ($img['url'] ?? '');
        $url  = str_starts_with($path, 'http') ? $path : 'https://www.bing.com' . $path;
        echo "  url retornada : " . $url . "\n";
        echo "  whitelist ok  : " . (isSafe($url, $whitelist) ? 'SIM' : 'NÃO') . "\n";
        echo "  copyright     : " . ($img['copyright'] ?? '—') . "\n";
    }
}

// ── 5. Testar via Service ─────────────────────────────────────────────────────
echo "\n── 5. LoginWallpaperService::getWallpaper() ────────────────\n";
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
    try {
        $svc    = new \BIomie\Services\LoginWallpaperService();
        $result = $svc->getWallpaper();
        printf("  source    : %s\n", $result['source']);
        printf("  url       : %s\n", $result['url'] !== '' ? $result['url'] : '(vazio — fallback)');
        if (!empty($result['last_error'])) {
            printf("  last_error: %s\n", $result['last_error']);
        }
    } catch (\Throwable $e) {
        echo "  EXCEÇÃO: " . $e->getMessage() . "\n";
    }
} else {
    echo "  (autoloader não encontrado — pular)\n";
}

echo "\n";

// ── Funções ───────────────────────────────────────────────────────────────────

function curlFetch(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BIomie-Test/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['error' => "curl #{$errno}: {$error}", 'http' => 0, 'body' => ''];
    }
    if ($http < 200 || $http >= 300) {
        return ['error' => "HTTP {$http}", 'http' => $http, 'body' => (string) $body];
    }
    return ['error' => '', 'http' => $http, 'body' => (string) $body];
}

function isSafe(string $url, array $whitelist): bool
{
    foreach ($whitelist as $prefix) {
        if (str_starts_with($url, $prefix)) return true;
    }
    return false;
}

function fmtAge(int $s): string
{
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return (int) ($s / 60) . 'min';
    $h = (int) ($s / 3600);
    $m = (int) (($s % 3600) / 60);
    return $h . 'h' . ($m > 0 ? ' ' . $m . 'min' : '');
}
