<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('sales.view', false);
echo PageRenderer::render('pedidos.html', [
    'page_key' => 'nf-periodo',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
