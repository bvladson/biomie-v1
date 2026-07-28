<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Application;

use BIomie\Logger;
use BIomie\Modules\Geo\Infrastructure\CnaeAggregateBuilder;
use BIomie\Modules\Geo\Infrastructure\GeoCustomerSourceRepository;
use BIomie\TableHealthService;
use BIomie\Telemetry;
use Throwable;

final class GeoCnaeMaterializationCoordinator
{
    public function __construct(
        private readonly GeoCustomerSourceRepository  $sourceRepo,
        private readonly GeoCnaeMaterializationRunner $runner,
        private readonly CnaeAggregateBuilder         $cnaeBuilder,
        private readonly TableHealthService           $tableHealth,
        private readonly Logger                       $logger,
    ) {}

    /**
     * @param array{dry_run?:bool,rebuild?:bool} $options
     * @return array<string,mixed>
     */
    public function run(array $options, ?int $actorUserId): array
    {
        $startedAt = microtime(true);
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan(
            'geo.materialize',
            ['domain_name' => 'geo', 'source_name' => 'geo_cnae_builder', 'actor_user_id' => $actorUserId],
            'geo',
            'geo_cnae_builder'
        );

        $prereq = $this->checkPrerequisites();
        if ($prereq !== null) {
            $report             = GeoCnaeMaterializationReport::empty();
            $report['status']   = 'prerequisites_missing';
            $report['errors'][] = $prereq;
            GeoCnaeMaterializationReport::finish($report, $startedAt);
            $span?->finish('prerequisites_missing', ['error' => $prereq]);
            return $report;
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $telemetry?->recordEvent('info', 'geo.materialize.started', 'Materializacao geo iniciada.', [
            'actor_user_id' => $actorUserId, 'dry_run' => $dryRun,
        ], 'geo', 'geo_cnae_builder');

        try {
            $report = $this->runner->run($options, $actorUserId);

            $telemetry?->recordEvent('info', 'geo.materialize.finished', 'Materializacao geo concluida.', [
                'skeletons_created' => $report['skeletons_created'],
                'bairros_matched'   => $report['bairros_matched'],
                'queued'            => $report['queued_for_enrichment'],
                'compradores_geo'   => $report['compradores_geo_inserted'] ?? 0,
                'vendas_cnae'       => $report['vendas_cnae_inserted'] ?? 0,
                'dry_run'           => $dryRun,
            ], 'geo', 'geo_cnae_builder');

            $span?->finish('ok', GeoCnaeMaterializationReport::spanResult($report));
            return $report;

        } catch (Throwable $e) {
            $this->logger->error('GeoCnaeMaterializationService: falha.', [
                'message' => $e->getMessage(), 'actor_user_id' => $actorUserId,
            ]);
            $report             = GeoCnaeMaterializationReport::empty();
            $report['status']   = 'error';
            $report['errors'][] = $e->getMessage();
            GeoCnaeMaterializationReport::finish($report, $startedAt);
            $span?->finish('error', ['exception' => get_class($e)]);
            return $report;
        }
    }

    /** @return array<string,mixed> */
    public function dryRunSnapshot(): array
    {
        return $this->sourceRepo->buildDryRunSnapshot();
    }

    public function buildVendasPorCnae(): int
    {
        return $this->cnaeBuilder->buildVendasPorCnae();
    }

    /** @param list<string> $candidates */
    public function pickColumn(string $table, array $candidates): ?string
    {
        return $this->sourceRepo->pickColumn($table, $candidates);
    }

    private function checkPrerequisites(): ?string
    {
        if (!$this->tableHealth->tableExists('biomie_clientes_dim')) {
            return 'biomie_clientes_dim ausente. Execute migrations e clientes:materialize-dim primeiro.';
        }
        if ((int) ($this->tableHealth->safeCount('biomie_clientes_dim') ?? 0) === 0) {
            return 'biomie_clientes_dim vazia. Execute clientes:materialize-dim antes de geo:materialize.';
        }
        if (!$this->tableHealth->tableExists('biomie_bairros_fortaleza_dim')) {
            return 'biomie_bairros_fortaleza_dim ausente. Execute as migrations primeiro.';
        }
        $bairroCount = (int) ($this->tableHealth->safeCount('biomie_bairros_fortaleza_dim') ?? 0);
        if ($bairroCount < 121) {
            return sprintf(
                'biomie_bairros_fortaleza_dim incompleta: %d bairros encontrados, esperado %d. Re-execute a migration.',
                $bairroCount,
                121
            );
        }
        if (!$this->tableHealth->tableExists('biomie_clientes_geo_dim')) {
            return 'biomie_clientes_geo_dim ausente. Execute migrations primeiro.';
        }
        return null;
    }
}
