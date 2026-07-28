<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\SyncPipelineRepository;
use BIomie\Telemetry;
use BIomie\Support\TenantContext;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('http.omie_webhook', [
    'domain_name' => 'webhook',
    'source_name' => 'http',
]);

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Http::json([
            'ok' => false,
            'message' => 'Use POST para publicar webhooks da Omie.',
            'request_id' => RequestContext::id(),
        ], 405);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    try {
        $pdo = Database::connect($config, $connection);
    } catch (\Throwable $dbEx) {
        // DB unavailable (e.g. max_connections_per_hour) — save raw payload to fallback
        // Raw body is redacted unless the global fallback token validates.
        $rawFallback = file_get_contents('php://input');
        $rawFallback = is_string($rawFallback) ? $rawFallback : '';
        $storeRawFallback = canStoreRawWebhookFallback($config);
        $fallbackDir = dirname(__DIR__) . '/storage/webhooks/fallback';
        if (!is_dir($fallbackDir)) {
            @mkdir($fallbackDir, 0750, true);
        }
        $fallbackFile = $fallbackDir . '/' . date('YmdHis') . '_' . RequestContext::id() . '.json';
        $fallbackPayload = [
            'received_at'  => date('c'),
            'request_id'   => RequestContext::id(),
            'request_uri'  => sanitizeWebhookRequestUri((string) ($_SERVER['REQUEST_URI'] ?? '')),
            'query_string' => sanitizeWebhookQueryString((string) ($_SERVER['QUERY_STRING'] ?? '')),
            'body_sha256'  => hash('sha256', $rawFallback),
            'body_bytes'   => strlen($rawFallback),
            'raw_body_redacted' => !$storeRawFallback,
            'db_error'     => $dbEx->getMessage(),
        ];
        if ($storeRawFallback) {
            $fallbackPayload['raw_body'] = $rawFallback;
        }
        @file_put_contents(
            $fallbackFile,
            json_encode(
                $fallbackPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '{}',
            LOCK_EX
        );
        $logger->warning('Webhook salvo em fallback por indisponibilidade de DB.', [
            'file' => basename($fallbackFile),
            'raw_body_stored' => $storeRawFallback,
            'body_bytes' => strlen($rawFallback),
            'error' => $dbEx->getMessage(),
        ]);
        Http::accepted([
            'ok'         => true,
            'message'    => 'Webhook recebido em modo fallback (DB indisponivel).',
            'fallback'   => true,
            'request_id' => RequestContext::id(),
        ]);
    }
    $factory = new OmieClientFactory($pdo, $logger, $config);
    $companyId = resolveWebhookCompanyId($config, $logger, $factory);
    TenantContext::bootForCompany($pdo, $companyId);

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        Http::json([
            'ok' => false,
            'message' => 'Payload vazio no webhook.',
            'request_id' => RequestContext::id(),
        ], 400);
    }

    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : ['raw_body' => $rawBody];
    $headers = collectRequestHeaders();

    $domain = resolveWebhookDomain($payload);
    $endpointGroup = resolveWebhookEndpointGroup($payload, $domain);
    $candidateKey = resolveWebhookCandidateKey($payload);
    $metadata = [
        'correlation_id' => RequestContext::correlationId(),
        'source_app' => resolveWebhookValue('source_app', $payload, ['app', 'aplicativo', 'origin_app']),
        'topic_name' => resolveWebhookValue('topic_name', $payload, ['topic', 'evento', 'event_type']),
        'event_at' => resolveWebhookValue('event_at', $payload, ['event_at', 'event_at_omie', 'timestamp', 'data_hora']),
        'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'request_uri' => sanitizeWebhookRequestUri((string) ($_SERVER['REQUEST_URI'] ?? '')),
        'company_id' => $companyId,
    ];

    $pipeline = SyncPipelineRepository::boot($config, $logger);
    if ($pipeline === null) {
        Http::json([
            'ok' => false,
            'message' => 'Pipeline operacional indisponivel para persistir webhook.',
            'request_id' => RequestContext::id(),
        ], 500);
    }

    $bronze = BronzeRepository::boot($config, $logger);
    $bronze?->captureRecord(
        'webhooks',
        'omie_incoming',
        sanitizeWebhookRequestUri((string) ($_SERVER['REQUEST_URI'] ?? '/api/omie_webhook.php')),
        $endpointGroup,
        $_GET,
        $payload,
        $candidateKey,
        RequestContext::id(),
        $metadata,
        'webhook'
    );

    $result = $pipeline->ingestWebhook(
        $domain,
        $endpointGroup,
        $payload,
        $headers,
        $candidateKey,
        $metadata,
        'webhook',
        (int) $config->get('sync_pipeline.webhook_dedupe_window_seconds', 300)
    );
    $factory->markWebhookReceived($companyId);

    // Notificação in-app (sininho). O evento cru vira algo legível e clicável:
    // "Novo pedido #2115 · R$ 1.199,00" em vez de "Evento Omie recebido".
    // Pedidos/clientes/faturamento saem como prioridade alta; contas a pagar e
    // estoque ficam agrupados como ruído. Defensivo: nunca quebra o webhook.
    try {
        $topicName = trim($metadata['topic_name']);
        $notice = \BIomie\Notifications\OmieEventClassifier::classify($topicName, $payload, $domain);
        (new \BIomie\Notifications\NotificationService($pdo))->push([
            'category' => $notice['category'],
            'severity' => $notice['severity'],
            'priority' => $notice['priority'],
            'icon' => $notice['icon'],
            'title' => $notice['title'],
            'body' => $notice['body'],
            'company_id' => $companyId,
            'source' => 'omie_webhook',
            'link' => $notice['link'],
            'dedupe_key' => $notice['dedupe_key'],
            'dedupe_window_seconds' => $notice['dedupe_window_seconds'],
        ]);
    } catch (\Throwable) {
    }

    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $jobQueue->enqueue('high', 'webhook.inbox.process', [
        'company_id' => $companyId,
        'limit' => 20,
        'domains' => [$domain],
    ], [
        'idempotency_key' => sprintf('webhook.inbox.process|company:%d|%s|%s', $companyId, $domain, date('Y-m-d-H-i')),
        'max_attempts' => 5,
    ]);

    // WEBHOOK-DRIVEN INCREMENTAL (Fase 6.1): além de primar o cursor, dispara um
    // delta real da empresa+domínio. O worker (company-aware) busca na Omie e
    // atualiza o canônico em ≤5min — sem esperar o cron */15. Debounce por
    // empresa+domínio+minuto evita tempestade de chamadas Omie num burst.
    $syncQueueResult = null;
    if (in_array($domain, ['commercial', 'finance'], true)) {
        $syncQueueResult = $jobQueue->enqueue('high', 'omie.delta.sync', [
            'company_id' => $companyId,
            'domains' => [$domain],
        ], [
            'idempotency_key' => sprintf('omie.delta.sync|company:%d|%s|%s', $companyId, $domain, date('Y-m-d-H-i')),
            'max_attempts' => 3,
        ]);
    }
    $telemetry->recordEvent('INFO', 'webhook.accepted', 'Webhook Omie recebido e persistido.', [
        'domain' => $domain,
        'endpoint_group' => $endpointGroup,
        'status' => $result['status'] ?? 'persistido',
        'duplicate' => $result['duplicate'],
        'inbox_id' => $result['inbox_id'],
    ], 'webhook', 'omie_webhook');
    $telemetry->recordMetric('webhook_received_total', 1, 'counter', [
        'domain' => $domain,
        'status' => $result['status'],
        'duplicate' => !empty($result['duplicate']) ? '1' : '0',
    ], 'webhook', 'omie_webhook');

    Http::accepted([
        'ok' => true,
        'message' => $result['duplicate']
            ? 'Webhook recebido e marcado como duplicado recente.'
            : 'Webhook recebido e persistido com sucesso.',
        'domain' => $domain,
        'endpoint_group' => $endpointGroup,
        'inbox' => $result,
        'job_id' => $queueResult['job_id'],
        'status_url' => sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id'])),
        'queue' => $queueResult,
        'sync_job' => $syncQueueResult,
        'request_id' => RequestContext::id(),
    ], [
        'X-Webhook-Status' => (string) $result['status'],
    ]);
} catch (\InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao receber webhook Omie.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao persistir webhook.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function resolveWebhookCompanyId(
    Config $config,
    Logger $logger,
    OmieClientFactory $factory,
): int
{
    $routeSlug = Http::query('company_slug');
    $provided = resolveWebhookProvidedToken();

    if (is_string($provided) && $provided !== '') {
        $companyId = is_string($routeSlug) && $routeSlug !== ''
            ? $factory->resolveWebhookCompanyIdForSlug($routeSlug, $provided)
            : $factory->resolveWebhookCompanyId($provided);
        if ($companyId !== null) {
            return $companyId;
        }
    }

    $expected = trim((string) $config->get('omie.webhook_token', ''));
    if ($expected === '') {
        $appEnv = strtolower(trim((string) $config->get('app.env', getenv('APP_ENV') ?: 'production')));
        $isProduction = in_array($appEnv, ['production', 'prod'], true);
        $allowInsecureLocal = (bool) $config->get('omie.webhook_allow_insecure_local', false);

        if ($isProduction) {
            $logger->error('Webhook Omie recusado: OMIE_WEBHOOK_TOKEN vazio em producao.', [
                'request_id' => RequestContext::id(),
                'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            Http::json([
                'ok' => false,
                'message' => 'Webhook indisponivel por erro de configuracao.',
                'request_id' => RequestContext::id(),
            ], 500);
        }

        if (!$allowInsecureLocal) {
            $logger->warning('Webhook Omie recusado: token vazio e bypass local desativado.', [
                'app_env' => $appEnv,
                'request_id' => RequestContext::id(),
                'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            Http::json([
                'ok' => false,
                'message' => 'Token do webhook nao configurado.',
                'request_id' => RequestContext::id(),
            ], 403);
        }

        $logger->warning('Webhook Omie aceito sem token por bypass local explicito.', [
            'app_env' => $appEnv,
            'request_id' => RequestContext::id(),
            'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        return 1;
    }

    if ($provided === null || $provided === '' || !hash_equals($expected, $provided)) {
        $logger->warning('Webhook Omie recusado: token ausente ou invalido.', [
            'request_id' => RequestContext::id(),
            'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        Http::json([
            'ok' => false,
            'message' => 'Token do webhook invalido.',
            'request_id' => RequestContext::id(),
        ], 401);
    }

    return 1;
}

function resolveWebhookProvidedToken(): ?string
{
    $provided = Http::headerValue('X-Webhook-Token');
    if ($provided === null || $provided === '') {
        $provided = Http::headerValue('X-Omie-Webhook-Token');
    }
    if ($provided === null || $provided === '') {
        $provided = Http::query('token');
    }
    if ($provided === null || $provided === '') {
        $provided = Http::query('path_token');
    }

    return is_string($provided) && $provided !== '' ? $provided : null;
}

function canStoreRawWebhookFallback(Config $config): bool
{
    $expected = trim((string) $config->get('omie.webhook_token', ''));
    $provided = resolveWebhookProvidedToken();

    return $expected !== '' && $provided !== null && hash_equals($expected, $provided);
}

function sanitizeWebhookRequestUri(string $uri): string
{
    if ($uri === '') {
        return '';
    }

    $uri = preg_replace(
        '#(/webhooks/omie/[^/?]+/)[^/?]+#i',
        '$1[redacted]',
        $uri
    ) ?? $uri;

    return preg_replace('/([?&](?:token|path_token)=)[^&]*/i', '$1[redacted]', $uri) ?? $uri;
}

function sanitizeWebhookQueryString(string $queryString): string
{
    if ($queryString === '') {
        return '';
    }

    parse_str($queryString, $params);
    foreach (array_keys($params) as $key) {
        if (in_array(strtolower((string) $key), ['token', 'path_token'], true)) {
            $params[$key] = '[redacted]';
        }
    }

    return http_build_query($params);
}

/**
 * @return array<string,mixed>
 */
function collectRequestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($value) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = $value;
    }

    return $headers;
}

/**
 * @param array<string,mixed> $payload
 */
function resolveWebhookDomain(array $payload): string
{
    $value = resolveWebhookValue('domain', $payload, [
        'domain',
        'dominio',
        'app',
        'aplicativo',
        'tipo',
        'resource',
    ], 'commercial');

    $normalized = strtolower(trim($value));
    return match ($normalized) {
        'commercial', 'comercial', 'orders', 'order', 'pedido', 'pedidos', 'order_events', 'eventos', 'events', 'stages', 'etapas', 'fiscal', 'nf', 'nfe', 'invoices', 'vendors', 'vendedores', 'clients', 'clientes' => 'commercial',
        'finance', 'financeiro', 'financial', 'titles', 'titulos', 'movements', 'movimentos', 'accounts', 'contas' => 'finance',
        default => $normalized !== '' ? $normalized : 'commercial',
    };
}

/**
 * @param array<string,mixed> $payload
 */
function resolveWebhookEndpointGroup(array $payload, string $default): string
{
    return resolveWebhookValue('endpoint_group', $payload, [
        'endpoint_group',
        'endpoint',
        'metodo',
        'method',
        'service',
        'resource',
    ], $default);
}

/**
 * @param array<string,mixed> $payload
 */
function resolveWebhookCandidateKey(array $payload): ?string
{
    $value = resolveWebhookValue('candidate_key', $payload, [
        'candidate_key',
        'codigo_pedido',
        'pedido_id',
        'id',
        'codigo',
        'chave_nfe',
        'numero',
    ]);

    return $value !== '' ? $value : null;
}

/**
 * @param array<string,mixed> $payload
 * @param list<string> $payloadKeys
 */
function resolveWebhookValue(string $queryKey, array $payload, array $payloadKeys, string $default = ''): string
{
    $queryValue = Http::query($queryKey);
    if ($queryValue !== null && $queryValue !== '') {
        return $queryValue;
    }

    foreach ($payloadKeys as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}
