<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('sales.view', false);

echo PageRenderer::render('conciliacao.html', [
    'page_key' => 'conciliacao',
    'user'     => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
