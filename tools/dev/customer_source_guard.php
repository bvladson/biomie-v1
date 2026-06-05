<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$json = in_array('--json', $argv ?? [], true);

$checks = [];
$add = static function (string $id, bool $pass, string $message) use (&$checks): void {
    $checks[] = ['id' => $id, 'pass' => $pass, 'message' => $message];
};
$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    return is_file($file) ? (string) file_get_contents($file) : '';
};

$files = [
    'model' => 'app/DataPlatform/ReadModel/CustomerReadModel.php',
    'factory' => 'app/DataPlatform/ReadModel/CustomerReadModelFactory.php',
    'repo' => 'app/DataPlatform/Repository/CustomerReadRepository.php',
    'query' => 'app/DataPlatform/Query/CustomerQuery.php',
    'contracts' => 'app/DataPlatform/Contracts/CustomerSourceContracts.php',
    'identity_resolver' => 'app/DataPlatform/Support/CustomerIdentityResolver.php',
    'document_classifier' => 'app/DataPlatform/Support/CustomerDocumentClassifier.php',
    'quality_resolver' => 'app/DataPlatform/Support/CustomerQualityResolver.php',
    'profile_api' => 'api/customer_profile.php',
    'search_api' => 'api/customer_search.php',
    'quality_api' => 'api/customer_quality.php',
    'opportunities_api' => 'api/customer_opportunities.php',
    'history_api' => 'api/customer_commercial_history.php',
    'probe' => 'tools/dev/customer_readmodel_probe.php',
];

foreach ($files as $id => $path) {
    $add('file_' . $id, is_file($root . '/' . $path), "{$path} presente");
}

$contracts = $read($files['contracts']) . "\n" . $read('app/DataPlatform/Contracts/SourceContractRegistry.php') . "\n" . $read('docs/DATA_SOURCE_CONTRACTS.md');
foreach ([
    'customer.profile.v1',
    'customer.identity.v1',
    'customer.commercial_history.v1',
    'customer.geo_status.v1',
    'customer.cnae_status.v1',
    'customer.quality.v1',
    'customer.search.v1',
    'customer.opportunities.v1',
] as $contract) {
    $add('contract_' . str_replace('.', '_', $contract), str_contains($contracts, $contract), "contrato {$contract} registrado/documentado");
}

$qualityResolver = $read($files['quality_resolver']);
foreach ([
    'complete',
    'missing_document',
    'invalid_document',
    'missing_city',
    'missing_neighborhood',
    'missing_geo',
    'missing_cnae_cnpj',
    'not_applicable_cpf',
    'missing_owner',
    'no_commercial_history',
    'orphan_commercial_history',
] as $status) {
    $add('quality_status_' . $status, str_contains($qualityResolver, $status), "quality status {$status} declarado");
}

$model = $read($files['model']);
$add('model_uses_cnae_status_resolver', str_contains($model, 'CnaeStatusResolver'), 'CustomerReadModel reaproveita status CNAE');
$add('profile_declares_geo_quality', str_contains($model, "'quality' => \$this->geoQuality") || str_contains($model, 'geoQuality('), 'customer.profile.v1 expõe geo.quality');
$add('payload_declares_source_contract', str_contains($model, 'SourceContractRegistry::get(CustomerSourceContracts::PROFILE)'), 'payload declara source_contract via registry');

$repo = $read($files['repo']);
$add('repo_uses_prepare', str_contains($repo, '->prepare('), 'Repository usa prepared statements');
$add('repo_has_order_allowlist', str_contains($repo, '$map = ['), 'Repository usa allowlist de ordenacao');
$add('repo_uses_bi_visibility', str_contains($repo, 'BiVisibilityScope'), 'Repository preserva escopo comercial BiVisibilityScope em NF');
$add('repo_no_select_star', !preg_match('/SELECT\s+\*\s+FROM/i', $repo), 'Repository nao usa SELECT *');

foreach (['profile_api', 'search_api', 'quality_api', 'opportunities_api', 'history_api'] as $id) {
    $content = $read($files[$id]);
    $add($id . '_uses_readmodel', str_contains($content, 'CustomerReadModelFactory'), "{$files[$id]} usa CustomerReadModelFactory");
    $add($id . '_source_contract', str_contains($content, 'CustomerSourceContracts'), "{$files[$id]} declara CustomerSourceContracts");
    $add($id . '_no_direct_client_dim_sql', !str_contains($content, 'biomie_clientes_dim'), "{$files[$id]} nao consulta biomie_clientes_dim diretamente");
    $add($id . '_no_direct_geo_dim_sql', !str_contains($content, 'biomie_clientes_geo_dim'), "{$files[$id]} nao consulta biomie_clientes_geo_dim diretamente");
}

$probeAudit = customerGuardRunProbe($root, 'audit', ['--limit=5']);
$add('customer_audit_pass', (bool) ($probeAudit['ok'] ?? false), 'customer:audit PASS');

$probeShadow = customerGuardRunProbe($root, 'shadow', ['--limit=5']);
$shadowOk = (bool) ($probeShadow['ok'] ?? false);
$shadowChecks = (array) ($probeShadow['checks'] ?? []);
foreach ($shadowChecks as $check) {
    if (is_array($check) && !($check['no_plain_document'] ?? true)) {
        $shadowOk = false;
    }
}
$add('customer_shadow_pass', $shadowOk, 'customer:shadow PASS e sem documento completo no payload publico');

$pass = count(array_filter($checks, static fn (array $check): bool => !$check['pass'])) === 0;
$payload = [
    'ok' => $pass,
    'status' => $pass ? 'PASS' : 'FAIL',
    'checks' => $checks,
    'summary' => [
        'pass' => count(array_filter($checks, static fn (array $check): bool => $check['pass'])),
        'fail' => count(array_filter($checks, static fn (array $check): bool => !$check['pass'])),
    ],
];

if ($json) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo 'D47 Customer Source Governance: ' . $payload['status'] . PHP_EOL;
    foreach ($checks as $check) {
        echo ($check['pass'] ? '[PASS] ' : '[FAIL] ') . $check['message'] . PHP_EOL;
    }
}

exit($pass ? 0 : 1);

/**
 * @param list<string> $args
 * @return array<string,mixed>
 */
function customerGuardRunProbe(string $root, string $mode, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/dev/customer_readmodel_probe.php') . ' --json --mode=' . escapeshellarg($mode);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $output = shell_exec($cmd);
    $decoded = is_string($output) ? json_decode($output, true) : null;
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'probe_json_invalid'];
}
