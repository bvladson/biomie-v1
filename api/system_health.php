<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\SystemHealth\SystemHealthService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['logs.view', 'jobs.view', 'admin.view'], true);

/**
 * Relatório de saúde do sistema BIomie.
 *
 * Executa 8 checks: database, jobs, freshness, semantic, shadow,
 * customer_quality, geo_quality, webhook_inbox.
 *
 * Permissão: logs.view | jobs.view | admin.view
 * Método: GET
 */

$pdo = null;
try {
    [$pdo] = Database::getGeoConnection($config);
} catch (\Throwable) {
    // DB unavailable — checks will report individually
}

try {
    $service = new SystemHealthService($pdo);
    $report  = $service->run();

    Http::json(array_merge(['ok' => true, 'partial' => $pdo === null], $report->toArray(), [
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
        ],
    ]));
} catch (\Throwable $exception) {
    Http::json([
        'ok'         => false,
        'message'    => 'Erro ao executar relatório de saúde do sistema.',
        'request_id' => RequestContext::id(),
    ], 500);
}
