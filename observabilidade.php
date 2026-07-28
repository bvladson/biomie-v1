<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('logs.view', false);
echo PageRenderer::render('observabilidade.html', [
    'page_key' => 'observabilidade',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
]);
