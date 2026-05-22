<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Modules\Geo\Application\GeoCnaeMaterializationCoordinator;
use BIomie\Modules\Geo\Application\GeoCnaeMaterializationRunner;
use BIomie\Modules\Geo\Infrastructure\CnaeAggregateBuilder;
use BIomie\Modules\Geo\Infrastructure\GeoAggregateBuilder;
use BIomie\Modules\Geo\Infrastructure\GeoCnaeMartRepository;
use BIomie\Modules\Geo\Infrastructure\GeoCustomerSourceRepository;
use BIomie\Modules\Geo\Infrastructure\OfficialNeighborhoodResolver;
use PDO;

/**
 * Fachada de materialização geo/CNAE.
 *
 * Delega toda a lógica para os módulos em app/Modules/Geo/.
 * Pipeline de 9 estágios — idempotente (executar N vezes não duplica registros).
 *
 * Invariantes preservadas na implementação modular:
 *  - INSERT IGNORE antes de qualquer UPDATE em biomie_clientes_geo_dim (skeleton row).
 *  - COALESCE(NULLIF(cnae_codigo, ''), :value) — nunca sobrescreve CNAE existente.
 *  - Self-join em biomie_clientes_para_enriquecer removido (MariaDB error 1052).
 */
final class GeoCnaeMaterializationService
{
    public function __construct(
        private readonly PDO                $pdo,
        private readonly Logger             $logger,
        private readonly TableHealthService $tableHealth,
    ) {}

    /**
     * @param array{dry_run?:bool,rebuild?:bool,start?:string,end?:string} $options
     * @return array<string,mixed>
     */
    public function run(array $options = [], ?int $actorUserId = null): array
    {
        return $this->makeCoordinator()->run($options, $actorUserId);
    }

    /** @return array<string,mixed> */
    public function dryRunSnapshot(): array
    {
        return (new GeoCustomerSourceRepository($this->pdo, $this->tableHealth))->buildDryRunSnapshot();
    }

    public function buildVendasPorCnae(): int
    {
        return (new CnaeAggregateBuilder($this->pdo, $this->tableHealth))->buildVendasPorCnae();
    }

    /** @param list<string> $candidates */
    public function pickColumn(string $table, array $candidates): ?string
    {
        return (new GeoCustomerSourceRepository($this->pdo, $this->tableHealth))->pickColumn($table, $candidates);
    }

    private function makeCoordinator(): GeoCnaeMaterializationCoordinator
    {
        $sourceRepo = new GeoCustomerSourceRepository($this->pdo, $this->tableHealth);
        $martRepo   = new GeoCnaeMartRepository($this->pdo);
        $resolver   = new OfficialNeighborhoodResolver($this->pdo);
        $geoBuilder = new GeoAggregateBuilder($this->pdo);
        $cnaeBuilder = new CnaeAggregateBuilder($this->pdo, $this->tableHealth);

        $runner = new GeoCnaeMaterializationRunner(
            $sourceRepo, $martRepo, $resolver, $geoBuilder, $cnaeBuilder,
            $this->tableHealth, $this->logger
        );

        return new GeoCnaeMaterializationCoordinator(
            $sourceRepo, $runner, $cnaeBuilder, $this->tableHealth, $this->logger
        );
    }
}
