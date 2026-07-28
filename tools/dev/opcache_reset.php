<?php

declare(strict_types=1);

/**
 * Fase L — OPcache Reset via Web
 *
 * Por que isso é necessário:
 *   CLI `php -r "opcache_reset();"` opera no processo CLI, não no pool Apache/PHP-FPM.
 *   Para invalidar o OPcache em produção (Hostinger PHP-FPM), é preciso chamar via web.
 *
 * Uso:
 *   https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=<ADMIN_TOKEN>
 *
 * Proteção:
 *   Requer variável de ambiente BIOMIE_ADMIN_TOKEN definida no .htaccess ou config/env.php.
 *   Sem o token, retorna 403.
 *
 * Resposta: JSON com status, scripts_cached_before e scripts_cached_after.
 */

// ── Auth ─────────────────────────────────────────────────────────────────────
$adminToken = getenv('BIOMIE_ADMIN_TOKEN') ?: null;
$provided   = $_GET['token'] ?? $_SERVER['HTTP_X_BIOMIE_TOKEN'] ?? null;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if ($adminToken === null || $adminToken === '' || $provided !== $adminToken) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Token inválido ou ausente.']);
    exit(1);
}

// ── OPcache status antes ──────────────────────────────────────────────────────
$before = ['available' => false, 'enabled' => false, 'scripts_cached' => null];
$after  = ['available' => false, 'enabled' => false, 'scripts_cached' => null, 'reset_ok' => false];

if (!function_exists('opcache_get_status') || !function_exists('opcache_reset')) {
    http_response_code(200);
    echo json_encode([
        'ok'      => false,
        'message' => 'Extensão OPcache não disponível neste ambiente.',
        'before'  => $before,
        'after'   => $after,
    ]);
    exit(0);
}

$statusBefore = opcache_get_status(false);
if (is_array($statusBefore)) {
    $before = [
        'available'      => true,
        'enabled'        => (bool) ($statusBefore['opcache_enabled'] ?? false),
        'scripts_cached' => (int) ($statusBefore['opcache_statistics']['num_cached_scripts'] ?? 0),
        'memory_used_mb' => round((float) ($statusBefore['memory_usage']['used_memory'] ?? 0) / 1048576, 1),
    ];
}

// ── Reset ─────────────────────────────────────────────────────────────────────
$resetResult = @opcache_reset();

// ── OPcache status depois ─────────────────────────────────────────────────────
$statusAfter = opcache_get_status(false);
if (is_array($statusAfter)) {
    $after = [
        'available'      => true,
        'enabled'        => (bool) ($statusAfter['opcache_enabled'] ?? false),
        'scripts_cached' => (int) ($statusAfter['opcache_statistics']['num_cached_scripts'] ?? 0),
        'memory_used_mb' => round((float) ($statusAfter['memory_usage']['used_memory'] ?? 0) / 1048576, 1),
        'reset_ok'       => $resetResult,
    ];
}

// ── Invalidar arquivos Fase K explicitamente (fallback se reset falhou) ───────
$root    = dirname(__DIR__, 2);
$targets = [
    'assets/js/app.js',
    'assets/js/core/render-helpers.js',
    'api/orders.php',
    'api/vendors.php',
    'api/overview.php',
    'api/products.php',
    'app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php',
    'app/Modules/Sales/Infrastructure/ProductMixReadRepository.php',
    'app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php',
    'app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php',
];

$invalidated = [];
foreach ($targets as $rel) {
    $abs = $root . '/' . $rel;
    if (file_exists($abs) && function_exists('opcache_invalidate')) {
        $ok = opcache_invalidate($abs, true);
        $invalidated[$rel] = $ok;
    }
}

http_response_code(200);
echo json_encode([
    'ok'          => $resetResult,
    'message'     => $resetResult ? 'OPcache resetado com sucesso.' : 'opcache_reset() retornou false (possível env restrito).',
    'timestamp'   => date(DATE_ATOM),
    'before'      => $before,
    'after'       => $after,
    'invalidated' => $invalidated,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit(0);
