<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$files = [
    'DIAGNOSTICO.md',
    'composer.json',
    'api/quote_agenda_policy.php',
    'app/Http/RequestInput.php',
    'app/Observability/EndpointMetricsCollector.php',
    'app/QuoteAgenda/QuoteAgendaReadService.php',
    'app/QuoteAgenda/QuoteFollowupChargeService.php',
    'app/QuoteAgenda/QuoteLiveSnapshotService.php',
    'app/Support/OmieDateParser.php',
    'app/Sync/WebhookInboxProcessor.php',
    'config/architecture-complexity-baseline.json',
    'config/asset-budget-baseline.json',
    'config/security-html-allowlist.json',
    'tools/dev/architecture_debt_inventory.php',
    'tools/dev/asset_budget.php',
    'tools/dev/build_manual_deploy_manifest.php',
    'tools/dev/company_id_inventory.php',
    'tools/dev/endpoint_security_inventory.php',
    'tools/dev/frontend_security_inventory.php',
    'tools/dev/generate_runtime_diagnostics.php',
    'tools/dev/retention_dry_run.php',
    'tests/run_request_input_contract.php',
    'tests/run_quote_agenda_policy_cache_contract.php',
];

$entries = [];
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException('Manifest file missing: ' . $file);
    }
    $entries[] = [
        'path' => $file,
        'bytes' => filesize($path),
        'sha256' => hash_file('sha256', $path),
    ];
}
$manifest = [
    'release_id' => 'biomie-local-' . gmdate('Ymd-His'),
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'git_commit' => null,
    'git_status' => 'unavailable_empty_metadata',
    'migration_state' => '80/86',
    'deployment_owner' => 'user',
    'files' => $entries,
];
$output = $root . '/docs/generated/manual-deploy-manifest.json';
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write deploy manifest.');
}
echo $json, PHP_EOL;
