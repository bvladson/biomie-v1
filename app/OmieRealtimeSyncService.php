<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\SecretMask;
use DateTimeImmutable;

final class OmieRealtimeSyncService
{
    private const RULE_VERSION = 'phase4-v1';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SyncPipelineRepository $pipeline,
        private readonly ?OmieFiscalSyncService $fiscalSync = null,
        private readonly ?OmieOrderEventSyncService $eventSync = null,
        private readonly ?OmieFinanceCanonicalSyncService $financeSync = null,
        private readonly ?FinanceAnalyticsService $finance = null,
        private readonly ?RefreshQueue $refreshQueue = null,
    ) {
    }

    /**
     * @param list<string> $domains
     * @return array{
     *   processed:int,
     *   retryable_failures:int,
     *   terminal_failures:int,
     *   records:list<array<string,mixed>>
     * }
     */
    public function processWebhookInbox(int $limit = 20, array $domains = []): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('sync.webhook_inbox', [
            'limit' => $limit,
            'domains' => $domains,
        ], 'sync', 'realtime_pipeline');
        $processed = 0;
        $retryableFailures = 0;
        $terminalFailures = 0;
        $records = [];

        while ($processed < max($limit, 1)) {
            $message = $this->pipeline->claimNextWebhook($this->normalizeDomainFilters($domains));
            if ($message === null) {
                break;
            }

            $domain = $this->normalizeDomain((string) ($message['domain_name'] ?? ''));
            $endpointGroup = trim((string) ($message['endpoint_group'] ?? '')) ?: $domain;
            $runId = $this->pipeline->startRun('webhook_dispatch', $domain, $endpointGroup, [
                'trigger_source' => 'webhook',
                'correlation_id' => (string) ($message['correlation_id'] ?? ''),
                'period_start' => (string) ($message['received_at'] ?? null),
                'period_end' => (string) ($message['received_at'] ?? null),
            ]);

            try {
                $result = $this->dispatchWebhookMessage($message);
                $this->pipeline->markWebhookProcessed((int) $message['id'], $result);
                $this->pipeline->finishRun($runId, (string) ($result['status'] ?? 'completed'), [
                    'items_discovered' => 1,
                    'items_processed' => 1,
                    'items_failed' => 0,
                ] + $result);
                $records[] = [
                    'inbox_id' => (int) $message['id'],
                    'domain' => $domain,
                    'endpoint_group' => $endpointGroup,
                    'status' => (string) ($result['status'] ?? 'completed'),
                ];
            } catch (\Throwable $exception) {
                $retryable = $this->isRetryable($exception);
                $messageText = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);
                if ($retryable) {
                    $retryableFailures++;
                    $this->pipeline->markWebhookTemporaryFailure(
                        (int) $message['id'],
                        $messageText,
                        300,
                        ['exception' => $exception::class]
                    );
                    $this->pipeline->finishRun($runId, 'failed_retryable', [
                        'items_discovered' => 1,
                        'items_processed' => 0,
                        'items_failed' => 1,
                    ], $messageText);
                } else {
                    $terminalFailures++;
                    $this->pipeline->markWebhookPermanentFailure(
                        (int) $message['id'],
                        $messageText,
                        ['exception' => $exception::class]
                    );
                    $this->pipeline->finishRun($runId, 'failed_terminal', [
                        'items_discovered' => 1,
                        'items_processed' => 0,
                        'items_failed' => 1,
                    ], $messageText);
                }

                $this->logger->warning('Falha ao despachar webhook Omie.', [
                    'inbox_id' => $message['id'] ?? null,
                    'domain' => $domain,
                    'endpoint_group' => $endpointGroup,
                    'retryable' => $retryable,
                    'message' => $messageText,
                ]);
            }

            $processed++;
        }

        $result = [
            'processed' => $processed,
            'retryable_failures' => $retryableFailures,
            'terminal_failures' => $terminalFailures,
            'records' => $records,
        ];
        $telemetry?->recordMetric('webhook_inbox_processed_total', $processed, 'counter', [
            'retryable_failures' => $retryableFailures,
            'terminal_failures' => $terminalFailures,
        ], 'webhook', 'realtime_pipeline');
        $span?->finish(($terminalFailures > 0 || $retryableFailures > 0) ? 'warning' : 'ok', [
            'processed' => $processed,
            'retryable_failures' => $retryableFailures,
            'terminal_failures' => $terminalFailures,
        ]);

        return $result;
    }

    /**
     * @param list<string> $domains
     * @return array{
     *   ok:bool,
     *   mode:string,
     *   processed:int,
     *   errors:int,
     *   results:list<array<string,mixed>>
     * }
     */
    public function runDelta(array $domains = [], ?DateTimeImmutable $now = null, ?string $runId = null): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('sync.delta', [
            'domains' => $domains,
        ], 'sync', 'realtime_pipeline');
        $now ??= new DateTimeImmutable('now');
        $results = [];
        $errors = 0;

        foreach ($this->buildDeltaGroups($domains, $now) as $group) {
            try {
                $results[] = $this->runDomainWindow(
                    (string) $group['domain'],
                    (array) $group['profile'],
                    (array) $group['cursors'],
                    $group['start_at'],
                    $group['end_at'],
                    'delta',
                    'cron-delta',
                    $runId
                );
            } catch (\Throwable $exception) {
                $errors++;
                $results[] = [
                    'domain' => $group['domain'],
                    'status' => 'failed_terminal',
                    'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 500),
                ];
            }
        }

        $result = [
            'ok' => $errors === 0,
            'mode' => 'delta',
            'processed' => count($results),
            'errors' => $errors,
            'results' => $results,
        ];
        $telemetry?->recordMetric('sync_window_total', count($results), 'counter', [
            'run_type' => 'delta',
            'status' => $errors === 0 ? 'ok' : 'error',
        ], 'sync', 'realtime_pipeline');
        $span?->finish($errors === 0 ? 'ok' : 'error', [
            'processed' => count($results),
            'errors' => $errors,
        ]);

        return $result;
    }

    /**
     * @param list<string> $domains
     * @return array{
     *   ok:bool,
     *   mode:string,
     *   processed:int,
     *   errors:int,
     *   results:list<array<string,mixed>>
     * }
     */
    public function runReconcile(int $days, array $domains = [], ?DateTimeImmutable $now = null): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('sync.reconcile', [
            'days' => $days,
            'domains' => $domains,
        ], 'sync', 'realtime_pipeline');
        $now ??= new DateTimeImmutable('now');
        $startAt = $now->modify(sprintf('-%d days', max($days, 1)));

        $result = $this->runFixedWindow('reconcile', 'cron-reconcile', $startAt, $now, $domains);
        $span?->finish(($result['ok'] ?? false) ? 'ok' : 'error', [
            'processed' => $result['processed'] ?? 0,
            'errors' => $result['errors'] ?? 0,
        ]);

        return $result;
    }

    /**
     * @param list<string> $domains
     * @return array{
     *   ok:bool,
     *   mode:string,
     *   processed:int,
     *   errors:int,
     *   results:list<array<string,mixed>>
     * }
     */
    public function runBackfill(string $start, string $end, array $domains = []): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('sync.backfill', [
            'start' => $start,
            'end' => $end,
            'domains' => $domains,
        ], 'sync', 'realtime_pipeline');
        $startAt = new DateTimeImmutable($start . ' 00:00:00');
        $endAt = new DateTimeImmutable($end . ' 23:59:59');

        $result = $this->runFixedWindow('backfill', 'cron-backfill', $startAt, $endAt, $domains);
        $span?->finish(($result['ok'] ?? false) ? 'ok' : 'error', [
            'processed' => $result['processed'] ?? 0,
            'errors' => $result['errors'] ?? 0,
        ]);

        return $result;
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    public function dispatchWebhookMessage(array $message): array
    {
        $telemetry = Telemetry::global();
        $domain = $this->normalizeDomain((string) ($message['domain_name'] ?? ''));
        $endpointGroup = trim((string) ($message['endpoint_group'] ?? '')) ?: $domain;
        $span = $telemetry?->startSpan('webhook.dispatch', [
            'domain' => $domain,
            'endpoint_group' => $endpointGroup,
            'inbox_id' => $message['id'] ?? null,
        ], 'webhook', 'realtime_pipeline');
        $eventAt = $this->extractEventAt($message);
        $now = new DateTimeImmutable('now');
        $profile = $this->profileFor($domain);
        $safetyMinutes = (int) ($profile['safety_window_minutes'] ?? 15);
        $repairStart = $eventAt->modify(sprintf('-%d minutes', $safetyMinutes));
        $repairEnd = $eventAt > $now ? $eventAt : $now;

        $cursor = $this->pipeline->upsertCursor($domain, $endpointGroup, [
            'last_event_at' => $eventAt->format('Y-m-d H:i:s'),
            'last_source_id' => (string) ($message['id'] ?? ''),
            'repair_from' => $repairStart->format('Y-m-d H:i:s'),
            'repair_to' => $repairEnd->format('Y-m-d H:i:s'),
            'next_delta_at' => $now->format('Y-m-d H:i:s'),
            'safety_window_minutes' => $safetyMinutes,
            'rule_version' => self::RULE_VERSION,
            'status' => $profile['supported'] ? 'completed' : 'completed_with_warnings',
            'last_error' => null,
            'cursor_meta' => [
                'last_webhook_id' => (int) ($message['id'] ?? 0),
                'last_correlation_id' => (string) ($message['correlation_id'] ?? ''),
                'last_candidate_key' => (string) ($message['candidate_key'] ?? ''),
            ],
        ]);

        $refreshHints = $this->queueRefreshHints($domain, $repairStart, $repairEnd);

        $result = [
            'status' => $profile['supported'] ? 'completed' : 'completed_with_warnings',
            'domain' => $domain,
            'endpoint_group' => $endpointGroup,
            'event_at' => $eventAt->format(DATE_ATOM),
            'repair_window' => [
                'start' => $repairStart->format(DATE_ATOM),
                'end' => $repairEnd->format(DATE_ATOM),
            ],
            'cursor' => [
                'id' => isset($cursor['id']) ? (int) $cursor['id'] : null,
                'status' => (string) ($cursor['status'] ?? 'completed'),
                'next_delta_at' => $cursor['next_delta_at'] ?? null,
            ],
            'refresh_hints' => $refreshHints,
            'warnings' => $profile['supported'] ? [] : ['Dominio ainda nao possui executor dedicado no pipeline.'],
        ];
        $telemetry?->recordMetric('webhook_dispatch_total', 1, 'counter', [
            'domain' => $domain,
            'status' => (string) ($result['status'] ?? 'completed'),
        ], 'webhook', 'realtime_pipeline');
        $span?->finish('ok', [
            'status' => $result['status'] ?? 'completed',
            'refresh_hints' => count($refreshHints),
        ]);

        return $result;
    }

    /**
     * @param list<string> $domains
     * @return list<array{
     *   domain:string,
     *   profile:array<string,mixed>,
     *   cursors:list<array<string,mixed>>,
     *   start_at:DateTimeImmutable,
     *   end_at:DateTimeImmutable
     * }>
     */
    private function buildDeltaGroups(array $domains, DateTimeImmutable $now): array
    {
        $profiles = $this->selectedProfiles($domains);
        $cursorRows = $this->pipeline->listCursors(array_keys($profiles));
        $grouped = [];

        foreach ($profiles as $domain => $profile) {
            $grouped[$domain] = [];
        }

        foreach ($cursorRows as $row) {
            $domain = $this->normalizeDomain((string) ($row['domain_name'] ?? ''));
            if (!isset($grouped[$domain])) {
                continue;
            }
            $grouped[$domain][] = $row;
        }

        $groups = [];
        foreach ($profiles as $domain => $profile) {
            $cursors = $grouped[$domain] ?? [];
            $window = $this->resolveDeltaWindow($domain, $profile, $cursors, $now);
            if ($window === null) {
                continue;
            }

            $groups[] = [
                'domain' => $domain,
                'profile' => $profile,
                'cursors' => $cursors,
                'start_at' => $window['start_at'],
                'end_at' => $window['end_at'],
            ];
        }

        return $groups;
    }

    /**
     * @param list<string> $domains
     * @return array{
     *   ok:bool,
     *   mode:string,
     *   processed:int,
     *   errors:int,
     *   results:list<array<string,mixed>>
     * }
     */
    private function runFixedWindow(
        string $runType,
        string $source,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        array $domains,
    ): array {
        $results = [];
        $errors = 0;

        foreach ($this->selectedProfiles($domains) as $domain => $profile) {
            try {
                $results[] = $this->runDomainWindow($domain, $profile, [], $startAt, $endAt, $runType, $source);
            } catch (\Throwable $exception) {
                $errors++;
                $results[] = [
                    'domain' => $domain,
                    'status' => 'failed_terminal',
                    'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 500),
                ];
            }
        }

        return [
            'ok' => $errors === 0,
            'mode' => $runType,
            'processed' => count($results),
            'errors' => $errors,
            'results' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $cursors
     * @return array<string,mixed>
     */
    private function runDomainWindow(
        string $domain,
        array $profile,
        array $cursors,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        string $runType,
        string $source,
        ?string $runId = null,
    ): array {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('sync.domain_window', [
            'domain' => $domain,
            'run_type' => $runType,
            'source' => $source,
            'start_at' => $startAt->format(DATE_ATOM),
            'end_at' => $endAt->format(DATE_ATOM),
        ], 'sync', 'realtime_pipeline');
        $cursorTargets = $cursors !== [] ? $cursors : [[
            'domain_name' => $domain,
            'endpoint_group' => $domain,
        ]];
        $endpointGroup = count($cursorTargets) === 1
            ? (string) ($cursorTargets[0]['endpoint_group'] ?? $domain)
            : 'aggregated';
        $runId = $this->pipeline->startRun($runType, $domain, $endpointGroup, [
            'trigger_source' => $source,
            'period_start' => $startAt->format('Y-m-d H:i:s'),
            'period_end' => $endAt->format('Y-m-d H:i:s'),
            'cursor_count' => count($cursorTargets),
            'run_id' => $runId,
        ]);
        $startedAt = new DateTimeImmutable('now');

        foreach ($cursorTargets as $cursor) {
            $this->pipeline->upsertCursor($domain, (string) ($cursor['endpoint_group'] ?? $domain), [
                'last_run_started_at' => $startedAt->format('Y-m-d H:i:s'),
                'status' => 'running',
                'rule_version' => self::RULE_VERSION,
            ]);
        }

        try {
            $result = $this->executeDomain($domain, $startAt, $endAt, $source);
            $status = (string) ($result['status'] ?? 'completed');
            $finishedAt = new DateTimeImmutable('now');

            foreach ($cursorTargets as $cursor) {
                $values = [
                    'last_run_finished_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'last_success_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'status' => $status,
                    'last_error' => null,
                    'rule_version' => self::RULE_VERSION,
                    'cursor_meta' => [
                        'last_window_start' => $startAt->format('Y-m-d H:i:s'),
                        'last_window_end' => $endAt->format('Y-m-d H:i:s'),
                        'last_run_type' => $runType,
                    ],
                ];

                if ($runType === 'delta') {
                    $values['repair_from'] = null;
                    $values['repair_to'] = null;
                    $values['next_delta_at'] = $finishedAt
                        ->modify(sprintf('+%d minutes', (int) ($profile['delta_cadence_minutes'] ?? 5)))
                        ->format('Y-m-d H:i:s');
                }

                $this->pipeline->upsertCursor($domain, (string) ($cursor['endpoint_group'] ?? $domain), $values);
            }

            $this->pipeline->finishRun($runId, $status, [
                'items_discovered' => (int) ($result['items_discovered'] ?? count($cursorTargets)),
                'items_processed' => (int) ($result['items_processed'] ?? count($cursorTargets)),
                'items_failed' => (int) ($result['items_failed'] ?? 0),
            ] + $result);

            $windowResult = [
                'domain' => $domain,
                'status' => $status,
                'period' => [
                    'start' => $startAt->format('Y-m-d H:i:s'),
                    'end' => $endAt->format('Y-m-d H:i:s'),
                ],
                'result' => $result,
            ];
            $telemetry?->recordMetric('sync_run_duration_ms', $this->durationBetween($startedAt, $finishedAt), 'histogram', [
                'domain' => $domain,
                'run_type' => $runType,
                'status' => $status,
            ], 'sync', 'realtime_pipeline');
            $span?->finish($status, [
                'cursor_count' => count($cursorTargets),
                'status' => $status,
                'items_processed' => $result['items_processed'] ?? count($cursorTargets),
            ]);

            return $windowResult;
        } catch (\Throwable $exception) {
            $retryable = $this->isRetryable($exception);
            $failureStatus = $retryable ? 'failed_retryable' : 'failed_terminal';
            $finishedAt = new DateTimeImmutable('now');
            $messageText = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);

            foreach ($cursorTargets as $cursor) {
                $values = [
                    'last_run_finished_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'status' => $failureStatus,
                    'last_error' => $messageText,
                    'rule_version' => self::RULE_VERSION,
                ];

                if ($runType === 'delta') {
                    $values['next_delta_at'] = $finishedAt
                        ->modify(sprintf('+%d minutes', max(1, (int) ($profile['delta_cadence_minutes'] ?? 5))))
                        ->format('Y-m-d H:i:s');
                }

                $this->pipeline->upsertCursor($domain, (string) ($cursor['endpoint_group'] ?? $domain), $values);
            }

            $this->pipeline->finishRun($runId, $failureStatus, [
                'items_discovered' => count($cursorTargets),
                'items_processed' => 0,
                'items_failed' => count($cursorTargets),
            ], $messageText);
            $telemetry?->recordEvent('ERROR', 'sync.domain_window.error', 'Falha em janela de sync por dominio.', [
                'domain' => $domain,
                'run_type' => $runType,
                'source' => $source,
                'message' => $messageText,
            ], 'sync', 'realtime_pipeline');
            $telemetry?->recordMetric('sync_run_duration_ms', $this->durationBetween($startedAt, $finishedAt), 'histogram', [
                'domain' => $domain,
                'run_type' => $runType,
                'status' => $failureStatus,
            ], 'sync', 'realtime_pipeline');
            $span?->finish('error', [
                'cursor_count' => count($cursorTargets),
                'status' => $failureStatus,
                'message' => $messageText,
            ]);

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $cursors
     * @return array{start_at:DateTimeImmutable,end_at:DateTimeImmutable}|null
     */
    private function resolveDeltaWindow(
        string $domain,
        array $profile,
        array $cursors,
        DateTimeImmutable $now,
    ): ?array {
        return SyncDeltaWindowPlanner::resolve($profile, $cursors, $now);
    }

    private function durationBetween(DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt): int
    {
        $start = (float) $startedAt->format('U.u');
        $end = (float) $finishedAt->format('U.u');
        return max(0, (int) round(($end - $start) * 1000));
    }

    /**
     * @return array<string,mixed>
     */
    private function executeDomain(
        string $domain,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        string $source,
    ): array {
        if ($domain === 'commercial') {
            if ($this->fiscalSync === null || $this->eventSync === null) {
                throw new \RuntimeException('Pipeline comercial sem servicos de sync configurados.');
            }

            $start = $startAt->format('Y-m-d');
            $end = $endAt->format('Y-m-d');
            $fiscalResult = $this->fiscalSync->syncPeriod($start, $end, $source);
            $eventResult = $this->eventSync->syncPeriod(
                $start,
                $end,
                $source,
                (int) ($fiscalResult['vendors_synced'] ?? 0)
            );

            return [
                'status' => 'completed',
                'handler' => 'canonical',
                'items_discovered' => 1,
                'items_processed' => 1,
                'items_failed' => 0,
                'period' => ['start' => $start, 'end' => $end],
                'vendors_synced' => (int) ($fiscalResult['vendors_synced'] ?? 0),
                'invoices' => $fiscalResult['invoices'] ?? null,
                'pending_orders' => $fiscalResult['pending_orders'] ?? null,
                'events' => $eventResult['events'] ?? null,
                'events_checked' => $eventResult['events_checked'] ?? null,
                'refresh_queue' => $this->queueRefreshHints($domain, $startAt, $endAt),
            ];
        }

        if ($domain === 'finance') {
            if ($this->finance === null || $this->financeSync === null) {
                throw new \RuntimeException('Pipeline financeiro sem servicos canonicos configurados.');
            }

            $start = $startAt->format('Y-m-d');
            $end = $endAt->format('Y-m-d');
            $financeResult = $this->financeSync->syncPeriod($start, $end, $source);

            // OTIMIZACAO: o snapshot analitico NAO e mais construido de forma sincrona aqui.
            // Ele consome o endpoint financas/resumo/ da Omie (historico de HTTP 500) e faz
            // dezenas de chamadas extras a Omie + escritas de bronze, tornando o sync
            // financeiro pesado, lento e vulneravel a erros de conexao sob concorrencia.
            // Os titulos canonicos — o dado que importa — ja foram sincronizados acima.
            // O snapshot e reconstruido de forma ASSINCRONA pelo worker via refresh_queue
            // (queueRefreshHints abaixo), protegido pelo circuit breaker do resumo.
            $snapshot = ['mode' => 'deferred', 'meta' => ['served_from' => 'async-refresh-queued']];

            return [
                'status' => 'completed',
                'handler' => 'finance_canonical_snapshot',
                'items_discovered' => 1,
                'items_processed' => 1,
                'items_failed' => 0,
                'period' => ['start' => $start, 'end' => $end],
                'titles' => $financeResult['titles'] ?? null,
                'titles_checked' => $financeResult['titles_checked'] ?? 0,
                'divergences' => $financeResult['divergences'] ?? 0,
                'mode' => (string) $snapshot['mode'],
                'served_from' => (string) $snapshot['meta']['served_from'],
                'refresh_queue' => $this->queueRefreshHints($domain, $startAt, $endAt),
            ];
        }

        return [
            'status' => 'completed_with_warnings',
            'handler' => 'noop',
            'items_discovered' => 1,
            'items_processed' => 1,
            'items_failed' => 0,
            'warnings' => ['Dominio sem executor implementado nesta fase.'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profileFor(string $domain): array
    {
        return $this->selectedProfiles([$domain])[$domain] ?? [
            'supported' => false,
            'hot_window_minutes' => 120,
            'delta_cadence_minutes' => 5,
            'safety_window_minutes' => 15,
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string,array<string,mixed>>
     */
    private function selectedProfiles(array $domains): array
    {
        $hot = (array) $this->config->get('sync_pipeline.hot_window_minutes', []);
        $cadence = (array) $this->config->get('sync_pipeline.delta_cadence_minutes', []);
        $safety = (array) $this->config->get('sync_pipeline.safety_window_minutes', []);
        $profiles = [
            'commercial' => [
                'supported' => true,
                'hot_window_minutes' => (int) ($hot['commercial'] ?? 120),
                'delta_cadence_minutes' => (int) ($cadence['commercial'] ?? 5),
                'safety_window_minutes' => (int) ($safety['commercial'] ?? 15),
            ],
            'finance' => [
                'supported' => true,
                'hot_window_minutes' => (int) ($hot['finance'] ?? 1440),
                'delta_cadence_minutes' => (int) ($cadence['finance'] ?? 15),
                'safety_window_minutes' => (int) ($safety['finance'] ?? 60),
            ],
        ];

        if ($domains === []) {
            return $profiles;
        }

        $selected = [];
        foreach ($domains as $domain) {
            $normalized = $this->normalizeDomain($domain);
            if (isset($profiles[$normalized])) {
                $selected[$normalized] = $profiles[$normalized];
                continue;
            }

            $selected[$normalized] = [
                'supported' => false,
                'hot_window_minutes' => 120,
                'delta_cadence_minutes' => 5,
                'safety_window_minutes' => 15,
            ];
        }

        return $selected;
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
            'commercial', 'comercial', 'orders', 'order', 'pedido', 'pedidos', 'order_events', 'eventos', 'events', 'stages', 'etapas', 'fiscal', 'nf', 'nfe', 'invoices', 'vendors', 'vendedores', 'clients', 'clientes' => 'commercial',
            'finance', 'financeiro', 'financial', 'titles', 'titulos', 'movements', 'movimentos', 'accounts', 'contas' => 'finance',
            default => $value !== '' ? $value : 'commercial',
        };
    }

    /**
     * @param array<string,mixed> $message
     */
    private function extractEventAt(array $message): DateTimeImmutable
    {
        $metadata = $this->decodeJsonColumn($message['metadata_json'] ?? null);
        foreach (['event_at', 'event_at_omie', 'timestamp', 'received_at'] as $key) {
            $value = trim((string) ($metadata[$key] ?? ''));
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }

        $payload = $this->decodeJsonColumn($message['payload_json'] ?? null);
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

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonColumn(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
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
        $limit = new DateTimeImmutable($endAt->format('Y-m-01'));
        $ranges = [];

        while ($cursor <= $limit) {
            $ranges[] = [
                'start' => $cursor->format('Y-m-01'),
                'end' => $cursor->format('Y-m-t'),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $ranges;
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
