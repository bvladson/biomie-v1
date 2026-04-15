<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;
use InvalidArgumentException;

final class FlightPlanTargetsService
{
    public function __construct(
        private readonly PDO $db,
        private readonly TargetsRepository $targetsRepository,
        private readonly Cache $cache,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<int, array{vendor_id:string, vendor_name:string, year_month:string, target_amount:float|int|string, changed_by:?string, change_reason:?string}> $rows
     */
    public function saveTargetsBatch(array $rows): array
    {
        $monthsTouched = [];
        $touchedPairs = [];
        $saved = 0;

        $this->db->beginTransaction();
        
        try {
            foreach ($rows as $row) {
                $vendorId = trim((string) ($row['vendor_id'] ?? ''));
                $yearMonth = trim((string) ($row['year_month'] ?? ''));
                $targetAmt = (float) ($row['target_amount'] ?? 0);
                
                if ($vendorId === '' || $yearMonth === '') {
                    throw new InvalidArgumentException("Formato inválido: vendor_id e year_month são obrigatórios.");
                }

                $this->targetsRepository->upsert(
                    $vendorId,
                    trim((string) ($row['vendor_name'] ?? '')),
                    $yearMonth,
                    $targetAmt,
                    trim((string) ($row['changed_by'] ?? 'interface')),
                    isset($row['change_reason']) ? trim((string)$row['change_reason']) : null
                );

                $monthsTouched[$yearMonth] = true;
                $touchedPairs[$vendorId . '|' . $yearMonth] = true;
                $saved++;
            }

            $this->db->commit();

            $persistedRows = [];
            if ($monthsTouched !== []) {
                $persistedMap = $this->targetsRepository->getTargetsForMonths(array_keys($monthsTouched));
                foreach ($persistedMap as $pairKey => $row) {
                    if (!isset($touchedPairs[$pairKey])) {
                        continue;
                    }

                    $persistedRows[] = [
                        'vendor_id' => (string) ($row['vendor_id'] ?? ''),
                        'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                        'year_month' => (string) ($row['year_month'] ?? ''),
                        'target_amount' => Support::money((float) ($row['target_amount'] ?? 0)),
                        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
                    ];
                }
            }

            foreach (array_keys($monthsTouched) as $ym) {
                // Invalida o Cache por prefixo de página
                $this->cache->invalidatePrefix("flightplan:targets:editor:{$ym}");
                $this->markHistoricalCompetenciaStale($ym);
            }

            $this->logger->info("Lote de metas salvo com sucesso", [
                'count' => $saved,
                'months' => array_keys($monthsTouched)
            ]);

            return [
                'ok' => true,
                'saved' => $saved,
                'months' => array_keys($monthsTouched),
                'rows' => $persistedRows,
            ];
            
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("Erro ao salvar lote de metas", [
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function markHistoricalCompetenciaStale(string $yearMonth): void
    {
        try {
            $this->db->prepare('DELETE FROM biomie_competencia_snapshots WHERE competencia = :competencia')
                ->execute(['competencia' => $yearMonth]);

            $this->db->prepare(
                'INSERT INTO biomie_competencia_sync_catalog (
                    competencia, period_start, period_end, status, source, last_error, snapshot_generated_at
                ) VALUES (
                    :competencia, :period_start, :period_end, :status, :source, :last_error, NULL
                )
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    source = VALUES(source),
                    last_error = VALUES(last_error),
                    snapshot_generated_at = NULL'
            )->execute([
                'competencia' => $yearMonth,
                'period_start' => $yearMonth . '-01',
                'period_end' => date('Y-m-t', strtotime($yearMonth . '-01')),
                'status' => 'stale',
                'source' => 'targets_update',
                'last_error' => 'Snapshot historico invalidado por alteracao de metas.',
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Nao foi possivel invalidar snapshot historico apos alteracao de metas.', [
                'year_month' => $yearMonth,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
