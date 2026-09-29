<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold;

use BIomie\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\GeoBuyerMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\Support\CommercialDataLockSet;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class GeoPeriodRebuildService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?PipelineStatusRepository $pipelineStatus = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function rebuild(string $start, string $end, array $options = []): array
    {
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de reconstruir os marts geograficos.');
        }
        $startedAt = microtime(true);
        $dryRun = !empty($options['dry_run']);
        $doAssert = !empty($options['assert']);
        $window = MartRefreshWindow::fromRange($start, $end, [
            'allow_open_month' => !empty($options['allow_open_month']),
            'force_full_month' => !empty($options['force_full_month']),
        ]);

        $lock = null;
        if (!$dryRun) {
            // Geo replaces marts also written by the commercial Gold refresh.
            // Use the shared fiscal -> Gold lock order so the two entrypoints
            // cannot interleave delete/insert cycles for the same company.
            $lock = CommercialDataLockSet::acquire($this->pdo, $companyId, $this->logger);
            if ($lock === null) {
                return [
                    'ok' => false,
                    'status' => 'locked',
                    'locked' => true,
                    'dry_run' => false,
                    'window' => $window->toArray(),
                    'errors' => ['Sincronizacao fiscal ou materializacao Gold esta em execucao para esta empresa.'],
                    'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                ];
            }
        }

        $jobId = 0;
        $pipeline = $this->pipelineStatus ?? $this->makePipelineRepository();
        $before = $this->snapshotForWindow($window);
        $source = $this->sourceSnapshot($window);

        $result = [
            'ok' => true,
            'status' => $dryRun ? 'dry_run' : 'ok',
            'locked' => false,
            'dry_run' => $dryRun,
            'window' => $window->toArray(),
            'source' => $source,
            'before' => $before,
            'after' => $before,
            'steps' => [],
            'deleted' => [
                'mart_cliente_periodo' => 0,
                'mart_compradores_por_geo' => 0,
            ],
            'inserted' => [
                'mart_cliente_periodo' => 0,
                'mart_compradores_por_geo' => 0,
            ],
            'assertions' => [],
            'reconciliation' => [],
            'warnings' => [],
            'errors' => [],
            'log' => [
                'job_id' => null,
                'source' => (string) ($options['source'] ?? 'geo_period_rebuild_service'),
                'actor_user_id' => isset($options['actor_user_id']) ? (int) $options['actor_user_id'] : null,
            ],
            'diagnosis' => [
                'map_should_populate' => false,
                'next_action' => 'Executar rebuild e depois validar o mapa.',
            ],
            'duration_ms' => 0,
        ];

        if ($dryRun) {
            $result['diagnosis'] = $this->diagnosis($source, $before);
            $result['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            return $result;
        }

        try {
            $jobId = $pipeline?->startJob('geo_rebuild_period', $window->effectiveStart, $window->effectiveEnd) ?? 0;
            $result['log']['job_id'] = $jobId ?: null;

            $runner = new MartTransactionRunner($this->pdo, $this->logger);
            $transaction = $runner->run('geo_rebuild_period', [
                'window' => $window->toArray(),
                'source' => $result['log']['source'],
            ], function () use ($window): array {
                $customer = new CustomerPeriodMartBuilder($this->pdo, $this->logger);
                $geo = new GeoBuyerMartBuilder($this->pdo, $this->logger);

                $customerSteps = [];
                $customerDeleted = 0;
                $customerInserted = 0;
                foreach ($window->competencias() as $competencia) {
                    $range = $window->rangeForCompetencia($competencia);
                    $step = $customer->refreshReplace($competencia, $range['start'], $range['end']);
                    $customerSteps[$competencia] = $step + ['range' => $range];
                    $customerDeleted += (int) ($step['deleted_rows'] ?? 0);
                    $customerInserted += (int) ($step['final_rows'] ?? 0);
                }

                $geoStep = $geo->refreshReplace($window->effectiveStart, $window->effectiveEnd, $window->competencias());

                return [
                    'mart_cliente_periodo' => [
                        'ok' => true,
                        'deleted_rows' => $customerDeleted,
                        'inserted_rows' => $customerInserted,
                        'competencias' => $customerSteps,
                    ],
                    'mart_compradores_por_geo' => $geoStep + ['ok' => true],
                ];
            });

            $steps = is_array($transaction['result'] ?? null) ? $transaction['result'] : [];
            $result['steps'] = $steps;
            $result['deleted']['mart_cliente_periodo'] = (int) ($steps['mart_cliente_periodo']['deleted_rows'] ?? 0);
            $result['deleted']['mart_compradores_por_geo'] = (int) ($steps['mart_compradores_por_geo']['deleted_rows'] ?? 0);
            $result['inserted']['mart_cliente_periodo'] = (int) ($steps['mart_cliente_periodo']['inserted_rows'] ?? 0);
            $result['inserted']['mart_compradores_por_geo'] = (int) ($steps['mart_compradores_por_geo']['inserted_rows'] ?? 0);
            $result['after'] = $this->snapshotForWindow($window);
            $result['reconciliation'] = $this->reconciliation($source, $result['after']);

            if ($doAssert) {
                $result['assertions'] = $this->assertWindow($window, $before, $result['after'], $source);
                if (($result['assertions']['status'] ?? 'ok') === 'failed') {
                    $result['ok'] = false;
                    $result['status'] = 'assert_failed';
                    $result['errors'][] = 'Assert pos-materializacao falhou.';
                } elseif (($result['assertions']['status'] ?? 'ok') === 'warning') {
                    $result['status'] = 'warning';
                    $result['warnings'][] = 'Assert pos-materializacao retornou warning.';
                }
            }

            $result['diagnosis'] = $this->diagnosis($source, $result['after']);
            $result['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));

            if ($result['ok']) {
                $pipeline?->completeJob($jobId, [
                    'mart_cliente_periodo' => $result['inserted']['mart_cliente_periodo'],
                    'mart_compradores_por_geo' => $result['inserted']['mart_compradores_por_geo'],
                ], $result['duration_ms'] / 1000);
            } else {
                $pipeline?->failJob($jobId, implode('; ', $result['errors']));
            }

            return $result;
        } catch (Throwable $exception) {
            $pipeline?->failJob($jobId, $exception->getMessage());

            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['errors'][] = $exception->getMessage();
            $result['after'] = $this->snapshotForWindow($window);
            $result['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public function snapshotForWindow(MartRefreshWindow $window): array
    {
        $cliente = $this->periodTableSnapshot('mart_cliente_periodo', $window->startCompetencia, $window->endCompetencia, 'valor_nfe');
        $geo = $this->periodTableSnapshot('mart_compradores_por_geo', $window->startCompetencia, $window->endCompetencia, 'faturamento');

        return [
            'mart_cliente_periodo_total' => $cliente['total_rows'],
            'mart_cliente_periodo_period' => $cliente['period_rows'],
            'mart_cliente_periodo_outside_period' => $cliente['outside_rows'],
            'mart_cliente_periodo_revenue' => $cliente['revenue'],
            'mart_cliente_periodo_last_refreshed_at' => $cliente['last_refreshed_at'],
            'mart_compradores_por_geo_total' => $geo['total_rows'],
            'mart_compradores_por_geo_period' => $geo['period_rows'],
            'mart_compradores_por_geo_outside_period' => $geo['outside_rows'],
            'mart_compradores_por_geo_revenue' => $geo['revenue'],
            'mart_compradores_por_geo_bairros' => $this->geoDistinctNeighborhoods($window),
            'mart_compradores_por_geo_clientes' => $this->geoDistinctClients($window),
            'mart_compradores_por_geo_notas' => $this->geoNotes($window),
            'mart_compradores_por_geo_last_refreshed_at' => $geo['last_refreshed_at'],
        ];
    }

    /** @return array<string,mixed> */
    public function assertWindow(MartRefreshWindow $window, array $before, array $after, array $source): array
    {
        $canonicalNfs = (int) ($source['canonical_nfs_autorizadas'] ?? 0);
        $clienteRows = (int) ($after['mart_cliente_periodo_period'] ?? 0);
        $geoRows = (int) ($after['mart_compradores_por_geo_period'] ?? 0);
        $geoBairros = (int) ($after['mart_compradores_por_geo_bairros'] ?? 0);
        $outsideUnchanged = (int) ($before['mart_cliente_periodo_outside_period'] ?? -1) === (int) ($after['mart_cliente_periodo_outside_period'] ?? -2)
            && (int) ($before['mart_compradores_por_geo_outside_period'] ?? -1) === (int) ($after['mart_compradores_por_geo_outside_period'] ?? -2);

        $duplicatesCliente = $this->duplicateCount('mart_cliente_periodo', ['competencia', 'codigo_cliente_omie'], $window);
        $geoDuplicateColumns = ['competencia', 'codigo_cliente_omie', 'uf', 'cidade_norm', 'bairro_norm'];
        if ($this->columnExists('mart_compradores_por_geo', 'codigo_vendedor_omie')) {
            array_splice($geoDuplicateColumns, 1, 0, ['codigo_vendedor_omie']);
        }
        $duplicatesGeo = $this->duplicateCount('mart_compradores_por_geo', $geoDuplicateColumns, $window);
        $reconciliation = $this->reconciliation($source, $after);

        $checks = [
            'canonical_nfs_autorizadas' => $canonicalNfs,
            'mart_cliente_periodo_has_rows' => $canonicalNfs === 0 || $clienteRows > 0,
            'mart_compradores_por_geo_has_rows' => $canonicalNfs === 0 || $geoRows > 0,
            'bairro_count_positive' => $canonicalNfs === 0 || $geoBairros > 0,
            'outside_period_unchanged' => $outsideUnchanged,
            'duplicates_mart_cliente_periodo' => $duplicatesCliente,
            'duplicates_mart_compradores_por_geo' => $duplicatesGeo,
            'updated_refreshed_at' => ($after['mart_cliente_periodo_last_refreshed_at'] ?? null) !== null
                || ($after['mart_compradores_por_geo_last_refreshed_at'] ?? null) !== null,
            'api_sales_by_neighborhood_ready' => $geoRows > 0 || $canonicalNfs === 0,
            'revenue_reconciliation' => $reconciliation,
        ];

        $failures = [];
        foreach ([
            'mart_cliente_periodo_has_rows',
            'mart_compradores_por_geo_has_rows',
            'outside_period_unchanged',
        ] as $key) {
            if (empty($checks[$key])) {
                $failures[] = $key;
            }
        }
        if ($duplicatesCliente > 0 || $duplicatesGeo > 0) {
            $failures[] = 'duplicated_unique_keys';
        }

        $warnings = [];
        if ($canonicalNfs > 0 && $geoBairros === 0) {
            $warnings[] = 'bairro_count_zero';
        }
        if (($reconciliation['status'] ?? 'ok') !== 'ok') {
            $warnings[] = 'revenue_reconciliation_' . ($reconciliation['status'] ?? 'unknown');
        }

        return [
            'status' => $failures !== [] ? 'failed' : ($warnings !== [] ? 'warning' : 'ok'),
            'checks' => $checks,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    private function sourceSnapshot(MartRefreshWindow $window): array
    {
        $operationScopeFilter = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope')
            ? "\n               AND nf.operation_scope = 'commercial'"
            : '';
        // Mesmo universo dos marts (AbstractMartBuilder::visibilityFragment):
        // licitação entra pelo canal; só varejo invisível e intra-grupo ficam
        // fora. Com a flag sozinha, a fonte "perdia" a licitação que o mart tem.
        if ($this->columnExists('biomie_notas_fiscais_canonicas', 'business_channel')
            && $this->columnExists('biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible')
        ) {
            $visibilityFilter = "\n               AND (nf.business_channel = 'licitacao'"
                . " OR COALESCE(nf.is_biomie_commercial_visible, 1) = 1)";
        } elseif ($this->columnExists('biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible')) {
            $visibilityFilter = "\n               AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1";
        } elseif ($this->columnExists('biomie_notas_fiscais_canonicas', 'nome_vendedor')) {
            $visibilityFilter = "\n               AND NOT UPPER(nf.nome_vendedor) LIKE '%LICITAC%'";
        } else {
            $visibilityFilter = '';
        }

        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('nf')
            : 'nf.valor_total';

        $row = $this->fetchOne(
            "SELECT
                COUNT(*) AS canonical_nfs_autorizadas,
                COUNT(DISTINCT nf.codigo_cliente_omie) AS canonical_clientes,
                COALESCE(SUM(nf.valor_total), 0) AS canonical_valor_total_raw,
                COALESCE(SUM({$nfBasisExpr}), 0) AS canonical_valor_total,
                COALESCE(SUM(nf.valor_mercadorias), 0) AS canonical_valor_mercadorias
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada'
               AND " . TenantContext::sqlFilter('nf') . "
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end{$operationScopeFilter}{$visibilityFilter}",
            ['start' => $window->effectiveStart, 'end' => $window->effectiveEnd]
        );

        return [
            'omie_live_called' => false,
            'canonical_nfs_autorizadas' => (int) ($row['canonical_nfs_autorizadas'] ?? 0),
            'canonical_clientes' => (int) ($row['canonical_clientes'] ?? 0),
            'canonical_valor_total' => (float) ($row['canonical_valor_total'] ?? 0),
            'canonical_valor_total_raw' => (float) ($row['canonical_valor_total_raw'] ?? 0),
            'canonical_valor_mercadorias' => (float) ($row['canonical_valor_mercadorias'] ?? 0),
            'canonical_basis' => $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
                ? 'commercial_base'
                : 'valor_total',
        ];
    }

    /** @return array<string,mixed> */
    private function periodTableSnapshot(string $table, string $startComp, string $endComp, string $revenueColumn): array
    {
        if (!$this->tableExists($table)) {
            return [
                'total_rows' => 0,
                'period_rows' => 0,
                'outside_rows' => 0,
                'revenue' => 0.0,
                'last_refreshed_at' => null,
            ];
        }

        $row = $this->fetchOne(
            "SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN competencia BETWEEN :start_comp_rows AND :end_comp_rows THEN 1 ELSE 0 END) AS period_rows,
                SUM(CASE WHEN competencia BETWEEN :start_comp_outside AND :end_comp_outside THEN 0 ELSE 1 END) AS outside_rows,
                COALESCE(SUM(CASE WHEN competencia BETWEEN :start_comp_revenue AND :end_comp_revenue THEN {$revenueColumn} ELSE 0 END), 0) AS revenue,
                MAX(CASE WHEN competencia BETWEEN :start_comp_refresh AND :end_comp_refresh THEN refreshed_at ELSE NULL END) AS last_refreshed_at
             FROM {$table}
             WHERE " . TenantContext::sqlFilter(),
            [
                'start_comp_rows' => $startComp,
                'end_comp_rows' => $endComp,
                'start_comp_outside' => $startComp,
                'end_comp_outside' => $endComp,
                'start_comp_revenue' => $startComp,
                'end_comp_revenue' => $endComp,
                'start_comp_refresh' => $startComp,
                'end_comp_refresh' => $endComp,
            ]
        );

        return [
            'total_rows' => (int) ($row['total_rows'] ?? 0),
            'period_rows' => (int) ($row['period_rows'] ?? 0),
            'outside_rows' => (int) ($row['outside_rows'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'last_refreshed_at' => $row['last_refreshed_at'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function reconciliation(array $source, array $after): array
    {
        $canonicalTotal = (float) ($source['canonical_valor_total'] ?? 0);
        $canonicalMerc = (float) ($source['canonical_valor_mercadorias'] ?? 0);
        $clienteNfe = (float) ($after['mart_cliente_periodo_revenue'] ?? 0);
        $geoFat = (float) ($after['mart_compradores_por_geo_revenue'] ?? 0);
        $diffCanonicalCliente = round($canonicalTotal - $clienteNfe, 2);
        $diffClienteGeo = round($clienteNfe - $geoFat, 2);
        $tolerance = 0.01;
        $maxDiff = max(abs($diffCanonicalCliente), abs($diffClienteGeo));

        return [
            'canonical_valor_total' => $canonicalTotal,
            'canonical_valor_mercadorias' => $canonicalMerc,
            'mart_cliente_periodo_valor_nfe' => $clienteNfe,
            'mart_compradores_geo_faturamento' => $geoFat,
            'diff_canonical_vs_cliente_periodo' => $diffCanonicalCliente,
            'diff_cliente_periodo_vs_geo' => $diffClienteGeo,
            'tolerance' => $tolerance,
            'status' => $maxDiff <= $tolerance ? 'ok' : ($maxDiff <= 100 ? 'warning' : 'critical'),
        ];
    }

    /** @return array<string,mixed> */
    private function diagnosis(array $source, array $snapshot): array
    {
        $canonicalNfs = (int) ($source['canonical_nfs_autorizadas'] ?? 0);
        $clienteRows = (int) ($snapshot['mart_cliente_periodo_period'] ?? 0);
        $geoRows = (int) ($snapshot['mart_compradores_por_geo_period'] ?? 0);
        $geoBairros = (int) ($snapshot['mart_compradores_por_geo_bairros'] ?? 0);

        if ($canonicalNfs === 0) {
            return [
                'map_should_populate' => false,
                'next_action' => 'Verificar sync Omie/canonical ou ampliar o periodo; nao ha NFs autorizadas locais.',
            ];
        }
        if ($clienteRows === 0) {
            return [
                'map_should_populate' => false,
                'next_action' => 'Execute rebuild de mart_cliente_periodo para o periodo.',
            ];
        }
        if ($geoRows === 0) {
            return [
                'map_should_populate' => false,
                'next_action' => 'Execute rebuild geografico para o periodo.',
            ];
        }
        if ($geoBairros === 0) {
            return [
                'map_should_populate' => false,
                'next_action' => 'Verifique clientes sem bairro e match GeoJSON x API.',
            ];
        }

        return [
            'map_should_populate' => true,
            'next_action' => 'Abrir mapa_vendas.php e validar pintura dos bairros.',
        ];
    }

    private function geoDistinctNeighborhoods(MartRefreshWindow $window): int
    {
        $row = $this->fetchOne(
            "SELECT COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' THEN bairro_norm END) AS n
             FROM mart_compradores_por_geo
             WHERE " . TenantContext::sqlFilter() . "
               AND competencia BETWEEN :start_comp AND :end_comp",
            ['start_comp' => $window->startCompetencia, 'end_comp' => $window->endCompetencia]
        );
        return (int) ($row['n'] ?? 0);
    }

    private function geoDistinctClients(MartRefreshWindow $window): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(DISTINCT codigo_cliente_omie) AS n
             FROM mart_compradores_por_geo
             WHERE ' . TenantContext::sqlFilter() . '
               AND competencia BETWEEN :start_comp AND :end_comp',
            ['start_comp' => $window->startCompetencia, 'end_comp' => $window->endCompetencia]
        );
        return (int) ($row['n'] ?? 0);
    }

    private function geoNotes(MartRefreshWindow $window): int
    {
        $row = $this->fetchOne(
            'SELECT COALESCE(SUM(qtd_notas), 0) AS n
             FROM mart_compradores_por_geo
             WHERE ' . TenantContext::sqlFilter() . '
               AND competencia BETWEEN :start_comp AND :end_comp',
            ['start_comp' => $window->startCompetencia, 'end_comp' => $window->endCompetencia]
        );
        return (int) ($row['n'] ?? 0);
    }

    /** @param list<string> $columns */
    private function duplicateCount(string $table, array $columns, MartRefreshWindow $window): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $group = implode(', ', array_map(static fn (string $col): string => "`{$col}`", $columns));
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS n FROM (
                SELECT {$group}, COUNT(*) AS c
                FROM {$table}
                WHERE " . TenantContext::sqlFilter() . "
                  AND competencia BETWEEN :start_comp AND :end_comp
                GROUP BY {$group}
                HAVING c > 1
            ) d",
            ['start_comp' => $window->startCompetencia, 'end_comp' => $window->endCompetencia]
        );
        return (int) ($row['n'] ?? 0);
    }

    private function makePipelineRepository(): ?PipelineStatusRepository
    {
        return $this->tableExists('biomie_pipeline_jobs')
            ? new PipelineStatusRepository($this->pdo, $this->logger)
            : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function fetchOne(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoPeriodRebuildService: consulta de diagnostico falhou.', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}
