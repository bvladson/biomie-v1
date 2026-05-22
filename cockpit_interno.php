<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['sales.view', 'finance.view', 'fiscal.view', 'geo.view', 'admin.view', 'logs.view'], false);

echo PageRenderer::renderPhp('cockpit_interno.php', [
    'page_key' => 'cockpit-interno',
    'user'     => Auth::currentUser(),
    'is_admin' => Auth::canAccessAdminArea(),
]);
