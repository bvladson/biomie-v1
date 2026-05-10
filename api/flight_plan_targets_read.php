<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

$yearMonth = date('Y-m');

try {
    $yearMonthParam = trim((string) ($_GET['year_month'] ?? ''));
    if ($yearMonthParam !== '' && preg_match('/^\d{4}-\d{2}$/', $yearMonthParam)) {
        $yearMonth = $yearMonthParam;
    } else {
        $start = trim((string) ($_GET['start'] ?? ''));
        $yearMonth = ($start !== '' && strlen($start) >= 7) ? substr($start, 0, 7) : date('Y-m');
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => true, 'vendors' => [], 'year_month' => $yearMonth, 'source' => 'mysql', 'message' => 'Banco não configurado.']);
        return;
    }

    $pdo        = Database::connect($config, $connection);
    $repository = new TargetsRepository($pdo, $logger);

    if (!$repository->isAvailable()) {
        Http::json(['ok' => true, 'vendors' => [], 'year_month' => $yearMonth, 'source' => 'mysql', 'message' => 'Tabela de metas não disponível.']);
        return;
    }

    $targets = $repository->getTargetsForMonths([$yearMonth]);

    Http::json([
        'ok'         => true,
        'vendors'    => array_values($targets),
        'year_month' => $yearMonth,
        'source'     => 'mysql',
    ]);

} catch (Throwable $exception) {
    $logger->warning('flight_plan_targets_read: falha silenciosa.', ['message' => $exception->getMessage()]);
    Http::json(['ok' => true, 'vendors' => [], 'year_month' => $yearMonth, 'source' => 'mysql', 'message' => 'Erro ao consultar metas.']);
}
