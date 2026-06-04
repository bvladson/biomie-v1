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

$files = [
    'resolver' => 'app/DataPlatform/Support/CnaeStatusResolver.php',
    'query' => 'app/DataPlatform/Query/CnaeQuery.php',
    'repo' => 'app/DataPlatform/Repository/CnaeReadRepository.php',
    'model' => 'app/DataPlatform/ReadModel/CnaeReadModel.php',
    'factory' => 'app/DataPlatform/ReadModel/CnaeReadModelFactory.php',
    'coverage_api' => 'api/cnae_coverage.php',
    'ranking_api' => 'api/cnae_ranking.php',
    'opportunities_api' => 'api/cnae_opportunities.php',
    'company_api' => 'api/company_cnae_ranking.php',
    'sales_api' => 'api/sales_by_cnae.php',
];

foreach ($files as $id => $path) {
    $add('file_' . $id, is_file($root . '/' . $path), "{$path} presente");
}

$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    return is_file($file) ? (string) file_get_contents($file) : '';
};

$resolver = $read($files['resolver']);
foreach (['ok', 'missing_cnpj', 'not_applicable_cpf', 'invalid_document', 'unknown'] as $status) {
    $add('status_' . $status, str_contains($resolver, $status), "status {$status} declarado no resolver");
}
$add('cpf_not_error', str_contains($resolver, 'NOT_APPLICABLE_CPF'), 'CPF sem CNAE e tratado como nao aplicavel');
$add('cnpj_missing_bucket', str_contains($resolver, 'MISSING_CNPJ'), 'CNPJ sem CNAE tem bucket proprio');

$contracts = $read('app/DataPlatform/Contracts/SourceContractRegistry.php')
    . "\n" . $read('app/DataPlatform/Contracts/CnaeSourceContracts.php');
foreach (['cnae.coverage.v1', 'cnae.ranking.v1', 'cnae.opportunities.v1', 'cnae.customer_status.v1', 'cnae.geo_distribution.v1'] as $contract) {
    $add('contract_' . str_replace(['.', ':'], '_', $contract), str_contains($contracts, $contract), "contrato {$contract} registrado");
}

foreach (['coverage_api', 'ranking_api', 'opportunities_api', 'company_api', 'sales_api'] as $id) {
    $content = $read($files[$id]);
    $add($id . '_uses_readmodel', str_contains($content, 'CnaeReadModelFactory'), "{$files[$id]} usa CnaeReadModelFactory");
    $add($id . '_source_contract', str_contains($content, 'CnaeSourceContracts'), "{$files[$id]} declara CnaeSourceContracts");
    $add($id . '_no_direct_mart_sql', !str_contains($content, 'mart_vendas_por_cnae_cnpj'), "{$files[$id]} nao consulta mart CNAE diretamente");
    $add($id . '_no_direct_dim_sql', !str_contains($content, 'biomie_cnae_dim'), "{$files[$id]} nao consulta biomie_cnae_dim diretamente");
}

$repo = $read($files['repo']);
$add('repo_uses_prepare', str_contains($repo, '->prepare('), 'Repository usa prepared statements');
$add('repo_has_document_type_expr', str_contains($repo, 'documentTypeExpr'), 'Repository centraliza tipo de documento');
$add('repo_has_status_expr', str_contains($repo, 'statusExpr'), 'Repository centraliza status CNAE');
$add('repo_has_order_allowlist', str_contains($repo, '$map = ['), 'Repository usa allowlist de ordenacao');

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
    echo 'D46 CNAE Semantic Governance: ' . $payload['status'] . PHP_EOL;
    foreach ($checks as $check) {
        echo ($check['pass'] ? '[PASS] ' : '[FAIL] ') . $check['message'] . PHP_EOL;
    }
}

exit($pass ? 0 : 1);
