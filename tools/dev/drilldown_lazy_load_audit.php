<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\PageAssetRegistry;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root = dirname(__DIR__, 2);
$checks = [];
$router = (string) @file_get_contents($root . '/assets/js/modules/drilldown-router.js');
$drawerManager = (string) @file_get_contents($root . '/assets/js/core/drawer-manager.js');
$pageRenderer = (string) @file_get_contents($root . '/app/PageRenderer.php');
$customerDetail = (string) @file_get_contents($root . '/assets/js/modules/customer-detail/index.js');

$bundles = [
    'vendor' => [
        'modules/vendor-detail/tabs.js',
        'modules/vendor-detail/composition.js',
        'modules/vendor-detail/charts.js',
        'modules/vendor-detail/index.js',
    ],
    'order' => ['modules/order-detail/index.js'],
    'invoice' => ['modules/invoice-detail/index.js'],
    'customer' => ['modules/customer-detail/index.js'],
    'product' => [
        'modules/product-detail/tabs.js',
        'modules/product-detail/charts.js',
        'modules/product-detail/index.js',
    ],
    'neighborhood' => [
        'modules/geo-detail/tabs.js',
        'modules/geo-detail/charts.js',
        'modules/geo-detail/index.js',
    ],
];

foreach ($bundles as $feature => $paths) {
    $ok = str_contains($router, $feature . ':');
    foreach ($paths as $path) {
        $ok = $ok
            && str_contains($router, "'" . $path . "'")
            && is_file($root . '/assets/js/' . $path);
    }
    drilldownCheck($checks, 'bundle_' . $feature, $ok, "Bundle {$feature} completo e disponivel.");
}

$eagerAssets = [];
$detailFolders = array_map(
    static fn (string $feature): string => '/' . ($feature === 'neighborhood' ? 'geo' : $feature) . '-detail/',
    array_keys($bundles),
);
foreach (PageAssetRegistry::pageKeys() as $pageKey) {
    foreach (PageAssetRegistry::jsBodyForPage($pageKey) as $path) {
        foreach ($detailFolders as $folder) {
            if (str_contains($path, $folder)) {
                $eagerAssets[] = $pageKey . ':' . $path;
            }
        }
    }
}

drilldownCheck($checks, 'no_eager_registry_assets', $eagerAssets === [], 'Drawers fora do carregamento inicial das paginas.');
drilldownCheck(
    $checks,
    'sequential_loader',
    str_contains($router, 'function ensureFeature(feature)')
        && str_contains($router, 'featureBundles[feature].reduce')
        && str_contains($router, 'script.async = false'),
    'Dependencias de cada drawer carregam em ordem.',
);
drilldownCheck(
    $checks,
    'cache_versioning',
    PageAssetRegistry::lazyDrilldownVersion() !== ''
        && str_contains($pageRenderer, 'data-biomie-lazy-version')
        && str_contains($router, "script.getAttribute('data-biomie-lazy-version')"),
    'Versao conjunta invalida cache quando qualquer drawer muda.',
);
drilldownCheck(
    $checks,
    'direct_link_support',
    str_contains($drawerManager, 'global.BIomieLazyDrilldowns.ensure(type)')
        && str_contains($drawerManager, 'urlInitialized'),
    'Links diretos esperam o drawer e abrem uma unica vez.',
);
drilldownCheck(
    $checks,
    'cross_detail_navigation',
    str_contains($customerDetail, 'data-biomie-action="open-product"')
        && !str_contains($customerDetail, 'window.BIomieProductDetail && row.codigo_produto'),
    'Navegacao cliente para produto funciona antes do modulo existir.',
);

$viewEagerAssets = [];
foreach (['mercadorias.html', 'plano_voo.html', 'mapa_vendas.html'] as $view) {
    $contents = (string) @file_get_contents($root . '/views/' . $view);
    if (str_contains($contents, 'modules/drilldown-router.js') || preg_match('~modules/(?:vendor|order|invoice|customer|product|geo)-detail/~', $contents)) {
        $viewEagerAssets[] = $view;
    }
}
drilldownCheck($checks, 'views_use_registry', $viewEagerAssets === [], 'Views usam o registro canonico para o roteador.');

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$payload = [
    'ok' => $failed === [],
    'summary' => [
        'checks' => count($checks),
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
        'lazy_version' => PageAssetRegistry::lazyDrilldownVersion(),
        'eager_registry_assets' => $eagerAssets,
        'views_with_eager_assets' => $viewEagerAssets,
    ],
    'checks' => $checks,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);

/**
 * @param list<array{id:string,ok:bool,message:string}> $checks
 */
function drilldownCheck(array &$checks, string $id, bool $ok, string $message): void
{
    $checks[] = ['id' => $id, 'ok' => $ok, 'message' => $message];
}
