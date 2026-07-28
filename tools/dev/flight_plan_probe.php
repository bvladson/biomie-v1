<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\FlightPlanV2Service;
use BIomie\Logger;
use BIomie\TargetsRepository;

$options = getopt('', ['start:', 'end:', 'vendor:', 'json']);
$start = trim((string) ($options['start'] ?? date('Y-m-01')));
$end = trim((string) ($options['end'] ?? date('Y-m-t')));
$vendorFilter = isset($options['vendor']) ? trim((string) $options['vendor']) : null;
$json = isset($options['json']);

try {
    $config = new Config();
    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger);

    if (!$canonical->isAvailable()) {
        throw new RuntimeException('Base comercial canônica indisponível.');
    }

    $targets = new TargetsRepository($pdo, $logger);
    $payload = (new FlightPlanV2Service(
        $canonical,
        $targets,
        (float) $config->get('flight_plan.saturday_weight', 0.33)
    ))->build($start, $end, $vendorFilter);

    $vendors = is_array($payload['vendors'] ?? null) ? $payload['vendors'] : [];
    $daily = is_array($payload['daily_sales'] ?? null) ? $payload['daily_sales'] : [];
    $team = is_array($payload['team'] ?? null) ? $payload['team'] : [];
    $dailyTotal = round(array_sum(array_map(
        static fn (array $row): float => (float) ($row['gross_revenue'] ?? 0),
        $daily
    )), 2);
    $teamRealized = round((float) ($team['realized_period'] ?? 0), 2);
    $dailyDelta = round(abs($dailyTotal - $teamRealized), 2);

    $result = [
        'ok' => $dailyDelta <= 0.01,
        'source' => (string) ($payload['source'] ?? 'flight_plan_v2'),
        'connection' => $connection,
        'period' => ['start' => $start, 'end' => $end],
        'summary' => [
            'vendors_returned' => count($vendors),
            'vendors_with_target' => (int) ($team['active_target_vendors'] ?? 0),
            'vendors_without_target' => (int) ($team['vendors_without_target'] ?? 0),
            'realized_total' => $teamRealized,
            'daily_series_total' => $dailyTotal,
            'daily_series_delta' => $dailyDelta,
            'pipeline_total' => round((float) ($team['pipeline_value'] ?? 0), 2),
            'notes_count' => (int) ($team['notes_count'] ?? 0),
        ],
        'vendors' => array_map(static fn (array $vendor): array => [
            'vendor_id' => (string) ($vendor['vendor_id'] ?? ''),
            'vendor_name' => (string) ($vendor['vendor_name'] ?? ''),
            'channel' => (string) ($vendor['vendor_channel'] ?? 'outro'),
            'risk' => (string) ($vendor['risk'] ?? ''),
            'target' => round((float) ($vendor['target_period'] ?? 0), 2),
            'realized' => round((float) ($vendor['realized_period'] ?? 0), 2),
            'pipeline' => round((float) ($vendor['pipeline_value'] ?? 0), 2),
        ], $vendors),
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        printf(
            "[%s] Plano de Voo %s a %s | %d vendedores | realizado R$ %.2f | delta diário R$ %.2f\n",
            $result['ok'] ? 'OK' : 'FALHA',
            $start,
            $end,
            count($vendors),
            $teamRealized,
            $dailyDelta
        );
    }

    exit($result['ok'] ? 0 : 2);
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception->getMessage()];
    echo $json
        ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
        : '[FALHA] ' . $exception->getMessage() . PHP_EOL;
    exit(2);
}
