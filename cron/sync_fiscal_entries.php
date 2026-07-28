<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Fiscal\Infrastructure\FiscalPurchaseEntrySyncService;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$tenant = TenantCliContext::boot($pdo, $argv ?? []);
$args = $tenant['args'];
$start = (string) ($args[1] ?? date('Y-01-01'));
$end = (string) ($args[2] ?? date('Y-m-t'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
    || $start > $end
) {
    fwrite(
        STDERR,
        "Uso: php cron/sync_fiscal_entries.php [--company=SLUG|ID] [YYYY-MM-DD] [YYYY-MM-DD]\n"
    );
    exit(2);
}

$lock = CronLock::acquire(
    $pdo,
    'biomie:sync_fiscal_entries:company:' . $tenant['company_id'],
    $logger
);
if ($lock === null) {
    exit(0);
}

try {
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache))
        ->forCompany($tenant['company_id']);
    $repository = new OmieRepository(
        $client,
        $config,
        new BronzeRepository($pdo, $logger, $tenant['company_id']),
        'fiscal-entry-cron',
    );
    $result = (new FiscalPurchaseEntrySyncService($pdo, $repository))->sync($start, $end);

    echo json_encode([
        'ok' => true,
        'company_id' => $tenant['company_id'],
        'company_slug' => $tenant['slug'],
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    $lock->release();
}
