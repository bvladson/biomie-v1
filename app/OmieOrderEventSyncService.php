<?php

declare(strict_types=1);

namespace BIomie;

final class OmieOrderEventSyncService
{
    private const EVENT_SYNC_NAME = 'order_stage_events_sync';
    private const STATE_PHASE = 'chunked';
    private const BULK_WINDOW_DAYS = 31;

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

        $this->canonical->prepareSyncState(
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

        try {
            foreach ($this->windowRanges($start, $end) as [$windowStart, $windowEnd]) {
                $this->canonical->advanceOrdersCheckpoint(
                    $start,
                    $end,
                    $windowStart,
                    1,
                    0,
                    $source,
                    $vendorsSynced,
                    $stats,
                    self::EVENT_SYNC_NAME,
                    self::STATE_PHASE
                );
                $windowResult = $this->processWindow($windowStart, $windowEnd, $source);
                $stats = $this->mergeStats($stats, $windowResult['stats']);
            }
        } catch (\Throwable $exception) {
            $this->canonical->markSyncFailed(
                $start,
                $end,
                $exception->getMessage(),
                $source,
                $vendorsSynced,
                $stats,
                $start,
                1,
                0,
                null,
                self::EVENT_SYNC_NAME,
                self::STATE_PHASE
            );
            throw $exception;
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
    private function processWindow(string $start, string $end, string $source): array
    {
        $stats = $this->emptyStats();
        $rawEvents = $this->omie->listOrderStageEvents($start, $end, '00:00:00', '23:59:59');

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

    /** @return list<array{0:string,1:string}> */
    private function windowRanges(string $start, string $end, int $days = self::BULK_WINDOW_DAYS): array
    {
        $ranges = [];
        $current = strtotime($start);
        $last = strtotime($end);

        while ($current <= $last) {
            $windowEnd = min(strtotime('+' . ($days - 1) . ' days', $current), $last);
            $ranges[] = [date('Y-m-d', $current), date('Y-m-d', $windowEnd)];
            $current = strtotime('+1 day', $windowEnd);
        }

        return $ranges;
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
