<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('sales.view', false);

$isAdmin = Auth::isAdmin();
$pageActions = $isAdmin ? [
    [
        'label' => 'Editar metas',
        'href'  => 'plano_voo_metas.php',
        'class' => 'btn-primary',
        'icon'  => 'edit',
    ],
] : [];

echo PageRenderer::render('plano_voo.html', [
    'page_key'    => 'rota-comercial',
    'user'        => Auth::currentUser(),
    'is_admin'    => $isAdmin,
    'pageActions' => $pageActions,
]);
