<?php

declare(strict_types=1);

/**
 * GET api/omie_status.php
 *
 * Retorna status local completo da integração Omie.
 * Não chama a API Omie — usa apenas dados locais.
 * APP_SECRET nunca é retornado. APP_KEY é mascarada.
 */

use BIomie\Database;
use BIomie\Http;
use BIomie\OmieHealthCheckService;
use BIomie\OmieStatusService;
use BIomie\PipelineStatusRepository;
use BIomie\Support\SecretMask;
use BIomie\SyncPipelineRepository;
use BIomie\TableHealthService;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireAnyCapability(['omie.view', 'jobs.view', 'omie.sync'], true);

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json([
            'ok' => false,
            'error' => 'database_not_configured',
            'message' => 'Banco de dados nao configurado.',
        ], 500);
        return;
    }

    $pdo = Database::connect($config, $connection);
    $tableHealth = new TableHealthService($pdo, $logger);

    $pipelineStatus = null;
    if ($tableHealth->tableExists('biomie_pipeline_jobs')) {
        $pipelineStatus = new PipelineStatusRepository($pdo, $logger);
    }

    $syncPipeline = SyncPipelineRepository::boot($config, $logger);

    $healthChecks = new OmieHealthCheckService(
        $config, $pdo, $logger, $tableHealth,
        $pipelineStatus, $syncPipeline,
    );

    $statusService = new OmieStatusService(
        $config, $pdo, $logger, $healthChecks, $tableHealth,
        $pipelineStatus, $syncPipeline,
    );

    Http::json($statusService->getOverview());
} catch (Throwable $e) {
    $logger->error('api/omie_status.php: erro ao gerar status.', [
        'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 200),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'internal_error',
        'message' => 'Nao foi possivel obter o status da integracao agora.',
    ], 500);
}
