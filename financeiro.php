<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('finance.view', false);
echo PageRenderer::render('financeiro.html', [
    'page_key' => 'financeiro',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
