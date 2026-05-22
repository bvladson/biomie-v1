<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'settings.view', 'logs.view'], false);

$registry = MetricRegistry::build();
$allKpis  = $registry->all();

$byDomain = [];
foreach ($allKpis as $def) {
    $byDomain[$def->domain][] = $def;
}
ksort($byDomain);

echo PageRenderer::renderPhp('metric_catalog.php', [
    'page_key' => 'catalogo-kpis',
    'user'     => Auth::currentUser(),
    'is_admin' => Auth::canAccessAdminArea(),
    'byDomain' => $byDomain,
    'total'    => count($allKpis),
    'active'   => count($registry->active()),
    'draft'    => count($registry->draft()),
]);
