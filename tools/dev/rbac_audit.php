<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);

/** @var array<string,array{expected:string,allow_login_only?:bool}> $targets */
$targets = [
    'financeiro.php' => ['expected' => 'finance.view'],
    'index.php' => ['expected' => 'sales.view'],
    'vendedores.php' => ['expected' => 'sales.view'],
    'pedidos.php' => ['expected' => 'sales.view'],
    'mercadorias.php' => ['expected' => 'sales.view'],
    'integracoes.php' => ['expected' => 'omie.view|settings.view'],
    'plano_voo.php' => ['expected' => 'sales.view'],
    'plano_voo_metas.php' => ['expected' => 'sales.sync|settings.manage'],
    'reconciliacao.php' => ['expected' => 'fiscal.view'],
    'observabilidade.php' => ['expected' => 'logs.view'],
    'admin.php' => ['expected' => 'admin.view|admin.manage|logs.view|settings.manage'],
    'mapa_vendas.php' => ['expected' => 'geo.view'],

    'api/bootstrap.php' => ['expected' => 'login bootstrap', 'allow_login_only' => true],
    'api/bootstrap_finance.php' => ['expected' => 'finance.view'],
    'api/finance_overview.php' => ['expected' => 'finance.view'],
    'api/finance_sync.php' => ['expected' => 'finance.sync'],
    'api/finance_titles.php' => ['expected' => 'finance.view'],
    'api/overview.php' => ['expected' => 'sales.view'],
    'api/vendors.php' => ['expected' => 'sales.view'],
    'api/canonical_vendors.php' => ['expected' => 'sales.view'],
    'api/orders.php' => ['expected' => 'sales.view'],
    'api/products.php' => ['expected' => 'sales.view'],
    'api/sync.php' => ['expected' => 'sales.sync'],
    'api/flight_plan.php' => ['expected' => 'sales.view'],
    'api/flight_plan_vendor_detail.php' => ['expected' => 'sales.view'],
    'api/flight_plan_targets_read.php' => ['expected' => 'sales.view'],
    'api/flight_plan_targets_list.php' => ['expected' => 'sales.view'],
    'api/flight_plan_targets_editor.php' => ['expected' => 'sales.sync|settings.manage'],
    'api/flight_plan_targets_upsert.php' => ['expected' => 'sales.sync'],
    'api/canonical_sync.php' => ['expected' => 'omie.sync'],
    'api/omie_status.php' => ['expected' => 'omie.view|jobs.view'],
    'api/omie_test_connection.php' => ['expected' => 'omie.sync'],
    'api/realtime_sync_status.php' => ['expected' => 'jobs.view'],
    'api/refresh_status.php' => ['expected' => 'jobs.view|sales.view|finance.view'],
    'api/live_stream.php' => ['expected' => 'jobs.view|sales.view'],
    'api/materialize_clients.php' => ['expected' => 'jobs.run|omie.sync|settings.manage'],
    'api/geo_materialize.php' => ['expected' => 'geo.materialize|jobs.run'],
    'api/geo_cnae_materialize.php' => ['expected' => 'geo.materialize|jobs.run'],
    'api/operational_observability.php' => ['expected' => 'logs.view'],
    'api/admin_health.php' => ['expected' => 'logs.view'],
    'api/reconciliation.php' => ['expected' => 'fiscal.view'],
    'api/reconciliation_queue.php' => ['expected' => 'fiscal.sync|jobs.run'],
    'api/domain_entity_map.php' => ['expected' => 'settings.manage|admin.view'],
    'api/omie_method_policy.php' => ['expected' => 'settings.manage'],
    'api/omie_materialize_commercial.php' => ['expected' => 'sales.sync|omie.sync|jobs.run'],
    'api/status.php' => ['expected' => 'jobs.view|sales.view|finance.view|fiscal.view'],
    'api/commercial_health.php' => ['expected' => 'finance.view|jobs.view|sales.view'],
    'api/system_health.php'    => ['expected' => 'logs.view|jobs.view|admin.view'],
    'system_health.php'        => ['expected' => 'logs.view|jobs.view|admin.view'],
    'api/admin_geo_rebuild.php' => ['expected' => 'admin.view|settings.manage'],
];

$summary = [
    'checked' => 0,
    'protected' => 0,
    'login_only' => 0,
    'missing' => 0,
    'missing_file' => 0,
];
$findings = [];

foreach ($targets as $relativePath => $rule) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($absolutePath)) {
        $summary['missing_file']++;
        $findings[] = [
            'file' => $relativePath,
            'risk' => 'missing_file',
            'expected' => $rule['expected'],
        ];
        continue;
    }

    $summary['checked']++;
    $contents = (string) file_get_contents($absolutePath);
    $hasSpecificGuard = preg_match('/Auth::require(Capability|AnyCapability|Module|Admin)\s*\(/', $contents) === 1
        || preg_match('/\\\\BIomie\\\\Auth::require(Capability|AnyCapability|Module|Admin)\s*\(/', $contents) === 1
        || preg_match('/->requireAny\s*\(/', $contents) === 1;
    $hasLoginOnly = preg_match('/Auth::requireLogin\s*\(/', $contents) === 1;

    if ($hasSpecificGuard) {
        $summary['protected']++;
        continue;
    }

    if ($hasLoginOnly) {
        if (($rule['allow_login_only'] ?? false) === true) {
            $summary['protected']++;
            continue;
        }

        $summary['login_only']++;
        $findings[] = [
            'file' => $relativePath,
            'risk' => 'login_only_or_missing_specific_permission',
            'expected' => $rule['expected'],
        ];
        continue;
    }

    $summary['missing']++;
    $findings[] = [
        'file' => $relativePath,
        'risk' => 'missing_server_side_authorization',
        'expected' => $rule['expected'],
    ];
}

$result = [
    'ok' => $summary['login_only'] === 0 && $summary['missing'] === 0 && $summary['missing_file'] === 0,
    'summary' => $summary,
    'findings' => $findings,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

