<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('geo.view', false);

echo PageRenderer::render('mapa_vendas.html', [
    'page_key' => 'geografia',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
    'boot_payloads' => [
        'features' => [
            'geo_readmodel_v2' => FeatureFlags::geoReadModelV2($config),
            'geo_single_payload' => FeatureFlags::geoSinglePayload($config),
            'is_admin' => Auth::isAdmin(),
        ],
    ],
]);
