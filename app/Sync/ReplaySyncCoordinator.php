<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\Config;
use BIomie\FinanceAnalyticsService;
use BIomie\Logger;
use BIomie\OmieFinanceCanonicalSyncService;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieOrderEventSyncService;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\SyncDeltaWindowPlanner;
use BIomie\SyncPipelineRepository;
use BIomie\Telemetry;
use DateTimeImmutable;

/**
 * Coordenador de replay, delta, reconcile e backfill por domínio.
 *
 * Extrai a lógica de orquestração de janelas do OmieRealtimeSyncService.
 * Injeta as mesmas dependências de serviço mas delega execução de domínio
 * aos coordinadores específicos (via facades dos serviços legados).
 */
final class ReplaySyncCoordinator
{
    private const RULE_VERSION = 'phase4-v1';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SyncPipelineRepository $pipeline,
        private readonly ?OmieFiscalSyncService $fiscalSync = null,
        private readonly ?OmieOrderEventSyncService $eventSync = null,
        private readonly ?OmieFinanceCanonicalSyncService $financeSync = null,
        private readonly ?FinanceAnalyticsService $financeAnalytics = null,
        private readonly ?RefreshQueue $refreshQueue = null,
    ) {
    }

    /**
     * @param list<string> $domains
     * @return array{ok:bool,mode:string,processed:int,errors:int,results:list<array<string,mixed>>}
     */
    public function runDelta(array $domains = [], ?DateTimeImmutable $now = null): array
    {
        $telemetry = Telemetry::global();
        $span      = $telemetry?->startSpan('sync.delta', ['domains' => $domains], 'sync', 'realtime_pipeline');
        $now     ??= new DateTimeImmutable('now');
        $results   = [];
        $errors    = 0;

        foreach ($this->buildDeltaGroups($domains, $now) as $group) {
            try {
                $results[] = $this->runDomainWindow(
                    (string) $group['domain'],
                    (array) $group['profile'],
                    (array) $group['cursors'],
                    $group['start_at'],
                    $group['end_at'],
                    'delta',
                    'cron-delta'
                );
            } catch (\Throwable $exception) {
                $errors++;
                $results[] = [
                    'domain'  => $group['domain'],
                    'status'  => 'failed_terminal',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $result = [
            'ok'        => $errors === 0,
            'mode'      => 'delta',
            'processed' => count($results),
            'errors'    => $errors,
            'results'   => $results,
        ];
        $telemetry?->recordMetric('sync_window_total', count($results), 'counter', [
            'run_type' => 'delta',
            'status'   => $errors === 0 ? 'ok' : 'error',
        ], 'sync', 'realtime_pipeline');
        $span?->finish($errors === 0 ? 'ok' : 'error', [
            'processed' => count($results),
            'errors'    => $errors,
        ]);

        return $result;
    }

    /**
     * @param list<string> $domains
     * @return array{ok:bool,mode:string,processed:int,errors:int,results:list<array<string,mixed>>}
     */
    public function runReconcile(int $days, array $domains = [], ?DateTimeImmutable $now = null): array
    {
        $now    ??= new DateTimeImmutable('now');
        $startAt  = $now->modify(sprintf('-%d days', max($days, 1)));

        return $this->runFixedWindow('reconcile', 'cron-reconcile', $startAt, $now, $domains);
    }

    /**
     * @param list<string> $domains
     * @return array{ok:bool,mode:string,processed:int,errors:int,results:list<array<string,mixed>>}
     */
    public function runBackfill(string $start, string $end, array $domains = []): array
    {
        $startAt = new DateTimeImmutable($start . ' 00:00:00');
        $endAt   = new DateTimeImmutable($end   . ' 23:59:59');

        return $this->runFixedWindow('backfill', 'cron-backfill', $startAt, $endAt, $domains);
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $cursors
     * @return array<string,mixed>
     */
    public function runDomainWindow(
        string $domain,
        array $profile,
        array $cursors,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        string $runType,
        string $source,
    ): array {
        $telemetry = Telemetry::global();
        $span      = $telemetry?->startSpan('sync.domain_window', [
            'domain'   => $domain,
            'run_type' => $runType,
            'source'   => $source,
            'start_at' => $startAt->format(DATE_ATOM),
            'end_at'   => $endAt->format(DATE_ATOM),
        ], 'sync', 'realtime_pipeline');

        $cursorTargets = $cursors !== [] ? $cursors : [[
            'domain_name'    => $domain,
            'endpoint_group' => $domain,
        ]];
        $endpointGroup = count($cursorTargets) === 1
            ? (string) ($cursorTargets[0]['endpoint_group'] ?? $domain)
            : 'aggregated';

        $runId     = $this->pipeline->startRun($runType, $domain, $endpointGroup, [
            'trigger_source' => $source,
            'period_start'   => $startAt->format('Y-m-d H:i:s'),
            'period_end'     => $endAt->format('Y-m-d H:i:s'),
            'cursor_count'   => count($cursorTargets),
        ]);
        $startedAt = new DateTimeImmutable('now');

        foreach ($cursorTargets as $cursor) {
            $this->pipeline->upsertCursor($domain, (string) ($cursor['endpoint_group'] ?? $domain), [
                'last_run_started_at' => $startedAt->format('Y-m-d H:i:s'),
                'status'              => 'running',
                'rule_version'        => self::RULE_VERSION,
            ]);
        }

        try {
            $result     = $this->executeDomain($domain, $startAt, $endAt, $source);
            $status     = (string) ($result['status'] ?? 'completed');
            $finishedAt = new DateTimeImmutable('now');

            foreach ($cursorTargets as $cursor) {
                $values = [
                    'last_run_finished_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'last_success_at'      => $finishedAt->format('Y-m-d H:i:s'),
                    'status'               => $status,
                    'last_error'           => null,
                    'rule_version'         => self::RULE_VERSION,
                    'cursor_meta'          => [
                        'last_window_start' => $startAt->format('Y-m-d H:i:s'),
                        'last_window_end'   => $endAt->format('Y-m-d H:i:s'),
                        'last_run_type'     => $runType,
                    ],
                ];

                if ($runType === 'delta') {
                    $values['repair_from']   = null;
                    $values['repair_to']     = null;
                    $values['next_delta_at'] = $finishedAt
                        ->modify(sprintf('+%d minutes', (int) ($profile['delta_cadence_minutes'] ?? 5)))
                        ->format('Y-m-d H:i:s');
                }

                $this->pipeline->upsertCursor($domain, (string) ($cursor['endpoint_group'] ?? $domain), $values);
            }

            $this->pipeline->finishRun($runId, $status, [
                'items_discovered' => (int) ($result['items_discovered'] ?? count($cursorTargets)),
                'items_processed'  => (int) ($result['items_processed']  ?? count($cursorTargets)),
                'items_failed'     => (int) ($result['items_failed']     ?? 0),
            ] + $result);

            $windowResult = [
                'domain' => $domain,
                'status' => $status,
                'period' => [
                    'start' => $startAt->format('Y-m-d H:i:s'),
                    'end'   => $endAt->format('Y-m-d H:i:s'),
                ],
                'result' => $result,
            ];
            $telemetry?->recordMetric('sync_run_duration_ms', $this->durationBetween($startedAt, $finishedAt), 'histogram', [
                'domain'   => $domain,
                'run_type' => $runType,
                'status'   => $status,
            ], 'sync', 'realtime_pipeline');
            $span?->finish($status, [
                'cursor_count'    => count($cursorTargets),
                'status'          => $status,
                'items_processed' => $result['items_processed'] ?? count($cursorTargets),
            ]);

            return $windowResult;
        } catch (\Throwable $exception) {
            $retryable     = $this->isRetryable($exception);
            $failureStatus = $retryable ? 'failed_retryable' : 'failed_terminal';
            $finishedAt    = new DateTimeImmutable('now');

            foreach ($cursorTargets as $cursor) {
                $values = [
                    'last_run_finished_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'status'               => $failureStatus,
                    'last_error'           => $exception->getMessage(),
                    'rule_version'         => self::RULE_VERSION,
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
                'items_processed'  => 0,
                'items_failed'     => count($cursorTargets),
            ], $exception->getMessage());
            $telemetry?->recordEvent('ERROR', 'sync.domain_window.error', 'Falha em janela de sync por dominio.', [
                'domain'   => $domain,
                'run_type' => $runType,
                'source'   => $source,
                'message'  => $exception->getMessage(),
            ], 'sync', 'realtime_pipeline');
            $telemetry?->recordMetric('sync_run_duration_ms', $this->durationBetween($startedAt, $finishedAt), 'histogram', [
                'domain'   => $domain,
                'run_type' => $runType,
                'status'   => $failureStatus,
            ], 'sync', 'realtime_pipeline');
            $span?->finish('error', [
                'cursor_count' => count($cursorTargets),
                'status'       => $failureStatus,
                'message'      => $exception->getMessage(),
            ]);

            throw $exception;
        }
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
                throw new \RuntimeException('Pipeline comercial sem coordenadores configurados.');
            }

            $start        = $startAt->format('Y-m-d');
            $end          = $endAt->format('Y-m-d');
            $fiscalResult = $this->fiscalSync->syncPeriod($start, $end, $source);
            $eventResult  = $this->eventSync->syncPeriod(
                $start,
                $end,
                $source,
                (int) ($fiscalResult['vendors_synced'] ?? 0)
            );

            return [
                'status'           => 'completed',
                'handler'          => 'coordinator',
                'items_discovered' => 1,
                'items_processed'  => 1,
                'items_failed'     => 0,
                'period'           => ['start' => $start, 'end' => $end],
                'vendors_synced'   => (int) ($fiscalResult['vendors_synced'] ?? 0),
                'invoices'         => $fiscalResult['invoices'] ?? null,
                'pending_orders'   => $fiscalResult['pending_orders'] ?? null,
                'events'           => $eventResult['events'] ?? null,
                'events_checked'   => $eventResult['events_checked'] ?? null,
                'refresh_queue'    => $this->queueRefreshHints($domain, $startAt, $endAt),
            ];
        }

        if ($domain === 'finance') {
            if ($this->financeSync === null || $this->financeAnalytics === null) {
                throw new \RuntimeException('Pipeline financeiro sem coordenadores configurados.');
            }

            $start         = $startAt->format('Y-m-d');
            $end           = $endAt->format('Y-m-d');
            $financeResult = $this->financeSync->syncPeriod($start, $end, $source);
            $snapshot      = $this->financeAnalytics->getSnapshot($start, $end, true);

            return [
                'status'           => 'completed',
                'handler'          => 'finance_coordinator',
                'items_discovered' => 1,
                'items_processed'  => 1,
                'items_failed'     => 0,
                'period'           => ['start' => $start, 'end' => $end],
                'titles'           => $financeResult['titles'] ?? null,
                'titles_checked'   => $financeResult['titles_checked'] ?? 0,
                'divergences'      => $financeResult['divergences'] ?? 0,
                'mode'             => (string) ($snapshot['mode'] ?? 'live'),
                'served_from'      => (string) ($snapshot['meta']['served_from'] ?? 'manual-refresh'),
                'refresh_queue'    => $this->queueRefreshHints($domain, $startAt, $endAt),
            ];
        }

        return [
            'status'           => 'completed_with_warnings',
            'handler'          => 'noop',
            'items_discovered' => 1,
            'items_processed'  => 1,
            'items_failed'     => 0,
            'warnings'         => ['Dominio sem executor implementado nesta fase.'],
        ];
    }

    /**
     * @param list<string> $domains
     * @return list<array{domain:string,profile:array<string,mixed>,cursors:list<array<string,mixed>>,start_at:DateTimeImmutable,end_at:DateTimeImmutable}>
     */
    private function buildDeltaGroups(array $domains, DateTimeImmutable $now): array
    {
        $profiles   = $this->selectedProfiles($domains);
        $cursorRows = $this->pipeline->listCursors(array_keys($profiles));
        $grouped    = array_fill_keys(array_keys($profiles), []);

        foreach ($cursorRows as $row) {
            $domain = $this->normalizeDomain((string) ($row['domain_name'] ?? ''));
            if (isset($grouped[$domain])) {
                $grouped[$domain][] = $row;
            }
        }

        $groups = [];
        foreach ($profiles as $domain => $profile) {
            $cursors = $grouped[$domain] ?? [];
            $window  = SyncDeltaWindowPlanner::resolve($profile, $cursors, $now);
            if ($window === null) {
                continue;
            }

            $groups[] = [
                'domain'   => $domain,
                'profile'  => $profile,
                'cursors'  => $cursors,
                'start_at' => $window['start_at'],
                'end_at'   => $window['end_at'],
            ];
        }

        return $groups;
    }

    /**
     * @param list<string> $domains
     * @return array{ok:bool,mode:string,processed:int,errors:int,results:list<array<string,mixed>>}
     */
    private function runFixedWindow(
        string $runType,
        string $source,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        array $domains,
    ): array {
        $results = [];
        $errors  = 0;

        foreach ($this->selectedProfiles($domains) as $domain => $profile) {
            try {
                $results[] = $this->runDomainWindow($domain, $profile, [], $startAt, $endAt, $runType, $source);
            } catch (\Throwable $exception) {
                $errors++;
                $results[] = [
                    'domain'  => $domain,
                    'status'  => 'failed_terminal',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'ok'        => $errors === 0,
            'mode'      => $runType,
            'processed' => count($results),
            'errors'    => $errors,
            'results'   => $results,
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string,array<string,mixed>>
     */
    private function selectedProfiles(array $domains): array
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

        if ($domains === []) {
            return $profiles;
        }

        $selected = [];
        foreach ($domains as $domain) {
            $normalized            = $this->normalizeDomain($domain);
            $selected[$normalized] = $profiles[$normalized] ?? [
                'supported'             => false,
                'hot_window_minutes'    => 120,
                'delta_cadence_minutes' => 5,
                'safety_window_minutes' => 15,
            ];
        }

        return $selected;
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
                $key = sprintf('finance_snapshot_%s_%s', $range['start'], $range['end']);
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

    private function durationBetween(DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt): int
    {
        $start = (float) $startedAt->format('U.u');
        $end   = (float) $finishedAt->format('U.u');

        return max(0, (int) round(($end - $start) * 1000));
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
