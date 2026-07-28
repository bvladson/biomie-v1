<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;

require_once __DIR__ . '/app/Bootstrap.php';
Bootstrap::init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$configuredToken = trim((string) (getenv('BIOMIE_ADMIN_TOKEN') ?: ''));
$providedToken = trim((string) ($_SERVER['HTTP_X_BIOMIE_TOKEN'] ?? ''));
$tokenAuthorized = $configuredToken !== ''
    && $providedToken !== ''
    && hash_equals($configuredToken, $providedToken);

if (!$tokenAuthorized) {
    $config = new Config();
    $logger = new Logger(__DIR__ . '/storage/logs/biomie.log');
    Auth::boot($config, $logger);
    if (!Auth::isAuthenticated() || !Auth::hasCapability('logs.view')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$stats = is_array($status) && is_array($status['opcache_statistics'] ?? null)
    ? $status['opcache_statistics']
    : [];

echo json_encode([
    'ok' => true,
    'authorized_by' => $tokenAuthorized ? 'admin_token' : 'authenticated_session',
    'runtime' => [
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'opcache' => [
            'enabled' => is_array($status) && (bool) ($status['opcache_enabled'] ?? false),
            'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
            'scripts_cached' => is_array($status) ? (int) ($stats['num_cached_scripts'] ?? 0) : null,
            'hit_rate_pct' => is_array($status) ? round((float) ($stats['opcache_hit_rate'] ?? 0), 2) : null,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
