<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalPurchaseEntrySyncService;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.sync', 'omie.sync', 'jobs.run'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(['ok' => false, 'message' => 'Use POST para sincronizar entradas.'], 405);
}

try {
    Http::enforceCsrf($logger);
    $start = Http::query('start', date('Y-01-01'));
    $end = Http::query('end', date('Y-m-t'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
        || $start > $end
    ) {
        Http::json(['ok' => false, 'message' => 'Período inválido.'], 422);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');
    $companyId = TenantContext::companyId();
    $bronze = new BronzeRepository($pdo, $logger, $companyId);
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache))
        ->forCompany($companyId);
    $repository = new OmieRepository($client, $config, $bronze, 'fiscal-entry-sync');

    set_time_limit(300);
    $result = (new FiscalPurchaseEntrySyncService($pdo, $repository))->sync($start, $end);

    Http::json([
        'ok' => true,
        'company_id' => $companyId,
        'message' => sprintf(
            '%d entrada(s) e %d item(ns) atualizados.',
            $result['documents'],
            $result['items'],
        ),
        'result' => $result,
    ]);
} catch (\Throwable $exception) {
    $logger->error('Falha ao sincronizar notas de entrada do Omie.', [
        'company_id' => TenantContext::companyId(),
        'message' => $exception->getMessage(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Falha ao sincronizar notas de entrada do Omie.',
    ], 500);
}
