<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Company\CompanyPackageLoader;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$root = dirname(__DIR__, 2);
$checks = [];

$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$companies = $pdo->query(
    "SELECT c.id, c.slug, c.cnpj, c.canal_padrao,
            COALESCE(i.active, 0) integration_active,
            CASE WHEN COALESCE(i.app_key, '') <> ''
                       AND COALESCE(i.app_secret_encrypted, '') <> ''
                 THEN 1 ELSE 0 END integration_configured
       FROM biomie_companies c
       LEFT JOIN biomie_company_integrations i
         ON i.company_id=c.id AND i.provider='omie'
      WHERE c.ativo=1
      ORDER BY c.id"
)->fetchAll(PDO::FETCH_ASSOC);

$check('five_active_companies', count($companies) === 5, count($companies));
$check(
    'canonical_company_slugs',
    array_column($companies, 'slug') === [
        'MAG_FILIAL',
        'EXPERT',
        'UNI_DESIGN',
        'MAG_MATRIZ',
        'ERGOOFFICE',
    ],
    array_column($companies, 'slug')
);

$manifestResults = [];
foreach ($companies as $company) {
    $slug = strtolower((string) $company['slug']);
    $manifest = CompanyPackageLoader::load($slug);
    $manifestResults[$slug] = [
        'identity' => $manifest->matchesIdentity($slug, (int) $company['id']),
        'channel' => $manifest->defaultChannel() === (string) $company['canal_padrao'],
        'active' => $manifest->isActive(),
        'logo' => $manifest->logoIconPath(),
        'logo_exists' => is_file($root . '/' . ltrim($manifest->logoIconPath(), '/')),
    ];
}
$check(
    'manifest_identity_and_channel_match',
    !in_array(false, array_map(
        static fn (array $row): bool => $row['identity'] && $row['channel'] && $row['active'] && $row['logo_exists'],
        $manifestResults
    ), true),
    $manifestResults
);

$packageDirs = array_map(
    static fn (string $path): string => basename($path),
    glob($root . '/companies/*', GLOB_ONLYDIR) ?: []
);
sort($packageDirs);
$check(
    'no_duplicate_ergo_packages',
    in_array('ergooffice', $packageDirs, true)
        && !in_array('ergo', $packageDirs, true)
        && !in_array('ergo_office', $packageDirs, true),
    $packageDirs
);

$check(
    'all_omie_integrations_ready',
    count(array_filter(
        $companies,
        static fn (array $row): bool => (bool) $row['integration_active']
            && (bool) $row['integration_configured']
    )) === 5,
    $companies
);

$selector = (string) file_get_contents($root . '/select_empresa.php');
$selectorJs = (string) file_get_contents($root . '/assets/js/select-empresa.js');
$contextSwitch = (string) file_get_contents($root . '/api/context_switch.php');
$setContextAdapter = (string) file_get_contents($root . '/api/set_context.php');
$tabRuntime = (string) file_get_contents($root . '/assets/js/tenant-tab-context.js');

$check(
    'selector_assets_versioned',
    str_contains($selector, "Asset::url('assets/css/login-wallpaper.css')")
        && str_contains($selector, "Asset::url('assets/js/select-empresa.js')")
        && str_contains($selector, "Asset::url('assets/js/login-wallpaper.js')")
);
$check(
    'tab_context_runtime_registered',
    str_contains(
        (string) file_get_contents($root . '/app/PageAssetRegistry.php'),
        "'assets/js/tenant-tab-context.js'"
    )
        && is_file($root . '/assets/js/tenant-tab-context.js')
        && str_contains($selectorJs, 'api/context_switch.php')
);
$check(
    'tab_context_runtime_hardened',
    str_contains($tabRuntime, 'history.replaceState = function')
        && str_contains($tabRuntime, 'history.pushState = function')
        && str_contains($tabRuntime, 'if (!sameOriginUrl(inputUrl))')
        && str_contains($tabRuntime, 'if (!sameOriginUrl(action)) return')
        && str_contains($tabRuntime, 'isContextFreePath')
        && str_contains($tabRuntime, 'new BroadcastChannel(channelName)')
        && str_contains($tabRuntime, 'BIOMIE_TAB_CONTEXT_READY')
        && str_contains($tabRuntime, "['CONNECTING', 'OPEN', 'CLOSED']")
);
$check(
    'expired_context_has_controlled_recovery',
    str_contains(
        (string) file_get_contents($root . '/app/Support/TenantContext.php'),
        "'context_required'"
    )
        && str_contains(
            (string) file_get_contents($root . '/api/bootstrap.php'),
            'X-BIOMIE-Context-Invalid'
        )
        && str_contains(
            (string) file_get_contents($root . '/app/page_bootstrap.php'),
            'select_empresa.php?context_reset=1'
        )
);
$check(
    'selector_accessibility',
    str_contains($selector, ':focus-visible')
        && str_contains($selector, 'aria-pressed=')
        && str_contains($selectorJs, "setAttribute('aria-busy'")
);
$check(
    'context_switch_is_capability_neutral_and_audited',
    str_contains($contextSwitch, 'Auth::requireLogin(true)')
        && !str_contains($contextSwitch, 'Auth::requireCapability(')
        && str_contains($contextSwitch, 'ContextSwitchAuditLogger')
        && str_contains($contextSwitch, 'createCompanyContext')
        && str_contains($contextSwitch, 'createGroupContext')
        && str_contains($setContextAdapter, "require __DIR__ . '/context_switch.php'")
        && !str_contains($setContextAdapter, 'biomie_tenant')
);
$check(
    'selector_uses_real_integration_status',
    str_contains($selector, "company['integration_active']")
        && str_contains($selector, "company['integration_configured']")
);

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
