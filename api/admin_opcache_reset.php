<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('settings.manage', true);
Http::enforceCsrf($logger);

$ok = false;
$message = 'OPcache indisponivel neste ambiente.';
if (function_exists('opcache_reset')) {
    $ok = (bool) @opcache_reset();
    $message = $ok ? 'OPcache resetado.' : 'Falha ao resetar OPcache.';
}

Http::json(['ok' => $ok, 'message' => $message, 'request_id' => RequestContext::id()]);
