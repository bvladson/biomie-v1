<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start = Http::query('start', date('Y-01-01'));
    $end = Http::query('end', date('Y-m-t'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-t');

    $config = new \BIomie\Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $service = new FiscalIntelligenceService(static fn () => $pdo);

    Http::json([
        'ok' => true,
        'period' => ['start' => $start, 'end' => $end],
        'context' => $service->fiscalContext(),
        'spheres' => $service->sphereSummary($start, $end),
    ]);
} catch (\Throwable $exception) {
    Http::json([
        'ok' => false,
        'message' => 'Não foi possível carregar o contexto fiscal.',
    ], 500);
}
