<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('settings.manage', true);
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::methodNotAllowed(['POST']);
}
Http::enforceCsrf($logger);

$clear = strtolower(trim((string) ($_POST['clear'] ?? 'api')));
$allowed = ['api', 'dashboard', 'wallpaper', 'assets_meta', 'all'];
if (!in_array($clear, $allowed, true)) {
    Http::validationError('Tipo de cache invalido.', ['allowed' => $allowed]);
}

$targets = [];
$root = Bootstrap::rootPath();
if ($clear === 'api' || $clear === 'all') {
    $targets[] = ['type' => 'dir', 'path' => $root . '/storage/cache/api'];
}
if ($clear === 'dashboard' || $clear === 'all') {
    $targets[] = ['type' => 'dir', 'path' => $root . '/storage/cache/dashboard'];
}
if ($clear === 'wallpaper' || $clear === 'all') {
    $targets[] = ['type' => 'file', 'path' => $root . '/storage/cache/login_wallpaper.json'];
}
if ($clear === 'assets_meta' || $clear === 'all') {
    $targets[] = ['type' => 'file', 'path' => $root . '/storage/cache/assets_build.json'];
}

$removed = [];
foreach ($targets as $target) {
    if ($target['type'] === 'file') {
        if (is_file($target['path'])) {
            @unlink($target['path']);
            $removed[] = str_replace($root . '/', '', $target['path']);
        }
        continue;
    }
    foreach (glob(rtrim($target['path'], '/\\') . '/*.json') ?: [] as $file) {
        @unlink($file);
        $removed[] = str_replace($root . '/', '', $file);
    }
}

Http::json(['ok' => true, 'cleared' => $removed, 'request_id' => RequestContext::id()]);
