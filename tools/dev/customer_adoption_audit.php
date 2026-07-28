<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$json = in_array('--json', $argv ?? [], true) || in_array('--format=json', $argv ?? [], true);

$targets = [
    'client_portfolio_360' => [
        'file' => 'api/client_portfolio_360.php',
        'legacy_role' => 'portfolio_360',
        'requires' => ['CustomerReadModelFactory', 'CustomerLegacyAdapter', 'customer.profile.v1'],
        'forbid' => ['biomie_clientes_dim', 'biomie_clientes_geo_dim'],
    ],
    'top_buyers_by_geo' => [
        'file' => 'api/top_buyers_by_geo.php',
        'legacy_role' => 'geo_top_buyers',
        'requires' => ['CustomerReadModelFactory', 'CustomerLegacyAdapter', 'customer.profile.v1'],
        'forbid' => ['biomie_clientes_dim'],
    ],
    'geo_territory_buyers' => [
        'file' => 'api/geo_territory_buyers.php',
        'legacy_role' => 'geo_territory_buyers',
        'requires' => ['CustomerReadModelFactory', 'CustomerLegacyAdapter', 'customer.profile.v1'],
        'forbid' => ['biomie_clientes_dim'],
    ],
];

$legacyCandidates = [
    'api/nf_periodo_table.php',
    'api/commercial_dashboard.php',
];

$rawPatterns = [
    '/canViewFullDocuments\s*\?\s*\$documentRaw/',
    '/canViewFullDocuments\s*\?\s*\$doc/',
    '/canViewDocs\s*\)\s*\{\s*return\s+\(string\)\s*\$document/s',
    '/documento_norm\'\s*=>\s*\$documentNorm/',
];

$checks = [];
$migrated = [];
$rawFindings = [];
$directFindings = [];

foreach ($targets as $id => $target) {
    $file = (string) $target['file'];
    $path = $root . '/' . $file;
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $exists = $content !== '';
    $checks[] = ['id' => $id . '_exists', 'pass' => $exists, 'message' => "{$file} existe"];

    $requiredOk = true;
    foreach ((array) $target['requires'] as $needle) {
        $ok = $exists && str_contains($content, (string) $needle);
        $requiredOk = $requiredOk && $ok;
        $checks[] = ['id' => $id . '_requires_' . preg_replace('/[^a-z0-9]+/i', '_', (string) $needle), 'pass' => $ok, 'message' => "{$file} usa {$needle}"];
    }

    $directOk = true;
    foreach ((array) $target['forbid'] as $needle) {
        if ($exists && str_contains($content, (string) $needle)) {
            $directOk = false;
            $directFindings[] = ['api' => $id, 'file' => $file, 'table' => $needle];
        }
    }
    $checks[] = ['id' => $id . '_no_direct_customer_dim', 'pass' => $directOk, 'message' => "{$file} nao consulta dimensoes de cliente diretamente"];

    $rawOk = true;
    foreach ($rawPatterns as $pattern) {
        if ($exists && preg_match($pattern, $content) === 1) {
            $rawOk = false;
            $rawFindings[] = ['api' => $id, 'file' => $file, 'pattern' => $pattern];
        }
    }
    $checks[] = ['id' => $id . '_no_raw_document_payload', 'pass' => $rawOk, 'message' => "{$file} nao possui padrao estatico de documento bruto em payload migrado"];

    $migrated[] = [
        'api' => $id,
        'file' => $file,
        'role' => (string) $target['legacy_role'],
        'uses_customer_readmodel' => $requiredOk,
        'direct_customer_dim' => !$directOk,
        'raw_document_risk' => !$rawOk,
        'status' => $exists && $requiredOk && $directOk && $rawOk ? 'migrated' : 'needs_review',
    ];
}

$legacy = [];
foreach ($legacyCandidates as $file) {
    $path = $root . '/' . $file;
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $legacy[] = [
        'file' => $file,
        'exists' => $content !== '',
        'uses_customer_readmodel' => str_contains($content, 'CustomerReadModel'),
        'direct_customer_dim' => str_contains($content, 'biomie_clientes_dim'),
        'status' => str_contains($content, 'CustomerReadModel') ? 'migrated' : 'legacy',
    ];
}

$pass = count(array_filter($checks, static fn (array $check): bool => !$check['pass'])) === 0;
$payload = [
    'ok' => $pass,
    'status' => $pass ? 'PASS' : 'FAIL',
    'source_contract' => 'customer.profile.v1',
    'phase' => 'CLIENTE.R1.1',
    'checks' => $checks,
    'data' => [
        'migrated_apis' => $migrated,
        'legacy_apis' => $legacy,
        'direct_customer_dim_findings' => $directFindings,
        'raw_document_findings' => $rawFindings,
        'summary' => [
            'migrated_targets' => count(array_filter($migrated, static fn (array $row): bool => $row['status'] === 'migrated')),
            'targets' => count($migrated),
            'legacy_candidates' => count($legacy),
            'direct_customer_dim_findings' => count($directFindings),
            'raw_document_findings' => count($rawFindings),
        ],
    ],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'CLIENTE.R1.1 Customer Adoption: ' . $payload['status'] . PHP_EOL;
    foreach ($migrated as $row) {
        echo '- ' . $row['file'] . ': ' . $row['status'] . PHP_EOL;
    }
}

exit($pass ? 0 : 2);
