<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\ExactReconciliationService;
use BIomie\Http;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.sync', 'jobs.run'], true);

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco nao configurado.'], 500);
    }

    $pdo = Database::connect($config, $connection);
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $canonical->ensureFiscalSchema();
    $bronze = new BronzeRepository($pdo, $logger);
    $bronze->ensureSchema();
    $service = new ExactReconciliationService($canonical, $logger, $bronze);

    if (!$canonical->isAvailable()) {
        Http::json([
            'ok' => false,
            'message' => 'Tabelas canonicas nao encontradas. Execute database/canonical_sales.sql.',
        ], 500);
    }

    $range = resolveRequestDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Http::json(buildQueuePayload($canonical, $service, $range));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Use GET para consultar ou POST para operar a fila.'], 405);
    }

    Http::enforceCsrf($logger);
    $action = strtolower((string) (requestValue('action') ?? ''));
    if ($action === '') {
        Http::json(['ok' => false, 'message' => 'Acao obrigatoria.'], 400);
    }

    if ($action === 'scan') {
        set_time_limit(300);
        $scan = $service->runPeriod($range['start'], $range['end']);
        $payload = buildQueuePayload($canonical, $service, $range);
        $payload['message'] = 'Varredura de reconciliacao exata concluida.';
        $payload['scan'] = $scan;
        Http::json($payload);
    }

    if ($action === 'update_status') {
        $id = (int) (requestValue('id') ?? 0);
        $status = (string) (requestValue('status') ?? '');
        if ($id <= 0 || $status === '') {
            Http::json(['ok' => false, 'message' => 'Informe id e status para atualizar a fila.'], 400);
        }
        if (!in_array($status, ['aberta', 'reincidente', 'resolvida', 'ignorada_regra'], true)) {
            Http::json(['ok' => false, 'message' => 'Status operacional invalido.'], 400);
        }

        $user = Auth::currentUser();
        $actor = trim((string) ($user['username'] ?? $user['full_name'] ?? 'admin'));
        $note = requestValue('note');

        $canonical->updateDivergenceOperationalStatus($id, $status, $actor, $note);

        $payload = buildQueuePayload($canonical, $service, $range);
        $payload['message'] = 'Status da divergencia atualizado.';
        $payload['updated'] = [
            'id' => $id,
            'status' => $status,
            'actor' => $actor,
        ];
        Http::json($payload);
    }

    Http::json(['ok' => false, 'message' => 'Acao nao suportada.'], 400);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha na fila de reconciliacao.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao processar a fila de reconciliacao.',
    ], 500);
}

/**
 * @return array{start:string,end:string,label:string}
 */
function resolveRequestDateRange(): array
{
    $start = requestValue('start');
    $end = requestValue('end');
    if ($start !== null && $end !== null && $start !== '' && $end !== '') {
        return [
            'start' => $start,
            'end' => $end,
            'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
        ];
    }

    return Http::resolveDateRange();
}

function requestValue(string $key): ?string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value !== '' ? $value : null;
}

/**
 * @return list<string>
 */
function resolveStatuses(): array
{
    $raw = requestValue('statuses');
    if ($raw === null) {
        return ['aberta', 'reincidente'];
    }

    $allowed = ['aberta', 'reincidente', 'resolvida', 'ignorada_regra'];
    $items = array_map('trim', explode(',', $raw));
    $items = array_values(array_filter($items, static fn (string $value): bool => in_array($value, $allowed, true)));

    return $items !== [] ? $items : ['aberta', 'reincidente'];
}

/**
 * @return array<string,mixed>
 */
function buildQueuePayload(
    CanonicalRepository $canonical,
    ExactReconciliationService $service,
    array $range,
): array {
    $statuses = resolveStatuses();
    $severity = requestValue('severity');
    $limit = max(1, min((int) (requestValue('limit') ?? '100'), 500));
    $queue = $canonical->listDivergenceQueue($statuses, $severity, $limit);

    return [
        'ok' => true,
        'period' => $range,
        'filters' => [
            'statuses' => $statuses,
            'severity' => $severity,
            'limit' => $limit,
        ],
        'available_statuses' => ['aberta', 'reincidente', 'resolvida', 'ignorada_regra'],
        'summary' => $canonical->summarizeDivergenceQueue(),
        'catalog' => $service->catalog(),
        'queue_count' => count($queue),
        'queue' => $queue,
    ];
}
