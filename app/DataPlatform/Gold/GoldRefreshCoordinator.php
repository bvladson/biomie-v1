<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold;

use BIomie\DataPlatform\Gold\Builders\BridgeMartBuilder;
use BIomie\DataPlatform\Gold\Builders\CnaeSalesMartBuilder;
use BIomie\DataPlatform\Gold\Builders\CommercialOrderMartBuilder;
use BIomie\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\FinanceTitleMartBuilder;
use BIomie\DataPlatform\Gold\Builders\FiscalNfeMartBuilder;
use BIomie\DataPlatform\Gold\Builders\GeoBuyerMartBuilder;
use BIomie\DataPlatform\Gold\Builders\ProductPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\VendorDayMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Orquestra a materialização de todos os marts gold para um intervalo de datas.
 *
 * Extraído de GoldMartBuilder (Fase 5). GoldMartBuilder mantém a API pública
 * original como fachada delegando para esta classe.
 */
final class GoldRefreshCoordinator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?PipelineStatusRepository $pipelineStatus = null,
    ) {
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshPeriod(string $start, string $end): array
    {
        $t0           = microtime(true);
        $competencias = $this->competenciasInRange($start, $end);
        $jobId        = $this->pipelineStatus?->startJob('gold_mart_refresh', $start, $end) ?? 0;

        $this->logger->info('GoldRefreshCoordinator: refresh iniciado.', [
            'start' => $start, 'end' => $end, 'competencias' => $competencias, 'job_id' => $jobId,
        ]);

        try {
            $bridge     = $this->bridge()->refresh($start, $end);
            $comercial  = $this->commercial()->refresh($start, $end);
            $fiscal     = $this->fiscal()->refresh($start, $end);
            $financeiro = $this->finance()->refresh($start, $end);
            $vendorDay  = $this->vendor()->refresh($start, $end);
            $geoResult  = $this->geoBuyer()->refreshReplace($start, $end, $competencias);
            $compGeo    = (int) ($geoResult['inserted_rows'] ?? 0);
            $cnaeCnpj   = $this->cnaeSales()->refresh($start, $end);

            $clientePeriodo = 0;
            $produtoPeriodo = 0;
            foreach ($competencias as $competencia) {
                $compStart = $competencia . '-01';
                $compEnd   = date('Y-m-t', strtotime($compStart));
                $customerResult = $this->customer()->refreshReplace($competencia, $compStart, $compEnd);
                $clientePeriodo += (int) ($customerResult['final_rows'] ?? 0);
                $produtoPeriodo += $this->product()->refresh($competencia, $compStart, $compEnd);
            }

            $duration = round(microtime(true) - $t0, 2);
            $counts = [
                'bridge'          => $bridge,
                'comercial'       => $comercial,
                'fiscal'          => $fiscal,
                'financeiro'      => $financeiro,
                'vendedor_dia'    => $vendorDay,
                'compradores_geo' => $compGeo,
                'vendas_cnae'     => $cnaeCnpj,
                'cliente_periodo' => $clientePeriodo,
                'produto_periodo' => $produtoPeriodo,
            ];

            $this->pipelineStatus?->completeJob($jobId, $counts, $duration);
            $this->logger->info('GoldRefreshCoordinator: refresh concluido.', ['duration_seconds' => $duration, 'rows' => $counts]);

            return $counts + ['duration_seconds' => $duration];
        } catch (Throwable $e) {
            $this->pipelineStatus?->failJob($jobId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshCommercialPeriod(string $start, string $end): array
    {
        $t0           = microtime(true);
        $competencias = $this->competenciasInRange($start, $end);
        $jobId        = $this->pipelineStatus?->startJob('gold_mart_refresh', $start, $end) ?? 0;

        $this->logger->info('GoldRefreshCoordinator: refresh comercial iniciado.', [
            'start' => $start, 'end' => $end, 'job_id' => $jobId,
        ]);

        try {
            $bridge    = $this->bridge()->refresh($start, $end);
            $comercial = $this->commercial()->refresh($start, $end);
            $fiscal    = $this->fiscal()->refresh($start, $end);
            $vendorDay = $this->vendor()->refresh($start, $end);

            $clientePeriodo = 0;
            $produtoPeriodo = 0;
            foreach ($competencias as $competencia) {
                $compStart = $competencia . '-01';
                $compEnd   = date('Y-m-t', strtotime($compStart));
                $customerResult = $this->customer()->refreshReplace($competencia, $compStart, $compEnd);
                $clientePeriodo += (int) ($customerResult['final_rows'] ?? 0);
                $produtoPeriodo += $this->product()->refresh($competencia, $compStart, $compEnd);
            }

            $duration = round(microtime(true) - $t0, 2);
            $counts = [
                'bridge'          => $bridge,
                'comercial'       => $comercial,
                'fiscal'          => $fiscal,
                'vendedor_dia'    => $vendorDay,
                'cliente_periodo' => $clientePeriodo,
                'produto_periodo' => $produtoPeriodo,
            ];

            $this->pipelineStatus?->completeJob($jobId, $counts, $duration);
            $this->logger->info('GoldRefreshCoordinator: refresh comercial concluido.', ['duration_seconds' => $duration, 'rows' => $counts]);

            return $counts + ['duration_seconds' => $duration];
        } catch (Throwable $e) {
            $this->pipelineStatus?->failJob($jobId, $e->getMessage());
            throw $e;
        }
    }

    // ── Builder factories ────────────────────────────────────────────────────

    private function bridge(): BridgeMartBuilder
    {
        return new BridgeMartBuilder($this->pdo, $this->logger);
    }

    private function commercial(): CommercialOrderMartBuilder
    {
        return new CommercialOrderMartBuilder($this->pdo, $this->logger);
    }

    private function fiscal(): FiscalNfeMartBuilder
    {
        return new FiscalNfeMartBuilder($this->pdo, $this->logger);
    }

    private function finance(): FinanceTitleMartBuilder
    {
        return new FinanceTitleMartBuilder($this->pdo, $this->logger);
    }

    private function vendor(): VendorDayMartBuilder
    {
        return new VendorDayMartBuilder($this->pdo, $this->logger);
    }

    private function customer(): CustomerPeriodMartBuilder
    {
        return new CustomerPeriodMartBuilder($this->pdo, $this->logger);
    }

    private function product(): ProductPeriodMartBuilder
    {
        return new ProductPeriodMartBuilder($this->pdo, $this->logger);
    }

    private function geoBuyer(): GeoBuyerMartBuilder
    {
        return new GeoBuyerMartBuilder($this->pdo, $this->logger);
    }

    private function cnaeSales(): CnaeSalesMartBuilder
    {
        return new CnaeSalesMartBuilder($this->pdo, $this->logger);
    }

    // ── Date helpers ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function competenciasInRange(string $start, string $end): array
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start) ?: new DateTimeImmutable($start);
        $endDate   = DateTimeImmutable::createFromFormat('Y-m-d', $end)   ?: new DateTimeImmutable($end);

        $cursor = $startDate->modify('first day of this month');
        $result = [];

        while ($cursor <= $endDate) {
            $result[] = $cursor->format('Y-m');
            $cursor   = $cursor->modify('+1 month');
        }

        return array_values(array_unique($result));
    }
}
