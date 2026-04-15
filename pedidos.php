<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireLogin(false);
echo PageRenderer::render('pedidos.html', [
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
