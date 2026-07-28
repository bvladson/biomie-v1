<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = getopt('', ['json', 'output:']);
$root = dirname(__DIR__, 2);
$files = glob($root . '/api/*.php') ?: [];
sort($files);

$rows = [];
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $name = basename($file, '.php');
    if (in_array($name, ['bootstrap', 'bootstrap_finance', 'quote_agenda_common'], true)) {
        continue;
    }

    preg_match_all(
        "/REQUEST_METHOD[^\\r\\n]*(?:===|!==|==|!=)\\s*['\"]([A-Z]+)['\"]|in_array\\([^\\r\\n]*REQUEST_METHOD[^\\r\\n]*\\[([^\\]]+)\\]/",
        $source,
        $matches
    );
    $methods = [];
    foreach ($matches[1] as $method) {
        if ($method !== '') {
            $methods[] = $method;
        }
    }
    foreach ($matches[2] as $list) {
        preg_match_all("/['\"]([A-Z]+)['\"]/", $list, $listed);
        $methods = array_merge($methods, $listed[1]);
    }
    if ($methods === []) {
        $methods[] = str_contains($source, 'php://input') || str_contains($source, '$_POST')
            ? 'UNDECLARED'
            : 'GET';
    }
    $methods = array_values(array_unique($methods));
    $mutatingMethod = count(array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE', 'UNDECLARED'])) > 0;
    $writeSql = preg_match('/\\b(?:INSERT\\s+INTO|UPDATE\\s+`?[a-z_]|DELETE\\s+FROM|TRUNCATE\\s+TABLE)\\b/i', $source) === 1;
    $mutates = $mutatingMethod && ($writeSql || preg_match('/->(?:save|upsert|create|delete|enqueue|sync|refresh|set|update)\\s*\\(/i', $source) === 1);

    $externalWebhookAuth = str_contains($source, 'resolveWebhookCompanyId')
        && str_contains($source, 'hash_equals($expected, $provided)');
    $auth = preg_match('/Auth::require(?:Login|Capability|AnyCapability|Module)\\s*\\(/', $source) === 1
        || str_contains($source, "require_once __DIR__ . '/bootstrap.php'")
        || str_contains($source, 'require __DIR__ . \'/bootstrap.php\'')
        || $externalWebhookAuth;
    $csrf = preg_match('/(?:Auth::verifyCsrf|Http::enforceCsrf)\\s*\\(|Csrf::|X-CSRF-Token/i', $source) === 1;
    $company = preg_match('/CompanyContext|companyId|company_id|currentCompany|requireCompanyContext/', $source) === 1;
    $seller = preg_match('/SellerVisibility|SellerBinding|seller_scope|sellerIds|seller_id/', $source) === 1;

    $rows[] = [
        'endpoint' => 'api/' . basename($file),
        'methods' => $methods,
        'mutates_data' => $mutates,
        'authentication' => $auth ? 'proven_static' : 'not_proven',
        'csrf' => !$mutates
            ? 'not_applicable'
            : ($externalWebhookAuth ? 'external_webhook_token' : ($csrf ? 'proven_static' : 'review_required')),
        'company_scope' => $company ? 'present_static' : 'review_required',
        'seller_scope' => $seller ? 'present_static' : 'not_applicable_or_review',
        'write_sql_detected' => $writeSql,
    ];
}

$mutating = array_values(array_filter($rows, static fn (array $row): bool => $row['mutates_data']));
$gaps = array_values(array_filter(
    $mutating,
    static fn (array $row): bool => $row['authentication'] !== 'proven_static'
        || !in_array($row['csrf'], ['proven_static', 'external_webhook_token'], true)
        || $row['company_scope'] !== 'present_static'
));
$payload = [
    'ok' => $gaps === [],
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'method' => 'conservative_static_analysis',
    'summary' => [
        'endpoints' => count($rows),
        'mutating_endpoints' => count($mutating),
        'mutating_review_required' => count($gaps),
    ],
    'review_required' => $gaps,
    'endpoints' => $rows,
];

$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($output, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write endpoint security inventory.');
    }
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0); // Inventory findings require review; generation itself is the gate here.
