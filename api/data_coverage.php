<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Monitoring\DataCoverageRepository;
use BIomie\DataPlatform\Monitoring\DataCoverageService;
use BIomie\Http;
use BIomie\Logger;

require __DIR__ . '/bootstrap.php';

// Endpoint protegido por RBAC: admin.view OU logs.view OU jobs.view
if (!Auth::can('admin.view') && !Auth::can('logs.view') && !Auth::can('jobs.view')) {
    Http::json(['ok' => false, 'message' => 'Forbidden'], 403);
}

try {
    $start = (string) Http::query('start', date('Y-m-01', strtotime('-1 month')));
    $end = (string) Http::query('end', date('Y-m-t'));
    $targetMonth = (string) Http::query('month', '');

    $months = [];
    if ($targetMonth !== '') {
        $months[] = $targetMonth;
        $start = "{$targetMonth}-01";
        $end = date('Y-m-t', strtotime($start));
    } else {
        // Build list of months from start to end
        $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $start)->modify('first day of this month');
        $limit = DateTimeImmutable::createFromFormat('Y-m-d', $end);
        while ($cursor <= $limit) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    
    $repository = new DataCoverageRepository($pdo, $logger);
    $service = new DataCoverageService($repository);

    $dbInfo = $service->getDatabaseInfo();

    $startedAt = microtime(true);
    
    $coverage = [];
    foreach ($months as $month) {
        $coverage[$month] = $service->analyzeMonth($month);
    }

    Http::json([
        'ok' => true,
        'database' => [
            'configured' => $connection,
            'selected' => $dbInfo['database'],
            'current_user' => $dbInfo['current_user'],
            'version' => $dbInfo['version'],
        ],
        'period' => [
            'start' => $start,
            'end' => $end,
            'months' => $months,
        ],
        'coverage' => $coverage,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ],
    ]);

} catch (Throwable $e) {
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao consultar cobertura.',
        'error' => $e->getMessage() // Dev mode only, remove in strictly prod if needed, but per prompt we don't hide technical reason from admin
    ], 500);
}
