<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
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
     * Payload vindo da interface (JSON) — todas as chaves são tratadas como
     * opcionais e validadas em runtime antes do upsert.
     *
     * @param array<int, array{vendor_id?:string, vendor_name?:string, year_month?:string, target_amount?:float|int|string, changed_by?:?string, change_reason?:?string, tiers?:mixed}> $rows
     */
    public function saveTargetsBatch(array $rows): array
    {
        if (TenantContext::isConsolidado()) {
            throw new InvalidArgumentException('Metas devem ser alteradas no contexto de uma empresa específica.');
        }

        $monthsTouched = [];
        $touchedPairs = [];
        $saved = 0;

        $this->db->beginTransaction();
        
        try {
            foreach ($rows as $row) {
                $vendorId = trim((string) ($row['vendor_id'] ?? ''));
                $yearMonth = trim((string) ($row['year_month'] ?? ''));
                $targetRaw = $row['target_amount'] ?? null;
                $targetAmt = is_numeric($targetRaw) ? (float) $targetRaw : NAN;
                
                $monthDate = \DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
                if ($vendorId === '' || !$monthDate instanceof \DateTimeImmutable || $monthDate->format('Y-m') !== $yearMonth) {
                    throw new InvalidArgumentException('Informe o vendedor e um mês de referência válido.');
                }

                if ($yearMonth < date('Y-m')) {
                    throw new InvalidArgumentException('Metas de meses encerrados não podem ser alteradas.');
                }

                if (!is_finite($targetAmt) || $targetAmt < 0 || $targetAmt > 9999999999999.99) {
                    throw new InvalidArgumentException('A meta precisa ser um valor válido igual ou maior que zero.');
                }

                // Níveis opcionais de meta (Meta 2..N) — devem superar a meta principal.
                $tiers = [];
                if (isset($row['tiers']) && is_array($row['tiers'])) {
                    foreach ($row['tiers'] as $tierRaw) {
                        if ($tierRaw === null || $tierRaw === '') {
                            continue;
                        }
                        $tierVal = is_numeric($tierRaw) ? (float) $tierRaw : NAN;
                        if (!is_finite($tierVal) || $tierVal <= 0 || $tierVal > 9999999999999.99) {
                            throw new InvalidArgumentException('Cada nível de meta precisa ser um valor válido maior que zero.');
                        }
                        if ($targetAmt > 0 && $tierVal <= $targetAmt) {
                            throw new InvalidArgumentException('Os níveis adicionais devem ser maiores que a meta principal.');
                        }
                        $tiers[] = $tierVal;
                    }
                }

                $currentUser = \BIomie\Auth::currentUser();
                $changedBy = $currentUser ? ($currentUser['full_name'] ?? $currentUser['username'] ?? 'system') : 'system';

                $this->targetsRepository->upsert(
                    $vendorId,
                    trim((string) ($row['vendor_name'] ?? '')),
                    $yearMonth,
                    $targetAmt,
                    $changedBy,
                    isset($row['change_reason']) ? trim((string)$row['change_reason']) : null,
                    $tiers
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
                        'vendor_id' => $row['vendor_id'],
                        'vendor_name' => $row['vendor_name'],
                        'year_month' => $row['year_month'],
                        'target_amount' => Support::money($row['target_amount']),
                        'tiers' => $row['tiers'],
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
            $companyId = TenantContext::companyId();
            $this->db->prepare(
                'DELETE FROM biomie_competencia_snapshots
                  WHERE company_id = :company_id AND competencia = :competencia'
            )->execute(['company_id' => $companyId, 'competencia' => $yearMonth]);

            $this->db->prepare(
                'INSERT INTO biomie_competencia_sync_catalog (
                    company_id, competencia, period_start, period_end, status, source, last_error, snapshot_generated_at
                ) VALUES (
                    :company_id, :competencia, :period_start, :period_end, :status, :source, :last_error, NULL
                )
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    source = VALUES(source),
                    last_error = VALUES(last_error),
                    snapshot_generated_at = NULL'
            )->execute([
                'company_id' => $companyId,
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
