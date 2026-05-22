<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('sales.view', false);

$isAdmin = Auth::isAdmin();

echo PageRenderer::render('plano_voo.html', [
    'page_key'      => 'rota-comercial',
    'user'          => Auth::currentUser(),
    'is_admin'      => $isAdmin,
    'topbarActions' => [
        ['label' => 'Equipe', 'href' => 'vendedores.php', 'class' => 'btn-secondary btn-sm', 'icon' => 'groups'],
    ],
]);
