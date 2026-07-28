<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\FinanceProductPaymentMethodBuilder;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));

    $config = new \BIomie\Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados nao configurado.'], 503);
    }

    $pdo = Database::connect($config, $connection);
    $payload = (new FinanceProductPaymentMethodBuilder($pdo))->build($range['start'], $range['end'], $limit);
    $payload['diagnostics']['request_id'] = RequestContext::id();
    Http::json($payload);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    Http::json(['ok' => false, 'message' => 'Erro interno ao cruzar metodos de pagamento e produtos.'], 500);
}
