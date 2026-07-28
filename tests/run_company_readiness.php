<?php

declare(strict_types=1);

use BIomie\CompanyReadinessAuditor;
use BIomie\Config;
use BIomie\Database;

putenv('BIOMIE_ALLOW_DEFAULT_TENANT=1');
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
$result = (new CompanyReadinessAuditor($pdo))->audit();
$companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];

$checks = [
    'five_companies' => count($companies) === 5,
    'unique_company_ids' => count(array_unique(array_map(
        static fn (array $row): int => (int) ($row['company_id'] ?? 0),
        $companies,
    ))) === count($companies),
    'freshness_contract' => count(array_filter(
        $companies,
        static fn (array $row): bool => isset(
            $row['freshness']['domains']['commercial'],
            $row['freshness']['domains']['finance'],
            $row['freshness']['domains']['gold'],
        ),
    )) === count($companies),
    'completeness_contract' => count(array_filter(
        $companies,
        static fn (array $row): bool => isset(
            $row['completeness']['groups']['customers']['document'],
            $row['completeness']['groups']['invoices']['items'],
            $row['completeness']['groups']['finance']['trace'],
            $row['completeness']['groups']['products']['sku'],
        ),
    )) === count($companies),
    'explicit_actions' => count(array_filter(
        $companies,
        static fn (array $row): bool => is_array($row['recommended_actions'] ?? null),
    )) === count($companies),
    'no_critical_companies' => !in_array('critical', array_column($companies, 'status'), true),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'checks' => $checks,
    'company_statuses' => array_column($companies, 'status', 'slug'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
