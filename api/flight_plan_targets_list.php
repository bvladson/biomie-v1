<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $yearMonth = Http::query('year_month');

    // Se não informado, usa o mês atual
    if ($yearMonth === null || $yearMonth === '') {
        $yearMonth = date('Y-m');
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        Http::json([
            'ok'      => false,
            'message' => 'year_month deve seguir o formato YYYY-MM (ex: 2026-03).',
        ], 400);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => true, 'targets' => [], 'source' => 'unconfigured', 'year_month' => $yearMonth]);
    }

    $pdo        = Database::connect($config, $connection);
    $repository = new TargetsRepository($pdo, $logger);

    if (!$repository->isAvailable()) {
        Http::json(['ok' => true, 'targets' => [], 'source' => 'table_missing', 'year_month' => $yearMonth]);
    }

    $targetsMap = $repository->getTargetsForMonths([$yearMonth]);
    $targets    = array_values($targetsMap);

    Http::json([
        'ok'         => true,
        'targets'    => $targets,
        'source'     => 'mysql',
        'year_month' => $yearMonth,
        'count'      => count($targets),
    ]);

} catch (Throwable $exception) {
    $logger->error('Falha ao listar metas do Plano de Voo.', [
        'message' => $exception->getMessage(),
        'trace'   => $exception->getTraceAsString(),
    ]);

    Http::json([
        'ok'      => false,
        'message' => 'Não foi possível carregar as metas. Tente novamente.',
    ], 500);
}
