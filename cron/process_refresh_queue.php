<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Support\TenantContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$factory = new OmieClientFactory($pdo, $logger, $config, $cache);
$limit = max(1, (int) ($argv[1] ?? 5));
$processed = 0;

$lock = CronLock::acquire($pdo, 'biomie:refresh_queue', $logger);
if ($lock === null) {
    exit(0);
}

try {
    while ($processed < $limit && ($job = $queue->claimNext()) !== null) {
        try {
            $service = (string) ($job['service'] ?? '');
            $payload = (array) ($job['payload'] ?? []);
            $rawCompanyId = $payload['company_id'] ?? null;
            if (
                (!is_int($rawCompanyId) && !is_string($rawCompanyId))
                || preg_match('/^[1-9]\d*$/', (string) $rawCompanyId) !== 1
            ) {
                throw new RuntimeException(
                    'Job rejeitado: company_id explicito e positivo e obrigatorio.'
                );
            }
            $companyId = (int) $rawCompanyId;
            $canal = strtolower(trim((string) ($payload['canal'] ?? '')));
            if (!in_array($canal, ['varejo', 'licitacao'], true)) {
                throw new RuntimeException(
                    'Job rejeitado: canal explicito deve ser varejo ou licitacao.'
                );
            }
            TenantContext::bootForCompany($pdo, $companyId, $canal);

            $start = (string) ($payload['start'] ?? date('Y-m-01'));
            $end = (string) ($payload['end'] ?? date('Y-m-t'));
            $client = $factory->forCompany($companyId);
            $bronze = new BronzeRepository($pdo, $logger, $companyId);
            $salesRepository = new OmieRepository(
                $client,
                $config,
                $bronze,
                'refresh-queue'
            );

            if ($service === 'sales') {
                $sales = new SalesAnalyticsService(
                    $config,
                    $cache,
                    $logger,
                    $salesRepository,
                    $queue
                );
                $snapshot = $sales->getSnapshot($start, $end, true);
            } elseif ($service === 'finance') {
                $financeRepository = new FinanceRepository(
                    $client,
                    $config,
                    $logger,
                    $cache,
                    $bronze,
                    'refresh-queue'
                );
                $finance = new FinanceAnalyticsService(
                    $config,
                    $cache,
                    $logger,
                    $financeRepository,
                    $salesRepository,
                    $queue
                );
                $snapshot = $finance->getSnapshot($start, $end, true);
            } else {
                throw new RuntimeException('Serviço não suportado: ' . $service);
            }

            $queue->complete($job, [
                'mode' => $snapshot['mode'] ?? 'live',
                'company_id' => $companyId,
                'canal' => $canal,
                'generated_at' => date(DATE_ATOM),
            ]);
            echo sprintf(
                "[%s] company=%d canal=%s %s %s..%s OK\n",
                date('H:i:s'),
                $companyId,
                $canal,
                $service,
                $start,
                $end
            );
        } catch (Throwable $exception) {
            $queue->fail($job, $exception->getMessage());
            echo sprintf("[%s] FALHA: %s\n", date('H:i:s'), $exception->getMessage());
        }
        $processed++;
    }

    if ($processed === 0) {
        echo sprintf("[%s] Fila vazia\n", date('H:i:s'));
    }
} finally {
    $lock->release();
}
