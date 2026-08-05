<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\Config;
use BIomie\FinanceAnalyticsService;
use BIomie\Logger;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Support\DatabaseErrorEnvelope;
use BIomie\SyncPipelineRepository;
use BIomie\Telemetry;
use DateTimeImmutable;

/**
 * Processa a fila de webhooks Omie (inbox → cursor de reparo → run audit).
 *
 * Extrai a responsabilidade de processamento de inbox do OmieRealtimeSyncService.
 * Claim → dispatch (atualiza cursor de reparo) → marca processado/falhou.
 * Não invoca sync diretamente — apenas agenda a janela de reparo no cursor.
 */
final class WebhookInboxProcessor
{
    private const RULE_VERSION = 'phase4-v1';

    public function __construct(
        private readonly Config $config,
        private readonly SyncPipelineRepository $pipeline,
        private readonly Logger $logger,
        private readonly ?RefreshQueue $refreshQueue = null,
        // Fase 2 da Agenda: fila para o refresh direcionado por pedido e PDO
        // para resolver o slug da empresa piloto. Ambos OPCIONAIS e no fim da
        // lista — chamadores existentes não mudam. Sem eles, o hook não
        // enfileira nada (falha segura: o pior caso é ficar só com a
        // reconciliação periódica, que é a rede de segurança).
        private readonly ?\BIomie\Queue\JobQueueInterface $jobQueue = null,
        private readonly ?\PDO $quotePdo = null,
    ) {
    }

    /**
     * @param list<string> $domains
     * @return array{processed:int,retryable_failures:int,terminal_failures:int,records:list<array<string,mixed>>}
     */
    public function process(int $limit = 20, array $domains = []): array
    {
        $telemetry     = Telemetry::global();
        $span          = $telemetry?->startSpan('sync.webhook_inbox', [
            'limit'   => $limit,
            'domains' => $domains,
        ], 'sync', 'realtime_pipeline');
        $processed         = 0;
        $retryableFailures = 0;
        $terminalFailures  = 0;
        $records           = [];
        $domainFilters     = $this->normalizeDomainFilters($domains);

        while ($processed < max($limit, 1)) {
            $message = $this->pipeline->claimNextWebhook($domainFilters);
            if ($message === null) {
                break;
            }

            $domain        = $this->normalizeDomain((string) ($message['domain_name'] ?? ''));
            $endpointGroup = trim((string) ($message['endpoint_group'] ?? '')) ?: $domain;
            $runId         = $this->pipeline->startRun('webhook_dispatch', $domain, $endpointGroup, [
                'trigger_source' => 'webhook',
                'correlation_id' => (string) ($message['correlation_id'] ?? ''),
                'period_start'   => (string) ($message['received_at'] ?? null),
                'period_end'     => (string) ($message['received_at'] ?? null),
            ]);

            try {
                $result = $this->dispatchMessage($message);
                $this->pipeline->markWebhookProcessed((int) $message['id'], $result);
                $this->pipeline->finishRun($runId, (string) ($result['status'] ?? 'completed'), [
                    'items_discovered' => 1,
                    'items_processed'  => 1,
                    'items_failed'     => 0,
                ] + $result);
                $records[] = [
                    'inbox_id'       => (int) $message['id'],
                    'domain'         => $domain,
                    'endpoint_group' => $endpointGroup,
                    'status'         => (string) ($result['status'] ?? 'completed'),
                ];
            } catch (\Throwable $exception) {
                $retryable = $this->isRetryable($exception);
                // P2/Etapa 1 — SQLSTATE + código do driver preservados. A
                // política de retry acima permanece exatamente a mesma.
                $captured    = DatabaseErrorEnvelope::capture($exception, [
                    'run_id'         => $runId,
                    'mode'           => 'webhook_dispatch',
                    'domain'         => $domain,
                    'endpoint_group' => $endpointGroup,
                    'inbox_id'       => (int) $message['id'],
                ]);
                $errorDetail = $captured['detail'];
                $messageText = $captured['summary'];
                if ($retryable) {
                    $retryableFailures++;
                    $this->pipeline->markWebhookTemporaryFailure(
                        (int) $message['id'],
                        $messageText,
                        300,
                        ['exception' => $exception::class] + $errorDetail
                    );
                    $this->pipeline->finishRun($runId, 'failed_retryable', [
                        'items_discovered' => 1,
                        'items_processed'  => 0,
                        'items_failed'     => 1,
                        'error_detail'     => $errorDetail,
                    ], $messageText);
                } else {
                    $terminalFailures++;
                    $this->pipeline->markWebhookPermanentFailure(
                        (int) $message['id'],
                        $messageText,
                        ['exception' => $exception::class] + $errorDetail
                    );
                    $this->pipeline->finishRun($runId, 'failed_terminal', [
                        'items_discovered' => 1,
                        'items_processed'  => 0,
                        'items_failed'     => 1,
                        'error_detail'     => $errorDetail,
                    ], $messageText);
                }

                $this->logger->warning('Falha ao despachar webhook Omie.', [
                    'inbox_id'       => $message['id'] ?? null,
                    'domain'         => $domain,
                    'endpoint_group' => $endpointGroup,
                    'retryable'      => $retryable,
                    'message'        => $messageText,
                    'error_detail'   => $errorDetail,
                ]);
            }

            $processed++;
        }

        $telemetry?->recordMetric('webhook_inbox_processed_total', $processed, 'counter', [
            'retryable_failures' => $retryableFailures,
            'terminal_failures'  => $terminalFailures,
        ], 'webhook', 'realtime_pipeline');
        $span?->finish(
            ($terminalFailures > 0 || $retryableFailures > 0) ? 'warning' : 'ok',
            ['processed' => $processed, 'retryable_failures' => $retryableFailures, 'terminal_failures' => $terminalFailures]
        );

        return [
            'processed'          => $processed,
            'retryable_failures' => $retryableFailures,
            'terminal_failures'  => $terminalFailures,
            'records'            => $records,
        ];
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    public function dispatchMessage(array $message): array
    {
        $telemetry     = Telemetry::global();
        $domain        = $this->normalizeDomain((string) ($message['domain_name'] ?? ''));
        $endpointGroup = trim((string) ($message['endpoint_group'] ?? '')) ?: $domain;
        $span          = $telemetry?->startSpan('webhook.dispatch', [
            'domain'         => $domain,
            'endpoint_group' => $endpointGroup,
            'inbox_id'       => $message['id'] ?? null,
        ], 'webhook', 'realtime_pipeline');

        $eventAt       = $this->extractEventAt($message);
        $now           = new DateTimeImmutable('now');
        $profile       = $this->profileFor($domain);
        $safetyMinutes = (int) ($profile['safety_window_minutes'] ?? 15);
        $repairStart   = $eventAt->modify(sprintf('-%d minutes', $safetyMinutes));
        $repairEnd     = $eventAt > $now ? $eventAt : $now;

        $cursor = $this->pipeline->upsertCursor($domain, $endpointGroup, [
            'last_event_at'         => $eventAt->format('Y-m-d H:i:s'),
            'last_source_id'        => (string) ($message['id'] ?? ''),
            'repair_from'           => $repairStart->format('Y-m-d H:i:s'),
            'repair_to'             => $repairEnd->format('Y-m-d H:i:s'),
            'next_delta_at'         => $now->format('Y-m-d H:i:s'),
            'safety_window_minutes' => $safetyMinutes,
            'rule_version'          => self::RULE_VERSION,
            'status'                => $profile['supported'] ? 'completed' : 'completed_with_warnings',
            'last_error'            => null,
            'cursor_meta'           => [
                'last_webhook_id'     => (int) ($message['id'] ?? 0),
                'last_correlation_id' => (string) ($message['correlation_id'] ?? ''),
                'last_candidate_key'  => (string) ($message['candidate_key'] ?? ''),
            ],
        ]);

        $refreshHints = $this->queueRefreshHints($domain, $repairStart, $repairEnd);

        // Agenda de Orçamentos (Fase 2): o evento já foi validado, persistido e
        // classificado — aqui só ENFILEIRA o refresh direcionado. Nenhuma
        // chamada à Omie acontece neste ponto (estamos no worker, e mesmo assim
        // o trabalho real fica para o job).
        $quoteRefresh = $this->queueQuoteAgendaRefresh($message, $domain);

        $result = [
            'status'         => $profile['supported'] ? 'completed' : 'completed_with_warnings',
            'domain'         => $domain,
            'endpoint_group' => $endpointGroup,
            'event_at'       => $eventAt->format(DATE_ATOM),
            'repair_window'  => [
                'start' => $repairStart->format(DATE_ATOM),
                'end'   => $repairEnd->format(DATE_ATOM),
            ],
            'cursor'         => [
                'id'            => isset($cursor['id']) ? (int) $cursor['id'] : null,
                'status'        => (string) ($cursor['status'] ?? 'completed'),
                'next_delta_at' => $cursor['next_delta_at'] ?? null,
            ],
            'refresh_hints'  => $refreshHints,
            'quote_agenda'   => $quoteRefresh,
            'warnings'       => $profile['supported'] ? [] : ['Dominio ainda nao possui executor dedicado no pipeline.'],
        ];
        $telemetry?->recordMetric('webhook_dispatch_total', 1, 'counter', [
            'domain' => $domain,
            'status' => $result['status'],
        ], 'webhook', 'realtime_pipeline');
        $span?->finish('ok', [
            'status'        => $result['status'],
            'refresh_hints' => count($refreshHints),
        ]);

        return $result;
    }

    /**
     * AGENDA DE ORÇAMENTOS (Fase 2) — enfileira o refresh direcionado do pedido.
     *
     * Contrato deliberadamente estreito:
     *  · SÓ enfileira. Nenhuma chamada à Omie aqui — quem consulta é o job.
     *  · SÓ para domínio de PEDIDO já classificado (o evento chega validado e
     *    persistido; este método não valida nada de novo).
     *  · SÓ para a empresa PILOTO, enquanto a flag estiver ligada. Desligada,
     *    devolve o motivo e não toca na fila.
     *  · IDEMPOTENTE: a chave junta empresa+pedido+janela de debounce, então um
     *    burst de eventos do mesmo pedido vira UM job.
     *
     * Falhar aqui nunca pode derrubar o processamento do webhook — o refresh é
     * um acelerador; a reconciliação periódica é a rede de segurança.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private function queueQuoteAgendaRefresh(array $message, string $domain): array
    {
        if (!(bool) $this->config->get('features.quote_agenda_webhook_refresh_enabled', false)) {
            return ['queued' => false, 'reason' => 'flag desligada'];
        }
        if (!in_array($domain, ['commercial', 'orders', 'order', 'pedido', 'pedidos'], true)) {
            return ['queued' => false, 'reason' => 'domínio não é de pedido'];
        }
        if ($this->jobQueue === null) {
            return ['queued' => false, 'reason' => 'fila indisponível'];
        }

        $companyId = (int) ($message['company_id'] ?? 0);
        if ($companyId <= 0) {
            return ['queued' => false, 'reason' => 'empresa não identificada no evento'];
        }

        // Piloto: comparação por SLUG, resolvido a partir do company_id do evento.
        $pilot = strtoupper(trim((string) $this->config->get('features.quote_agenda_webhook_refresh_pilot', '')));
        if ($pilot === '') {
            return ['queued' => false, 'reason' => 'nenhuma empresa piloto definida'];
        }
        $slug = strtoupper($this->companySlugFor($companyId));
        if ($slug === '' || $slug !== $pilot) {
            return ['queued' => false, 'reason' => 'empresa fora do piloto', 'company' => $slug];
        }

        $orderId = $this->extractOrderId($message);
        if ($orderId === null) {
            return ['queued' => false, 'reason' => 'evento sem código de pedido legível'];
        }

        // Janela de debounce no ENFILEIRAMENTO: bursts do mesmo pedido colapsam
        // em um job. O debounce definitivo (que recusa a CHAMADA) vive no
        // QuoteOrderRefresher, com base no log — este é só o primeiro filtro.
        $bucket = (int) floor(time() / \BIomie\QuoteAgenda\QuoteOrderRefresher::DEBOUNCE_SECONDS);

        try {
            $res = $this->jobQueue->enqueue('high', 'quote_agenda.order.refresh', [
                'company_id' => $companyId,
                'omie_order_id' => $orderId,
                'source' => 'webhook',
            ], [
                'idempotency_key' => sprintf(
                    'quote_agenda.order.refresh|company:%d|order:%s|%d',
                    $companyId, $orderId, $bucket
                ),
                'max_attempts' => 3,
            ]);

            return ['queued' => true, 'order_id' => $orderId, 'company' => $slug, 'job' => $res['id'] ?? null];
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao enfileirar refresh da Agenda (não bloqueia o webhook).', [
                'company_id' => $companyId, 'order_id' => $orderId, 'error' => $e->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'falha ao enfileirar'];
        }
    }

    /** Slug da empresa do evento (cacheado no processo). '' = não resolvido. */
    private function companySlugFor(int $companyId): string
    {
        static $cache = [];
        if (array_key_exists($companyId, $cache)) {
            return $cache[$companyId];
        }
        if ($this->quotePdo === null) {
            return $cache[$companyId] = '';
        }
        try {
            $st = $this->quotePdo->prepare('SELECT slug FROM biomie_companies WHERE id = :id LIMIT 1');
            $st->execute(['id' => $companyId]);

            return $cache[$companyId] = (string) ($st->fetchColumn() ?: '');
        } catch (\Throwable) {
            return $cache[$companyId] = '';
        }
    }

    /**
     * Código do pedido no payload do evento. A Omie varia o nome do campo entre
     * tópicos, então tentamos as formas conhecidas — sem inventar.
     *
     * @param array<string,mixed> $message
     */
    private function extractOrderId(array $message): ?string
    {
        $payload = $this->decodeJson($message['payload_json'] ?? null);
        $candidates = [$payload, $payload['event'] ?? null, $payload['dados'] ?? null, $payload['data'] ?? null];

        foreach ($candidates as $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach (['codigo_pedido', 'idPedido', 'id_pedido', 'nCodPed', 'codigo_pedido_integracao'] as $key) {
                $v = trim((string) ($node[$key] ?? ''));
                if ($v !== '' && ctype_digit($v)) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function profileFor(string $domain): array
    {
        $hot     = (array) $this->config->get('sync_pipeline.hot_window_minutes', []);
        $cadence = (array) $this->config->get('sync_pipeline.delta_cadence_minutes', []);
        $safety  = (array) $this->config->get('sync_pipeline.safety_window_minutes', []);

        $profiles = [
            'commercial' => [
                'supported'             => true,
                'hot_window_minutes'    => (int) ($hot['commercial']     ?? 120),
                'delta_cadence_minutes' => (int) ($cadence['commercial'] ?? 5),
                'safety_window_minutes' => (int) ($safety['commercial']  ?? 15),
            ],
            'finance' => [
                'supported'             => true,
                'hot_window_minutes'    => (int) ($hot['finance']        ?? 1440),
                'delta_cadence_minutes' => (int) ($cadence['finance']    ?? 15),
                'safety_window_minutes' => (int) ($safety['finance']     ?? 60),
            ],
        ];

        return $profiles[$domain] ?? [
            'supported'             => false,
            'hot_window_minutes'    => 120,
            'delta_cadence_minutes' => 5,
            'safety_window_minutes' => 15,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function queueRefreshHints(string $domain, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        if ($this->refreshQueue === null) {
            return [];
        }

        $results = [];
        foreach ($this->buildMonthlyRefreshRanges($startAt, $endAt) as $range) {
            if ($domain === 'commercial') {
                $key = SalesAnalyticsService::cacheKey($range['start'], $range['end']);
                $results['sales'][] = $this->refreshQueue->enqueue('sales', $key, $range);
            }

            if ($domain === 'finance') {
                $key = FinanceAnalyticsService::cacheKey($range['start'], $range['end']);
                $results['finance'][] = $this->refreshQueue->enqueue('finance', $key, $range);
            }
        }

        return $results;
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    private function buildMonthlyRefreshRanges(DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $cursor = new DateTimeImmutable($startAt->format('Y-m-01'));
        $limit  = new DateTimeImmutable($endAt->format('Y-m-01'));
        $ranges = [];

        while ($cursor <= $limit) {
            $ranges[] = [
                'start' => $cursor->format('Y-m-01'),
                'end'   => $cursor->format('Y-m-t'),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $ranges;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function extractEventAt(array $message): DateTimeImmutable
    {
        $metadata = $this->decodeJson($message['metadata_json'] ?? null);
        foreach (['event_at', 'event_at_omie', 'timestamp', 'received_at'] as $key) {
            $value = trim((string) ($metadata[$key] ?? ''));
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }

        $payload = $this->decodeJson($message['payload_json'] ?? null);
        foreach (['event_at', 'event_at_omie', 'data_hora', 'datahora', 'dhEvento', 'dt_evento', 'updated_at'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }

        $date = trim((string) ($payload['data'] ?? $payload['dData'] ?? ''));
        $time = trim((string) ($payload['hora'] ?? $payload['hHora'] ?? '00:00:00'));
        if ($date !== '') {
            return new DateTimeImmutable($date . ' ' . $time);
        }

        $receivedAt = trim((string) ($message['received_at'] ?? ''));

        return new DateTimeImmutable($receivedAt !== '' ? $receivedAt : 'now');
    }

    private function decodeJson(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $domains
     * @return list<string>
     */
    private function normalizeDomainFilters(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $value = $this->normalizeDomain($domain);
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function normalizeDomain(string $domain): string
    {
        $value = strtolower(trim($domain));

        return match ($value) {
            'commercial', 'comercial', 'orders', 'order', 'pedido', 'pedidos',
            'order_events', 'eventos', 'events', 'stages', 'etapas', 'fiscal',
            'nf', 'nfe', 'invoices', 'vendors', 'vendedores', 'clients', 'clientes' => 'commercial',
            'finance', 'financeiro', 'financial', 'titles', 'titulos',
            'movements', 'movimentos', 'accounts', 'contas' => 'finance',
            default => $value !== '' ? $value : 'commercial',
        };
    }

    private function isRetryable(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'redundant')
            || str_contains($message, 'timeout')
            || str_contains($message, 'temporar')
            || str_contains($message, '429')
            || str_contains($message, 'connection')
            || str_contains($message, 'lock wait');
    }
}
