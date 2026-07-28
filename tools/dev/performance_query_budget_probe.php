<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\FlightPlanV2Service;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Products\ProductMixReadService;
use BIomie\Modules\Sales\Infrastructure\ProductMixReadRepository;
use BIomie\Observability\RequestPerformanceContext;
use BIomie\Support\TenantContext;
use BIomie\TargetsRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'json', 'enforce']);
$start = (string) ($opts['start'] ?? date('Y-m-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));

try {
    $config = new Config();
    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    TenantContext::bootForCompany($pdo, 1, 'varejo');

    $measurements = [];
    $measurements[] = performanceMeasureQueries($pdo, 'product_mix_first', static function () use ($pdo, $start, $end): array {
        return (new ProductMixReadService(new ProductMixReadRepository($pdo)))->build($start, $end, 100);
    });
    $measurements[] = performanceMeasureQueries($pdo, 'product_mix_second_same_request', static function () use ($pdo, $start, $end): array {
        return (new ProductMixReadService(new ProductMixReadRepository($pdo)))->build($start, $end, 100);
    });

    $canonical = new CanonicalRepository($pdo, $logger);
    $targets = new TargetsRepository($pdo, $logger);
    $measurements[] = performanceMeasureQueries($pdo, 'flight_plan', static function () use ($canonical, $targets, $config, $start, $end): array {
        return (new FlightPlanV2Service(
            $canonical,
            $targets,
            (float) $config->get('flight_plan.saturday_weight', 0.33)
        ))->build($start, $end);
    });

    $budgets = [
        'product_mix_first' => 5,
        'product_mix_second_same_request' => 4,
        'flight_plan' => 8,
    ];
    $violations = [];
    foreach ($measurements as $index => $measurement) {
        $label = (string) ($measurement['label'] ?? '');
        $budget = (int) ($budgets[$label] ?? 0);
        $queries = (int) ($measurement['queries'] ?? 0);
        $measurements[$index]['budget_queries'] = $budget;
        $measurements[$index]['status'] = $budget > 0 && $queries > $budget ? 'over_budget' : 'ok';
        if ($budget > 0 && $queries > $budget) {
            $violations[] = sprintf('%s executou %d consultas; budget=%d', $label, $queries, $budget);
        }
    }

    $payload = [
        'ok' => $violations === [],
        'period' => ['start' => $start, 'end' => $end],
        'connection' => $connection,
        'measurements' => $measurements,
        'violations' => $violations,
        'runtime' => RequestPerformanceContext::snapshot(),
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(isset($opts['enforce']) && $violations !== [] ? 1 : 0);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

/**
 * @param callable():array<string,mixed> $callback
 * @return array<string,mixed>
 */
function performanceMeasureQueries(PDO $pdo, string $label, callable $callback): array
{
    $before = performanceSessionQuestions($pdo);
    $startedAt = microtime(true);
    $result = $callback();
    $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
    $after = performanceSessionQuestions($pdo);

    return [
        'label' => $label,
        // The final SHOW SESSION STATUS is included in $after and removed here.
        'queries' => max(0, $after - $before - 1),
        'elapsed_ms' => $elapsedMs,
        'top_level_keys' => array_keys($result),
    ];
}

function performanceSessionQuestions(PDO $pdo): int
{
    $row = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['Value'] ?? 0);
}
