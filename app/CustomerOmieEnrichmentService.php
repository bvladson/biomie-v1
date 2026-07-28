<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Modules\Customers\Application\CustomerEnrichmentCoordinator;
use BIomie\Modules\Customers\Application\CustomerEnrichmentRunner;
use BIomie\Modules\Customers\Infrastructure\CustomerDimensionRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerGeoSyncRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerNormalizer;
use BIomie\Modules\Customers\Infrastructure\CustomerQualityValidator;
use BIomie\Modules\Customers\Infrastructure\OmieCustomerFetcher;
use PDO;

/**
 * Fachada temporária de enriquecimento Omie de clientes.
 *
 * Delega toda a lógica para os módulos em app/Modules/Customers/.
 * Modos suportados:
 *   run()    → local_first (ConsultarCliente por código) ou omie_page (ListarClientes).
 *   runAll() → cursor-based batch sem OFFSET mutável.
 *
 * Invariantes de segurança (preservadas na implementação modular):
 *  - Nunca apaga vendedor_responsavel já resolvido.
 *  - Nunca apaga documento_normalizado.
 *  - cnae_codigo: só preenche se estava NULL (COALESCE).
 *  - Skeleton row em biomie_clientes_geo_dim garantida antes do UPDATE.
 */
final class CustomerOmieEnrichmentService
{
    public function __construct(
        private readonly PDO        $pdo,
        private readonly OmieClient $omieClient,
        private readonly Logger     $logger,
        private readonly Config     $config,
    ) {}

    /**
     * @param array{dry_run?:bool,limit?:int,offset?:int,pagina?:int,mode?:string,only_missing?:bool} $options
     * @return array<string,mixed>
     */
    public function run(array $options = [], ?int $actorUserId = null): array
    {
        return $this->makeCoordinator()->run($options, $actorUserId);
    }

    /**
     * @param array{
     *   dry_run?:bool, limit?:int, max_batches?:int, only_missing?:bool,
     *   sleep_ms?:int, max_runtime_seconds?:int, target?:string, source?:string
     * } $options
     * @return array<string,mixed>
     */
    public function runAll(array $options = [], ?int $actorUserId = null): array
    {
        return $this->makeRunner()->runAll($options, $actorUserId);
    }

    private function makeCoordinator(): CustomerEnrichmentCoordinator
    {
        [$normalizer, $validator, $dimRepo, $geoRepo, $fetcher] = $this->makeDependencies();
        return new CustomerEnrichmentCoordinator($dimRepo, $geoRepo, $normalizer, $validator, $fetcher, $this->logger);
    }

    private function makeRunner(): CustomerEnrichmentRunner
    {
        [$normalizer, $validator, $dimRepo, $geoRepo, $fetcher] = $this->makeDependencies();
        return new CustomerEnrichmentRunner($dimRepo, $geoRepo, $normalizer, $validator, $fetcher, $this->logger);
    }

    /**
     * @return array{0:CustomerNormalizer,1:CustomerQualityValidator,2:CustomerDimensionRepository,3:CustomerGeoSyncRepository,4:OmieCustomerFetcher}
     */
    private function makeDependencies(): array
    {
        return [
            new CustomerNormalizer(),
            new CustomerQualityValidator(),
            new CustomerDimensionRepository($this->pdo, $this->logger),
            new CustomerGeoSyncRepository($this->pdo, $this->logger),
            new OmieCustomerFetcher($this->omieClient),
        ];
    }
}
