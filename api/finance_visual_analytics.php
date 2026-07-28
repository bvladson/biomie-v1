<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\FinanceVisualAnalyticsBuilder;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $config = new \BIomie\Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados nao configurado.'], 503);
    }

    $pdo = Database::connect($config, $conn);
    if (!Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        Http::json([
            'ok' => true,
            'period' => ['start' => $range['start'], 'end' => $range['end'], 'reference_date' => date('Y-m-d')],
            'summary' => [],
            'composition' => [],
            'evolution' => [],
            'aging' => [],
            'vendors' => [],
            'clients_risk' => [],
            'governance' => [],
            'action_mix' => [],
            'details' => [],
            'diagnostics' => ['source' => 'table_missing', 'request_id' => RequestContext::id()],
        ]);
    }

    $includeDetails = (string) ($_GET['include_details'] ?? '1') !== '0';
    $builder = new FinanceVisualAnalyticsBuilder($pdo);
    $payload = $builder->build($range['start'], $range['end'], $includeDetails);
    $payload['diagnostics']['request_id'] = RequestContext::id();

    Http::json($payload);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar analytics financeiro visual.'], 500);
}
