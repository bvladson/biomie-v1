<?php

declare(strict_types=1);

/**
 * POST api/omie_test_connection.php
 *
 * Testa conexão com a API Omie usando chamada leve (ListarClientesResumido).
 * Requer login, permissão e CSRF.
 * Registra resultado em biomie_omie_status_checks.
 * Nunca expõe APP_SECRET.
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\OmieHealthCheckService;
use BIomie\OmieStatusService;
use BIomie\PipelineStatusRepository;
use BIomie\SyncPipelineRepository;
use BIomie\TableHealthService;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('omie.sync', true);

// Somente POST
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Apenas POST permitido.',
    ], 405);
    return;
}

// CSRF validation
$csrfToken = null;

// Tenta ler do body JSON
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && $rawBody !== '') {
    $jsonBody = json_decode($rawBody, true);
    if (is_array($jsonBody)) {
        $csrfToken = $jsonBody['_csrf'] ?? $jsonBody['csrf_token'] ?? null;
    }
}

// Fallback para header
if ($csrfToken === null) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_BIOMIE_CSRF'] ?? null;
}

// Fallback para POST form
if ($csrfToken === null) {
    $csrfToken = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? null;
}

if (!is_string($csrfToken) || !Auth::verifyCsrf($csrfToken)) {
    Http::json([
        'ok' => false,
        'error' => 'csrf_invalid',
        'message' => 'Token de seguranca invalido. Atualize a pagina e tente novamente.',
    ], 403);
    return;
}

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
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

    $currentUser = Auth::currentUser();
    $actorUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;

    $result = $statusService->testConnection($actorUserId);

    Http::json($result, ($result['ok'] ?? false) ? 200 : 502);
} catch (Throwable $e) {
    $logger->error('api/omie_test_connection.php: erro ao testar conexao.', [
        'message' => $e->getMessage(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'internal_error',
        'message' => 'Nao foi possivel testar a conexao agora.',
        'recommended_action' => 'Verifique logs do servidor.',
    ], 500);
}
