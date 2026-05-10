<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Application;

use BIomie\Logger;
use BIomie\Modules\Geo\Infrastructure\CnaeAggregateBuilder;
use BIomie\Modules\Geo\Infrastructure\GeoAggregateBuilder;
use BIomie\Modules\Geo\Infrastructure\GeoCnaeMartRepository;
use BIomie\Modules\Geo\Infrastructure\GeoCustomerSourceRepository;
use BIomie\Modules\Geo\Infrastructure\OfficialNeighborhoodResolver;
use BIomie\TableHealthService;
use Throwable;

final class GeoCnaeMaterializationRunner
{
    public function __construct(
        private readonly GeoCustomerSourceRepository  $sourceRepo,
        private readonly GeoCnaeMartRepository        $martRepo,
        private readonly OfficialNeighborhoodResolver $resolver,
        private readonly GeoAggregateBuilder          $geoBuilder,
        private readonly CnaeAggregateBuilder         $cnaeBuilder,
        private readonly TableHealthService           $tableHealth,
        private readonly Logger                       $logger,
    ) {}

    /**
     * Executes the full 9-stage geo/CNAE materialization pipeline.
     *
     * @param array{dry_run?:bool,rebuild?:bool} $options
     * @return array<string,mixed>
     */
    public function run(array $options, ?int $actorUserId): array
    {
        $startedAt = microtime(true);
        $dryRun    = (bool) ($options['dry_run'] ?? false);
        $rebuild   = (bool) ($options['rebuild'] ?? false);
        $report    = GeoCnaeMaterializationReport::empty();

        // ── Stage 1: skeleton rows ────────────────────────────────────────────
        $skeletons = $dryRun
            ? $this->sourceRepo->countMissingSkeletons()
            : $this->martRepo->ensureGeoDimSkeletons($rebuild);
        $report['skeletons_created'] = $skeletons;

        // ── Stage 2: normalize geo fields from raw columns ────────────────────
        $report['geo_fields_normalized'] = $dryRun ? 0 : $this->martRepo->normalizeGeoFields();

        // ── Stage 3: match bairros against official dim ───────────────────────
        $matched = $dryRun
            ? $this->sourceRepo->countMatchable()
            : $this->resolver->matchBairrosOfficials();
        $report['bairros_matched'] = $matched;

        // ── Stage 4: queue unmatched (FIXED — self-join removed) ─────────────
        $queued = $dryRun
            ? $this->sourceRepo->countQueueable()
            : $this->martRepo->queueUnmatchedFortaleza();
        $report['queued_for_enrichment'] = $queued;

        if (!$dryRun) {
            // ── Stage 5: geo fact (from mart_fiscal_nfe) ──────────────────────
            if ($this->tableHealth->tableExists('biomie_vendas_geo_cliente_fact')
                && $this->tableHealth->tableExists('mart_fiscal_nfe')) {
                try {
                    [$geoFact, $geoFactWarns] = $this->martRepo->buildGeoFact();
                    $report['geo_fact_inserted'] = $geoFact;
                    foreach ($geoFactWarns as $w) {
                        $report['warnings'][] = "[stage:build_geo_fact] {$w}";
                    }
                } catch (Throwable $e) {
                    $report['warnings'][]        = '[stage:build_geo_fact] Falhou: ' . $e->getMessage();
                    $report['geo_fact_inserted'] = 0;
                }
            }

            // ── Stage 6: geo daily (from fact) ───────────────────────────────
            if ($this->tableHealth->tableExists('biomie_geo_vendas_diaria')
                && $this->tableHealth->tableExists('biomie_vendas_geo_cliente_fact')) {
                try {
                    $report['geo_daily_inserted'] = $this->martRepo->buildGeoDaily();
                } catch (Throwable $e) {
                    $report['warnings'][] = '[stage:build_geo_daily] Falhou: ' . $e->getMessage();
                }
            }

            // ── Stage 7: mart_compradores_por_geo ─────────────────────────────
            if ($this->tableHealth->tableExists('mart_compradores_por_geo')
                && $this->tableHealth->tableExists('mart_cliente_periodo')) {
                try {
                    $report['compradores_geo_inserted'] = $this->geoBuilder->buildCompradoresPorGeo();
                } catch (Throwable $e) {
                    $report['warnings'][] = '[stage:build_compradores_geo] Falhou: ' . $e->getMessage();
                }
            }

            // ── Stage 8: mart_vendas_por_cnae_cnpj ───────────────────────────
            if ($this->tableHealth->tableExists('mart_vendas_por_cnae_cnpj')
                && $this->tableHealth->tableExists('mart_cliente_periodo')) {
                try {
                    $report['vendas_cnae_inserted'] = $this->cnaeBuilder->buildVendasPorCnae();
                } catch (Throwable $e) {
                    $report['warnings'][] = '[stage:build_vendas_cnae] Falhou: ' . $e->getMessage();
                }
            }
        }

        // ── Stage 9: coverage + zero reasons ─────────────────────────────────
        $report['coverage']     = $this->sourceRepo->buildCoverage();
        $report['zero_reasons'] = $this->buildZeroReasons($report);

        $report['status'] = 'ok';
        $report['ok']     = true;
        GeoCnaeMaterializationReport::finish($report, $startedAt);
        return $report;
    }

    /** @return array<string,string> */
    private function buildZeroReasons(array $report): array
    {
        $reasons  = [];
        $coverage = $report['coverage'] ?? [];

        if ((int) ($coverage['com_bairro_norm'] ?? 0) === 0) {
            $reasons['bairros_matched_zero'] =
                'Clientes sem bairro_norm: o endereco nao foi preenchido. '
                . 'O endereco precisa ser sincronizado via Omie (sync de clientes) ou enriquecido manualmente.';
        }

        $compradoresGeo = (int) ($report['compradores_geo_inserted'] ?? -1);
        if ($compradoresGeo === 0) {
            if ($this->tableHealth->tableExists('mart_cliente_periodo')
                && (int) ($this->tableHealth->safeCount('mart_cliente_periodo') ?? 0) === 0) {
                $reasons['compradores_geo_zero'] =
                    'mart_cliente_periodo vazio: execute gold:materialize-commercial primeiro para gerar os dados por periodo.';
            } elseif ($compradoresGeo === 0) {
                $reasons['compradores_geo_zero'] =
                    'Nenhum cliente com NFs encontrado em mart_cliente_periodo para o periodo disponivel.';
            }
        }

        if (isset($report['vendas_cnae_inserted']) && (int) $report['vendas_cnae_inserted'] === 0) {
            $hasCnae = $this->sourceRepo->hasClientesWithCnae();
            $reasons['vendas_cnae_zero'] = $hasCnae
                ? 'Clientes com CNAE nao tiveram NFs no periodo disponivel.'
                : 'Clientes ainda sem CNAE. Execute enriquecimento cadastral para popular cnae_codigo.';
        }

        return $reasons;
    }
}
