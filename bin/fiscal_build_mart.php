<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Modules\Fiscal\Infrastructure\FiscalMartBuilder;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new \BIomie\Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);
$tenant = TenantCliContext::boot($pdo, $argv ?? []);

set_time_limit(600);
ini_set('memory_limit', '512M');

$builder = new FiscalMartBuilder($pdo);

echo "Building mart_fiscal_items for {$tenant['slug']}...\n";
$t = microtime(true);

$result = $builder->build();

$elapsed = round(microtime(true) - $t, 1);
echo json_encode(array_merge($result, [
    'company_id' => $tenant['company_id'],
    'company_slug' => $tenant['slug'],
    'total_elapsed_s' => $elapsed,
]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
