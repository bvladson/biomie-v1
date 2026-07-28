<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\FinanceRepository;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieFinanceCanonicalSyncService;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantCliContext;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.sync_finance', [
    'domain_name' => 'finance',
    'source_name' => 'cron',
]);
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$args = $argv ?? [];
$lock = null;
$factory = null;
$companyId = null;

try {
    $connection = Database::isConfigured($config, 'flight_plan_db')
        ? 'flight_plan_db'
        : (Database::isConfigured($config, 'db') ? 'db' : null);
    if ($connection === null) {
        throw new RuntimeException('Banco de dados nao configurado.');
    }

    $sharedConnection = new SharedDatabaseConnection($config, $connection);
    $pdo = $sharedConnection->current();
    $tenant = TenantCliContext::boot($pdo, $args);
    $companyId = $tenant['company_id'];
    $args = $tenant['args'];
    $start = (string) ($args[1] ?? date('Y-m-01'));
    $end = (string) ($args[2] ?? date('Y-m-t'));

    $lock = CronLock::acquire(
        $pdo,
        'biomie:sync_finance:company:' . $tenant['company_id'],
        $logger
    );
    if ($lock === null) {
        exit(0);
    }

    $reconnect = static fn () => $sharedConnection->recover();
    $factory = new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect);
    $client = $factory->forCompany($tenant['company_id']);
    $bronze = new BronzeRepository(
        $pdo,
        $logger,
        $tenant['company_id'],
        $reconnect,
    );
    $financeRepository = new FinanceRepository(
        $client,
        $config,
        $logger,
        null,
        $bronze,
        'finance-sync'
    );

    $canonical = new CanonicalRepository(
        $pdo,
        $logger,
        $reconnect,
        $tenant['company_id'],
        $tenant['canal']
    );
    $canonical->ensureFiscalSchema();
    $canonicalFinance = new OmieFinanceCanonicalSyncService(
        $financeRepository,
        $canonical,
        $logger
    );

    $financeResult = $canonicalFinance->syncPeriod($start, $end, 'finance-sync');
    $factory->markSuccess($tenant['company_id']);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'mode' => 'canonical-finance-sync',
        'period' => ['start' => $start, 'end' => $end],
        'canonical_finance' => $financeResult,
        'company_id' => $tenant['company_id'],
        'company_slug' => $tenant['slug'],
        'generated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $message = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);
    if ($factory instanceof OmieClientFactory && is_int($companyId)) {
        try {
            $factory->markFailure($companyId);
        } catch (Throwable) {
        }
    }
    $logger->error('Falha no cron financeiro', [
        'message' => $message,
        'trace' => $exception->getTraceAsString(),
    ]);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}
