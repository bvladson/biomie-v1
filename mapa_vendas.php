<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('geo.view', false);

echo PageRenderer::render('mapa_vendas.html', [
    'page_key' => 'geografia',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
