<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';

Auth::requireAdmin(true);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Metodo nao permitido. Use POST.'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true) ?? [];

    $csrfToken = Http::headerValue('X-CSRF-Token');
    if (($csrfToken === null || $csrfToken === '') && is_array($body) && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
        $csrfToken = trim($body['csrf_token']);
    }

    if (!Auth::verifyCsrf($csrfToken)) {
        Http::json([
            'ok' => false,
            'message' => 'Sessao do formulario expirada. Recarregue a pagina e tente novamente.',
            'request_id' => RequestContext::id(),
        ], 419);
    }

    if (isset($body['rows']) && is_array($body['rows'])) {
        $rows = $body['rows'];
    } elseif (isset($body['vendor_id'])) {
        $rows = [$body];
    } else {
        $rows = is_array($body) ? $body : [];
    }

    if ($rows === []) {
        throw new InvalidArgumentException('Corpo da requisicao vazio ou formato inesperado.');
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $repository = new TargetsRepository($pdo, $logger);

    if (!$repository->isAvailable()) {
        throw new RuntimeException('A tabela biomie_sales_targets_monthly nao existe.');
    }

    $service = new \BIomie\FlightPlanTargetsService($pdo, $repository, $cache, $logger);
    $result = $service->saveTargetsBatch($rows);

    $currentUser = Auth::currentUser();
    $logger->info('Metas do plano de voo atualizadas.', [
        'user_id' => $currentUser['id'] ?? null,
        'username' => $currentUser['username'] ?? null,
        'saved' => $result['saved'],
        'vendors' => count($rows),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => true,
        'message' => 'Lote de metas salvo com sucesso.',
        'saved' => $result['saved'],
        'months' => $result['months'] ?? [],
        'rows' => $result['rows'] ?? [],
        'request_id' => RequestContext::id(),
    ]);
} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao salvar meta no Plano de Voo.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel salvar as metas. Tente novamente ou contate o administrador.',
        'request_id' => RequestContext::id(),
    ], 500);
}
