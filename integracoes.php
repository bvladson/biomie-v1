<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAnyCapability(['omie.view', 'settings.view'], false);

$capabilities = [
    'omie_sync' => Auth::hasCapability('omie.sync'),
    'jobs_run' => Auth::hasCapability('jobs.run'),
    'sales_sync' => Auth::hasCapability('sales.sync'),
    'geo_materialize' => Auth::hasAnyCapability(['geo.materialize', 'jobs.run']),
    'settings_manage' => Auth::hasCapability('settings.manage'),
    'admin_view' => Auth::hasCapability('admin.view'),
    'logs_view' => Auth::hasCapability('logs.view'),
];

echo PageRenderer::render('integracoes.html', [
    'page_key' => 'omie',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
    'boot_payloads' => [
        'omie' => [
            'capabilities' => $capabilities,
        ],
    ],
]);
