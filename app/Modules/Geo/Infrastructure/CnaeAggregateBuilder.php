<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\DataPlatform\Gold\Builders\CnaeSalesMartBuilder;
use BIomie\DataPlatform\Gold\MartTransactionRunner;
use BIomie\Logger;
use BIomie\Support\CommercialDataLockSet;
use BIomie\Support\TenantContext;
use BIomie\TableHealthService;
use DateTimeImmutable;
use PDO;

/**
 * Compatibility adapter for legacy Geo/CNAE entrypoints.
 *
 * The former implementation rebuilt the whole company without channel keys,
 * a transaction or the shared Gold lock. All calls now delegate to the same
 * canonical, channel-aware builder used by GoldRefreshCoordinator.
 */
final class CnaeAggregateBuilder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TableHealthService $tableHealth,
        private readonly Logger $logger,
    ) {
    }

    public function buildVendasPorCnae(?string $start = null, ?string $end = null): int
    {
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de materializar vendas por CNAE.');
        }
        if (!$this->tableHealth->tableExists('mart_vendas_por_cnae_cnpj')
            || !$this->tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
            return 0;
        }

        [$rangeStart, $rangeEnd] = $this->resolveRange($start, $end, $companyId);
        if ($rangeStart === null || $rangeEnd === null) {
            return 0;
        }

        $lockSet = CommercialDataLockSet::acquire($this->pdo, $companyId, $this->logger);
        if ($lockSet === null) {
            throw new \RuntimeException(
                'Sincronizacao fiscal ou materializacao Gold em execucao; tente o CNAE novamente.'
            );
        }

        try {
            $transaction = (new MartTransactionRunner($this->pdo, $this->logger))->run(
                'legacy_cnae_adapter_refresh',
                ['start' => $rangeStart, 'end' => $rangeEnd, 'company_id' => $companyId],
                fn (): array => [
                    'inserted_rows' => (new CnaeSalesMartBuilder($this->pdo, $this->logger))
                        ->refresh($rangeStart, $rangeEnd),
                ],
            );

            return (int) ($transaction['result']['inserted_rows'] ?? 0);
        } finally {
            $lockSet->release();
        }
    }

    /** @return array{0:?string,1:?string} */
    private function resolveRange(?string $start, ?string $end, int $companyId): array
    {
        $start = trim((string) $start);
        $end = trim((string) $end);
        if (($start === '') !== ($end === '')) {
            throw new \InvalidArgumentException('Informe start e end juntos para materializar CNAE.');
        }

        if ($start !== '' && $end !== '') {
            $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
            if (!$startDate instanceof DateTimeImmutable
                || $startDate->format('Y-m-d') !== $start
                || !$endDate instanceof DateTimeImmutable
                || $endDate->format('Y-m-d') !== $end
                || $start > $end) {
                throw new \InvalidArgumentException('Intervalo CNAE invalido; use YYYY-MM-DD e start <= end.');
            }
            return [$start, $end];
        }

        $stmt = $this->pdo->prepare(
            'SELECT MIN(COALESCE(data_competencia, data_emissao)) AS min_date,
                    MAX(COALESCE(data_competencia, data_emissao)) AS max_date
               FROM biomie_notas_fiscais_canonicas
              WHERE company_id = :company_id
                AND COALESCE(data_competencia, data_emissao) IS NOT NULL'
        );
        $stmt->execute(['company_id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [null, null];
        }

        $min = trim((string) ($row['min_date'] ?? ''));
        $max = trim((string) ($row['max_date'] ?? ''));
        return $min !== '' && $max !== '' ? [$min, $max] : [null, null];
    }
}
