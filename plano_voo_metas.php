<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAnyCapability(['sales.sync', 'settings.manage'], false);
echo PageRenderer::render('plano_voo_metas.html', [
    'page_key'      => 'rota-comercial',
    'user'          => Auth::currentUser(),
    'is_admin'      => Auth::isAdmin(),
    'topbarActions' => [
        ['label' => 'Rota Comercial', 'href' => 'plano_voo.php', 'class' => 'btn-secondary btn-sm', 'icon' => 'flight'],
    ],
]);
