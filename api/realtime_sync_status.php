<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\SyncPipelineRepository;

require __DIR__ . '/bootstrap.php';

Auth::requireAdmin(true);

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco nao configurado.'], 500);
    }

    $pipeline = SyncPipelineRepository::boot($config, $logger);
    if ($pipeline === null) {
        Http::json(['ok' => false, 'message' => 'Pipeline operacional indisponivel.'], 500);
    }

    Http::json([
        'ok' => true,
        'pipeline' => $pipeline->summarizeOperationalState(30),
        'sync_defaults' => [
            'dedupe_window_seconds' => (int) $config->get('sync_pipeline.webhook_dedupe_window_seconds', 300),
            'hot_window_minutes' => (array) $config->get('sync_pipeline.hot_window_minutes', []),
            'delta_cadence_minutes' => (array) $config->get('sync_pipeline.delta_cadence_minutes', []),
            'safety_window_minutes' => (array) $config->get('sync_pipeline.safety_window_minutes', []),
            'reconcile_days' => (int) $config->get('sync_pipeline.reconcile_days', 30),
        ],
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao consultar status do pipeline operacional.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao consultar status do pipeline.',
    ], 500);
}
