<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAnyCapability(['fiscal.view', 'finance.view'], false);
echo PageRenderer::render('fiscal.html', [
    'page_key' => 'fiscal',
    'user'     => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
