<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAnyCapability(['omie.view', 'settings.view'], false);
echo PageRenderer::render('integracoes.html', [
    'page_key' => 'omie',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
