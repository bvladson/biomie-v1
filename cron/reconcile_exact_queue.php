<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\ExactReconciliationService;
use BIomie\Logger;
use BIomie\Telemetry;

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.reconcile_exact_queue', [
    'domain_name' => 'reconciliation',
    'source_name' => 'cron',
]);

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(2);
}

[$start, $end] = resolvePeriod($argv ?? []);

$pdo = Database::connect($config, $connection);
$lock = CronLock::acquire($pdo, 'biomie:reconcile_exact', $logger);
if ($lock === null) {
    exit(0);
}

try {
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $canonical->ensureFiscalSchema();
    $bronze = new BronzeRepository($pdo, $logger);
    $bronze->ensureSchema();

    $service = new ExactReconciliationService($canonical, $logger, $bronze);
    $result = $service->runPeriod($start, $end);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'period' => ['start' => $start, 'end' => $end],
        'result' => $result,
        'summary' => $canonical->summarizeDivergenceQueue(),
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $logger->error('Falha no cron de reconciliacao exata.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'period_start' => $start,
        'period_end' => $end,
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}

/**
 * @param list<string> $args
 * @return array{0:string,1:string}
 */
function resolvePeriod(array $args): array
{
    $start = null;
    $end = null;
    $days = 30;

    foreach ($args as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (str_starts_with($arg, '--start=')) {
            $start = trim(substr($arg, 8));
            continue;
        }

        if (str_starts_with($arg, '--end=')) {
            $end = trim(substr($arg, 6));
            continue;
        }

        if (str_starts_with($arg, '--days=')) {
            $days = max(1, (int) trim(substr($arg, 7)));
        }
    }

    if ($start !== null && $end !== null && $start !== '' && $end !== '') {
        return [$start, $end];
    }

    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    return [$start, $end];
}
