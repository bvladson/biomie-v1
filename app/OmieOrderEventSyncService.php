<?php

declare(strict_types=1);

namespace BIomie;

final class OmieOrderEventSyncService
{
    private const EVENT_SYNC_NAME = 'order_stage_events_sync';
    private const STATE_PHASE = 'chunked';

    public function __construct(
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *   events:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   events_checked:int,
     *   duration_seconds:float
     * }
     */
    public function syncPeriod(string $start, string $end, string $source = 'cron', int $vendorsSynced = 0): array
    {
        $t0 = microtime(true);
        $stats = $this->emptyStats();

        $state = $this->canonical->prepareSyncState(
            $start,
            $end,
            $source,
            self::EVENT_SYNC_NAME,
            self::STATE_PHASE
        );
        $this->canonical->markVendorsSynced(
            $start,
            $end,
            $vendorsSynced,
            $source,
            self::EVENT_SYNC_NAME,
            self::STATE_PHASE
        );

        $current = strtotime($start);
        $last = strtotime($end);
        if ($state && !empty($state['checkpoint_date'])) {
            $checkpointTs = strtotime((string) $state['checkpoint_date']);
            if ($checkpointTs >= $current) {
                $current = $checkpointTs;
            }
        }

        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $checkpointPage = ($state && ($state['checkpoint_date'] ?? null) === $day)
                ? (int) ($state['checkpoint_page'] ?? 1)
                : 1;
            $checkpointBatch = ($state && ($state['checkpoint_date'] ?? null) === $day)
                ? (int) ($state['checkpoint_batch'] ?? 0)
                : 0;

            $this->canonical->advanceOrdersCheckpoint(
                $start,
                $end,
                $day,
                $checkpointPage,
                $checkpointBatch,
                $source,
                $vendorsSynced,
                $stats,
                self::EVENT_SYNC_NAME,
                self::STATE_PHASE
            );

            $attempts = 0;
            $success = false;
            while (!$success && $attempts < 3) {
                try {
                    $dayResult = $this->processDay($day, $source);
                    $stats = $this->mergeStats($stats, $dayResult['stats']);
                    $checkpointBatch = (int) $dayResult['checkpoint_batch'];

                    $nextDay = date('Y-m-d', strtotime('+1 day', $current));
                    if (strtotime($nextDay) <= $last) {
                        $this->canonical->advanceOrdersCheckpoint(
                            $start,
                            $end,
                            $nextDay,
                            1,
                            0,
                            $source,
                            $vendorsSynced,
                            $stats,
                            self::EVENT_SYNC_NAME,
                            self::STATE_PHASE
                        );
                    }

                    $success = true;
                } catch (\RuntimeException $exception) {
                    if (str_contains($exception->getMessage(), 'REDUNDANT')) {
                        $this->logger->warning('Rate limit ao sincronizar eventos de pedido; aplicando backoff.', [
                            'day' => $day,
                            'attempt' => $attempts + 1,
                        ]);
                        if (PHP_SAPI !== 'cli') {
                            $this->logger->warning('Backoff de eventos Omie suprimido fora de CLI; use fila/worker.', [
                                'day' => $day,
                                'attempt' => $attempts + 1,
                            ]);
                            throw $exception;
                        }

                        sleep(10);
                        $attempts++;
                        continue;
                    }

                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorsSynced,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::EVENT_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                } catch (\Throwable $exception) {
                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorsSynced,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::EVENT_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                }
            }

            $current = strtotime('+1 day', $current);
        }

        $this->canonical->markSyncCompleted(
            $start,
            $end,
            $source,
            $vendorsSynced,
            $stats,
            $end,
            1,
            0,
            self::EVENT_SYNC_NAME,
            self::STATE_PHASE
        );

        $duration = round(microtime(true) - $t0, 2);
        $checked = $this->sumStats($stats);

        $this->logger->info('Sync canonico de eventos de pedido finalizado.', [
            'start' => $start,
            'end' => $end,
            'events' => $stats,
            'events_checked' => $checked,
            'duration_seconds' => $duration,
        ]);

        return [
            'events' => $stats,
            'events_checked' => $checked,
            'duration_seconds' => $duration,
        ];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},checkpoint_batch:int}
     */
    private function processDay(string $day, string $source): array
    {
        $stats = $this->emptyStats();
        $rawEvents = $this->omie->listOrderStageEvents($day, $day, '00:00:00', '23:59:59');

        foreach ($rawEvents as $rawEvent) {
            if (!is_array($rawEvent)) {
                continue;
            }

            foreach ($this->normalizeOrderEvents($rawEvent, $source) as $event) {
                $result = $this->canonical->appendCanonicalEvent($event);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            }
        }

        return [
            'stats' => $stats,
            'checkpoint_batch' => count($rawEvents),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeOrderEvents(array $rawEvent, string $source): array
    {
        $events = [];

        $orderId = (int) Support::getByPaths($rawEvent, ['nCodPed'], 0);
        $entityCode = trim((string) Support::getByPaths($rawEvent, ['cNumero'], ''));
        $integrationCode = trim((string) Support::getByPaths($rawEvent, ['cCodIntPed'], ''));
        $stageCode = trim((string) Support::getByPaths($rawEvent, ['cEtapa'], ''));
        $documentKey = trim((string) Support::getByPaths($rawEvent, ['faturamento.cChaveNFE'], ''));
        $documentNumber = trim((string) Support::getByPaths($rawEvent, ['faturamento.cNumNFE'], ''));
        $documentSeries = trim((string) Support::getByPaths($rawEvent, ['faturamento.cSerieNFE'], ''));

        $base = [
            'entity_name' => 'pedido_venda',
            'omie_entity_id' => $orderId > 0 ? $orderId : null,
            'entity_code' => $entityCode !== '' ? $entityCode : null,
            'integration_code' => $integrationCode !== '' ? $integrationCode : null,
            'stage_code' => $stageCode !== '' ? $stageCode : null,
            'document_key' => $documentKey !== '' ? $documentKey : null,
            'document_number' => $documentNumber !== '' ? $documentNumber : null,
            'document_series' => $documentSeries !== '' ? $documentSeries : null,
            'source_payload' => $rawEvent,
            'source' => $source,
        ];

        $events[] = $this->buildEvent(
            $base,
            'pedido_incluido',
            Support::formatDateTime(
                (string) Support::getByPaths($rawEvent, ['info.dInc'], ''),
                (string) Support::getByPaths($rawEvent, ['info.hInc'], '')
            ),
            10,
            trim((string) Support::getByPaths($rawEvent, ['info.uInc'], ''))
        );

        $events[] = $this->buildEvent(
            $base,
            'pedido_etapa_alterada',
            Support::formatDateTime(
                (string) Support::getByPaths($rawEvent, ['dDtEtapa'], ''),
                (string) Support::getByPaths($rawEvent, ['cHrEtapa'], '')
            ),
            20,
            trim((string) Support::getByPaths($rawEvent, ['cUsEtapa'], ''))
        );

        if ($this->isTruthyFlag(Support::getByPaths($rawEvent, ['faturamento.cFaturado'], 'N'))) {
            $events[] = $this->buildEvent(
                $base,
                'pedido_faturado',
                $this->resolveEventDateTime(
                    $rawEvent,
                    ['faturamento.dDtFat', 'dDtEtapa'],
                    ['faturamento.cHrFat', 'cHrEtapa']
                ),
                30,
                trim((string) Support::getByPaths($rawEvent, ['faturamento.cUsFat', 'faturamento.cUsDev', 'cUsEtapa'], ''))
            );
        }

        if ($this->isTruthyFlag(Support::getByPaths($rawEvent, ['faturamento.cAutorizado'], 'N'))) {
            $events[] = $this->buildEvent(
                $base,
                'nfe_autorizada',
                $this->resolveEventDateTime(
                    $rawEvent,
                    ['faturamento.dDtSainda', 'faturamento.dDtFat', 'dDtEtapa'],
                    ['faturamento.cHrSaida', 'faturamento.cHrFat', 'cHrEtapa']
                ),
                40,
                trim((string) Support::getByPaths($rawEvent, ['faturamento.cUsFat', 'faturamento.cUsDev', 'cUsEtapa'], ''))
            );
        }

        if ($this->isTruthyFlag(Support::getByPaths($rawEvent, ['faturamento.cDenegado'], 'N'))) {
            $events[] = $this->buildEvent(
                $base,
                'nfe_denegada',
                $this->resolveEventDateTime(
                    $rawEvent,
                    ['faturamento.dDtFat', 'dDtEtapa'],
                    ['faturamento.cHrFat', 'cHrEtapa']
                ),
                45,
                trim((string) Support::getByPaths($rawEvent, ['faturamento.cUsFat', 'faturamento.cUsDev', 'cUsEtapa'], ''))
            );
        }

        if ($this->isTruthyFlag(Support::getByPaths($rawEvent, ['cancelamento.cCancelado'], 'N'))) {
            $events[] = $this->buildEvent(
                $base,
                'pedido_cancelado',
                Support::formatDateTime(
                    (string) Support::getByPaths($rawEvent, ['cancelamento.dDtCanc'], ''),
                    (string) Support::getByPaths($rawEvent, ['cancelamento.cHrCanc'], '')
                ),
                50,
                trim((string) Support::getByPaths($rawEvent, ['cancelamento.cUsCanc'], ''))
            );
        }

        if ($this->isTruthyFlag(Support::getByPaths($rawEvent, ['devolucao.cDevolvido'], 'N'))) {
            $events[] = $this->buildEvent(
                $base,
                'pedido_devolvido',
                Support::formatDateTime(
                    (string) Support::getByPaths($rawEvent, ['devolucao.dDtDev'], ''),
                    (string) Support::getByPaths($rawEvent, ['devolucao.cHrDev'], '')
                ),
                60,
                trim((string) Support::getByPaths($rawEvent, ['devolucao.cUsDev'], ''))
            );
        }

        return array_values(array_filter($events, static fn (mixed $event): bool => is_array($event)));
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>|null
     */
    private function buildEvent(array $base, string $eventType, ?string $eventAt, int $eventOrder, string $eventUser): ?array
    {
        if ($eventAt === null || $eventAt === '') {
            return null;
        }

        $event = $base;
        $event['event_type'] = $eventType;
        $event['event_at_omie'] = $eventAt;
        $event['event_order'] = $eventOrder;
        $event['event_user'] = $eventUser !== '' ? $eventUser : null;

        return $event;
    }

    /**
     * @param list<string> $datePaths
     * @param list<string> $timePaths
     */
    private function resolveEventDateTime(array $rawEvent, array $datePaths, array $timePaths): ?string
    {
        return Support::formatDateTime(
            (string) Support::getByPaths($rawEvent, $datePaths, ''),
            (string) Support::getByPaths($rawEvent, $timePaths, '')
        );
    }

    private function isTruthyFlag(mixed $value): bool
    {
        $normalized = mb_strtoupper(trim((string) $value));
        return in_array($normalized, ['S', '1', 'Y', 'TRUE'], true);
    }

    /**
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function emptyStats(): array
    {
        return [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $current
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $delta
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function mergeStats(array $current, array $delta): array
    {
        return [
            'inserted' => (int) ($current['inserted'] ?? 0) + (int) ($delta['inserted'] ?? 0),
            'updated' => (int) ($current['updated'] ?? 0) + (int) ($delta['updated'] ?? 0),
            'unchanged' => (int) ($current['unchanged'] ?? 0) + (int) ($delta['unchanged'] ?? 0),
            'skipped' => (int) ($current['skipped'] ?? 0) + (int) ($delta['skipped'] ?? 0),
        ];
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $stats
     */
    private function sumStats(array $stats): int
    {
        return (int) ($stats['inserted'] ?? 0)
            + (int) ($stats['updated'] ?? 0)
            + (int) ($stats['unchanged'] ?? 0)
            + (int) ($stats['skipped'] ?? 0);
    }
}
