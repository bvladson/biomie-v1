<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'settings.manage'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(['ok' => false, 'message' => 'Use POST para reprocessar.', 'request_id' => RequestContext::id()], 405);
}

Http::enforceCsrf($logger);

try {
    $raw = file_get_contents('php://input');
    $body = $raw !== false && trim($raw) !== '' ? (json_decode($raw, true) ?: []) : [];

    $start = trim((string) ($_POST['start'] ?? $body['start'] ?? Http::query('start') ?? ''));
    $end = trim((string) ($_POST['end'] ?? $body['end'] ?? Http::query('end') ?? ''));
    if ($start === '' || $end === '') {
        Http::json(['ok' => false, 'message' => 'Informe start e end (YYYY-MM-DD).', 'request_id' => RequestContext::id()], 400);
    }

    foreach (['start' => $start, 'end' => $end] as $name => $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            Http::json(['ok' => false, 'message' => "Parametro {$name} invalido: {$value}", 'request_id' => RequestContext::id()], 400);
        }
    }

    if ($start > $end) {
        Http::json(['ok' => false, 'message' => 'start deve ser anterior ou igual a end.', 'request_id' => RequestContext::id()], 400);
    }

    $months = ((int) substr($end, 0, 4) - (int) substr($start, 0, 4)) * 12
        + ((int) substr($end, 5, 2) - (int) substr($start, 5, 2));
    if ($months > 3) {
        Http::json(['ok' => false, 'message' => 'Maximo de 3 meses por rebuild sincrono. Divida o periodo.', 'request_id' => RequestContext::id()], 400);
    }

    [$pdo, $connectionName] = Database::getGeoConnection($config);
    $service = new GeoPeriodRebuildService($pdo, $logger);
    $user = Auth::currentUser();
    $result = $service->rebuild($start, $end, [
        'assert' => !empty($_POST['assert']) || !empty($body['assert']),
        'dry_run' => !empty($_POST['dry_run']) || !empty($body['dry_run']),
        'force_full_month' => !empty($_POST['force_full_month']) || !empty($body['force_full_month']),
        'allow_open_month' => !empty($_POST['allow_open_month']) || !empty($body['allow_open_month']),
        'actor_user_id' => isset($user['id']) ? (int) $user['id'] : null,
        'source' => 'api/admin_geo_rebuild.php',
    ]);
    $result['request_id'] = RequestContext::id();
    $result['connection'] = $connectionName;

    $logger->info('admin_geo_rebuild: service concluido.', [
        'status' => $result['status'] ?? 'unknown',
        'ok' => (bool) ($result['ok'] ?? false),
        'request_id' => RequestContext::id(),
        'actor' => $user['email'] ?? 'unknown',
    ]);

    if (($result['status'] ?? '') === 'locked') {
        Http::json($result, 423);
    }

    Http::json($result, !empty($result['ok']) ? 200 : 500);
} catch (Throwable $exception) {
    $logger->error('admin_geo_rebuild: erro inesperado.', [
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok' => false,
        'status' => 'failed',
        'message' => 'Erro inesperado ao reprocessar periodo geo.',
        'errors' => [$exception->getMessage()],
        'request_id' => RequestContext::id(),
    ], 500);
}
