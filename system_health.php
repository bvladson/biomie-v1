<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireLogin(false);

$isAdmin = Auth::hasCapability('admin.view')
    || Auth::hasCapability('logs.view')
    || Auth::hasCapability('jobs.view');

echo PageRenderer::renderPhp('system_health.php', [
    'page_key' => 'system-health',
    'user'     => Auth::currentUser(),
    'is_admin' => $isAdmin,
    'isAdmin'  => $isAdmin,
]);
