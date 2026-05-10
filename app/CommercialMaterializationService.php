<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\SecretMask;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class CommercialMaterializationService
{
    /** @var array<string,string> */
    private const SOURCE_TABLES = [
        'pedidos_canonicos' => 'biomie_pedidos_canonicos',
        'notas_fiscais_canonicas' => 'biomie_notas_fiscais_canonicas',
        'itens_nf_canonicos' => 'biomie_notas_fiscais_itens_canonicos',
        'vendedores_omie' => 'biomie_vendedores_omie',
        'bronze_omie' => 'biomie_omie_bronze',
        'titulos_financeiros_canonicos' => 'biomie_titulos_financeiros_canonicos',
        'clientes_dim' => 'biomie_clientes_dim',
        'clientes_geo_dim' => 'biomie_clientes_geo_dim',
    ];

    /** @var array<string,string> */
    private const REQUIRED_SOURCE_TABLES = [
        'pedidos_canonicos' => 'biomie_pedidos_canonicos',
        'notas_fiscais_canonicas' => 'biomie_notas_fiscais_canonicas',
        'itens_nf_canonicos' => 'biomie_notas_fiscais_itens_canonicos',
    ];

    /** @var array<string,string> */
    private const TARGET_TABLES = [
        'mart_bridge_pedido_nfe_titulo' => 'mart_bridge_pedido_nfe_titulo',
        'mart_comercial_pedido' => 'mart_comercial_pedido',
        'mart_fiscal_nfe' => 'mart_fiscal_nfe',
        'mart_vendedor_dia' => 'mart_vendedor_dia',
        'mart_cliente_periodo' => 'mart_cliente_periodo',
        'mart_produto_periodo' => 'mart_produto_periodo',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly GoldMartBuilder $builder,
        private readonly TableHealthService $tableHealth,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(?int $actorUserId = null, ?string $start = null, ?string $end = null): array
    {
        $startedAtMicro = microtime(true);
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('gold.materialize.commercial', [
            'domain_name' => 'gold',
            'source_name' => 'commercial_materializer',
            'trigger' => 'manual_admin',
            'user_id' => $actorUserId,
        ], 'gold', 'commercial_materializer');

        $report = [
            'ok' => false,
            'status' => 'pending',
            'started_at' => date('Y-m-d H:i:s'),
            'ended_at' => null,
            'duration_ms' => 0,
            'date_range' => null,
            'source_counts' => [],
            'source_period_counts' => [],
            'target_before_counts' => [],
            'deleted_counts' => [],
            'builder_counts' => [],
            'target_after_counts' => [],
            'inserted_counts' => [],
            'skipped_counts' => [],
            'warnings' => [],
            'errors' => [],
            'error_meta' => [],
            'zero_reason' => null,
        ];

        try {
            $report['source_counts'] = $this->countTables(self::SOURCE_TABLES);
            $report['skipped_counts'] = $this->skippedCounts();

            $telemetry?->recordEvent('info', 'gold.materialize.started', 'Materializacao comercial iniciada.', [
                'source_counts' => $report['source_counts'],
                'targets' => array_values(self::TARGET_TABLES),
                'actor_user_id' => $actorUserId,
            ], 'gold', 'commercial_materializer');

            $missingSourceTables = $this->missingTables(self::REQUIRED_SOURCE_TABLES);
            if ($missingSourceTables !== []) {
                $report['status'] = 'missing_source_tables';
                $report['errors'][] = 'Tabelas canonicas ausentes: ' . implode(', ', $missingSourceTables) . '.';
                $report['zero_reason'] = 'missing_source_tables';
                $this->finishReport($report, $startedAtMicro);
                $this->recordFailed($telemetry, $span, $report);
                return $report;
            }

            $range = $this->resolveDateRange($start, $end);
            if ($range === null) {
                $report['status'] = 'source_without_dates';
                $report['zero_reason'] = 'source_without_dates';
                $report['warnings'][] = 'Nao foi encontrada data de referencia nas tabelas canonicas.';
                $this->finishReport($report, $startedAtMicro);
                $this->recordFinished($telemetry, $span, $report);
                return $report;
            }

            $report['date_range'] = $range;
            $report['source_period_counts'] = $this->sourceCountsInRange($range['start'], $range['end']);

            $missingTargets = $this->missingTables(self::TARGET_TABLES);
            if ($missingTargets !== []) {
                $report['status'] = 'missing_target_tables';
                $report['errors'][] = 'Tabelas gold ausentes: ' . implode(', ', $missingTargets) . '.';
                $report['zero_reason'] = 'missing_target_tables';
                $this->finishReport($report, $startedAtMicro);
                $this->recordFailed($telemetry, $span, $report);
                return $report;
            }

            $sourceCoreTotal = $this->sumCounts($report['source_counts'], [
                'pedidos_canonicos',
                'notas_fiscais_canonicas',
                'itens_nf_canonicos',
            ]);
            if ($sourceCoreTotal === 0) {
                $report['status'] = 'source_empty';
                $report['zero_reason'] = 'source_empty';
                $report['warnings'][] = 'As tabelas canonicas principais nao possuem registros.';
                $this->finishReport($report, $startedAtMicro);
                $this->recordFinished($telemetry, $span, $report);
                return $report;
            }

            $periodCoreTotal = $this->sumCounts($report['source_period_counts'], [
                'pedidos_canonicos',
                'notas_fiscais_canonicas',
                'itens_nf_canonicos',
            ]);
            if ($periodCoreTotal === 0) {
                $report['status'] = 'source_outside_period';
                $report['zero_reason'] = 'source_outside_period';
                $report['warnings'][] = 'Ha dados canonicos, mas nenhum registro entrou no periodo de materializacao.';
                $this->finishReport($report, $startedAtMicro);
                $this->recordFinished($telemetry, $span, $report);
                return $report;
            }

            $report['target_before_counts'] = $this->countTables(self::TARGET_TABLES);

            $this->pdo->beginTransaction();
            try {
                $report['deleted_counts'] = $this->deleteTargets();
                $report['builder_counts'] = $this->builder->refreshCommercialPeriod($range['start'], $range['end']);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            $report['target_after_counts'] = $this->countTables(self::TARGET_TABLES);
            $report['inserted_counts'] = $report['target_after_counts'];
            $this->appendDomainWarnings($report);

            $materializedRows = $this->sumCounts($report['target_after_counts'], array_keys(self::TARGET_TABLES));
            if ($materializedRows > 0) {
                $report['ok'] = true;
                $report['status'] = 'ok';
            } else {
                $report['status'] = 'warning_zero_rows';
                $report['zero_reason'] = 'target_zero_after_run';
                $report['warnings'][] = 'A rotina foi executada, mas todas as tabelas mart continuaram vazias.';
            }

            $this->finishReport($report, $startedAtMicro);
            $this->recordFinished($telemetry, $span, $report);
            return $report;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $report['status'] = 'failed';
            $report['errors'][] = SecretMask::sanitizeErrorMessage($exception->getMessage(), 300);

            $sqlstate        = null;
            $driverErrorCode = null;
            if ($exception instanceof PDOException && is_array($exception->errorInfo)) {
                $sqlstate        = isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : null;
                $driverErrorCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;
            } elseif (is_scalar($exception->getCode()) && $exception->getCode() !== 0) {
                $sqlstate = (string) $exception->getCode();
            }

            $builderStage = $this->builder->getLastStage();
            $builderTable = $this->builder->getLastTable();
            $builderLabel = $this->builder->getLastSqlLabel();

            $report['error_meta'][] = [
                'stage'                   => $builderStage !== '' ? $builderStage : 'materialization',
                'failed_table'            => $builderTable !== '' ? $builderTable : null,
                'failed_method'           => $builderLabel !== '' ? 'GoldMartBuilder::' . $builderLabel : null,
                'last_sql_label'          => $builderLabel !== '' ? $builderLabel : null,
                'exception'               => get_class($exception),
                'sqlstate'                => $sqlstate,
                'driver_error_code'       => $driverErrorCode,
                'sanitized_error_message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 300),
                'placeholders_found'      => $this->builder->getLastPlaceholders(),
                'params_supplied'         => $this->builder->getLastParamsSupplied(),
                'missing_params'          => $this->builder->getLastMissingParams(),
                'extra_params'            => $this->builder->getLastExtraParams(),
            ];
            $report['zero_reason'] = 'exception';
            $this->finishReport($report, $startedAtMicro);

            $this->logger->error('CommercialMaterializationService: falha na materializacao comercial.', [
                'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 500),
            ]);

            $this->recordFailed($telemetry, $span, $report);
            return $report;
        }
    }

    /**
     * @param array<string,string> $tables
     * @return array<string,int|null>
     */
    private function countTables(array $tables): array
    {
        $counts = [];
        foreach ($tables as $alias => $table) {
            $counts[$alias] = $this->countTable($table);
        }

        return $counts;
    }

    private function countTable(string $table): ?int
    {
        if (!$this->tableHealth->tableExists($table)) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $this->escapeIdentifier($table)));
            return $stmt !== false ? (int) $stmt->fetchColumn() : null;
        } catch (Throwable $exception) {
            $this->logger->warning('CommercialMaterializationService: falha ao contar tabela.', [
                'table' => $table,
                'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 200),
            ]);
            return null;
        }
    }

    /**
     * @param array<string,string> $tables
     * @return list<string>
     */
    private function missingTables(array $tables): array
    {
        $missing = [];
        foreach ($tables as $table) {
            if (!$this->tableHealth->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return array{start:string,end:string,label:string}|null
     */
    private function resolveDateRange(?string $start, ?string $end): ?array
    {
        if ($start !== null || $end !== null) {
            $start = $this->normalizeDate($start, 'start');
            $end = $this->normalizeDate($end, 'end');
            if ($start > $end) {
                throw new InvalidArgumentException('A data inicial deve ser menor ou igual a data final.');
            }

            return [
                'start' => $start,
                'end' => $end,
                'label' => $this->formatRangeLabel($start, $end),
            ];
        }

        $parts = [];
        if ($this->tableHealth->tableExists('biomie_pedidos_canonicos')) {
            $parts[] = "SELECT MIN(COALESCE(data_faturamento, data_emissao)) AS start_date, MAX(COALESCE(data_faturamento, data_emissao)) AS end_date FROM biomie_pedidos_canonicos WHERE COALESCE(data_faturamento, data_emissao) IS NOT NULL";
        }
        if ($this->tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
            $parts[] = "SELECT MIN(COALESCE(data_competencia, data_emissao)) AS start_date, MAX(COALESCE(data_competencia, data_emissao)) AS end_date FROM biomie_notas_fiscais_canonicas WHERE COALESCE(data_competencia, data_emissao) IS NOT NULL";
        }
        if ($this->tableHealth->tableExists('biomie_titulos_financeiros_canonicos')) {
            $parts[] = "SELECT MIN(COALESCE(data_competencia, data_vencimento)) AS start_date, MAX(COALESCE(data_competencia, data_vencimento)) AS end_date FROM biomie_titulos_financeiros_canonicos WHERE COALESCE(data_competencia, data_vencimento) IS NOT NULL";
        }

        if ($parts === []) {
            return null;
        }

        $sql = 'SELECT MIN(start_date) AS start_date, MAX(end_date) AS end_date FROM (' . implode(' UNION ALL ', $parts) . ') ranges';
        $stmt = $this->pdo->query($sql);
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $rangeStart = is_array($row) ? (string) ($row['start_date'] ?? '') : '';
        $rangeEnd = is_array($row) ? (string) ($row['end_date'] ?? '') : '';

        if ($rangeStart === '' || $rangeEnd === '') {
            return null;
        }

        return [
            'start' => substr($rangeStart, 0, 10),
            'end' => substr($rangeEnd, 0, 10),
            'label' => $this->formatRangeLabel(substr($rangeStart, 0, 10), substr($rangeEnd, 0, 10)),
        ];
    }

    /**
     * @return array<string,int|null>
     */
    private function sourceCountsInRange(string $start, string $end): array
    {
        return [
            'pedidos_canonicos' => $this->countWhere(
                'biomie_pedidos_canonicos',
                'COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end',
                ['start' => $start, 'end' => $end]
            ),
            'notas_fiscais_canonicas' => $this->countWhere(
                'biomie_notas_fiscais_canonicas',
                'COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end',
                ['start' => $start, 'end' => $end]
            ),
            'itens_nf_canonicos' => $this->countInvoiceItemsInRange($start, $end),
            'titulos_financeiros_canonicos' => $this->countWhere(
                'biomie_titulos_financeiros_canonicos',
                'COALESCE(data_competencia, data_vencimento) BETWEEN :start AND :end',
                ['start' => $start, 'end' => $end]
            ),
        ];
    }

    /**
     * @param array<string,string> $params
     */
    private function countWhere(string $table, string $where, array $params): ?int
    {
        if (!$this->tableHealth->tableExists($table)) {
            return null;
        }

        $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $this->escapeIdentifier($table), $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function countInvoiceItemsInRange(string $start, string $end): ?int
    {
        if (!$this->tableHealth->tableExists('biomie_notas_fiscais_itens_canonicos')
            || !$this->tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM biomie_notas_fiscais_itens_canonicos i
             JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
             WHERE COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,int|null>
     */
    private function skippedCounts(): array
    {
        return [
            'pedidos_sem_data' => $this->countWhereIfExists('biomie_pedidos_canonicos', 'COALESCE(data_faturamento, data_emissao) IS NULL'),
            'notas_sem_data' => $this->countWhereIfExists('biomie_notas_fiscais_canonicas', 'COALESCE(data_competencia, data_emissao) IS NULL'),
            'pedidos_sem_cliente_omie' => $this->countWhereIfExists('biomie_pedidos_canonicos', 'codigo_cliente_omie IS NULL'),
            'notas_sem_cliente_omie' => $this->countWhereIfExists('biomie_notas_fiscais_canonicas', 'codigo_cliente_omie IS NULL'),
            'notas_sem_documento' => $this->countWhereIfExists('biomie_notas_fiscais_canonicas', "documento_cliente IS NULL OR TRIM(documento_cliente) = ''"),
        ];
    }

    private function countWhereIfExists(string $table, string $where): ?int
    {
        if (!$this->tableHealth->tableExists($table)) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $this->escapeIdentifier($table), $where));
            return $stmt !== false ? (int) $stmt->fetchColumn() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,int>
     */
    private function deleteTargets(): array
    {
        $deleted = [];
        foreach (self::TARGET_TABLES as $alias => $table) {
            $stmt = $this->pdo->prepare(sprintf('DELETE FROM `%s`', $this->escapeIdentifier($table)));
            $stmt->execute();
            $deleted[$alias] = (int) $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function appendDomainWarnings(array &$report): void
    {
        if ((int) ($report['source_counts']['clientes_dim'] ?? 0) === 0) {
            $report['warnings'][] = 'Clientes = 0. Execute sincronizacao/enriquecimento de clientes para melhorar cliente, CNAE e geografia.';
        }
        if ((int) ($report['source_counts']['clientes_geo_dim'] ?? 0) === 0) {
            $report['warnings'][] = 'Geo = 0. A leitura geografica depende de biomie_clientes_geo_dim.';
        }
        if ((int) ($report['source_counts']['titulos_financeiros_canonicos'] ?? 0) === 0) {
            $report['warnings'][] = 'Titulos = 0. A ponte financeira ficara parcial ate a sincronizacao financeira.';
        }
        if (($report['target_after_counts']['mart_fiscal_nfe'] ?? 0) === 0
            && ($report['source_period_counts']['notas_fiscais_canonicas'] ?? 0) > 0) {
            $report['warnings'][] = 'Ha NF canonicas no periodo, mas mart_fiscal_nfe ficou vazio.';
        }
        if (($report['target_after_counts']['mart_produto_periodo'] ?? 0) === 0
            && ($report['source_period_counts']['itens_nf_canonicos'] ?? 0) > 0) {
            $report['warnings'][] = 'Ha itens de NF no periodo, mas mart_produto_periodo ficou vazio.';
        }
    }

    /**
     * @param array<string,int|null> $counts
     * @param list<string> $keys
     */
    private function sumCounts(array $counts, array $keys): int
    {
        $sum = 0;
        foreach ($keys as $key) {
            $sum += (int) ($counts[$key] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function finishReport(array &$report, float $startedAtMicro): void
    {
        $report['ended_at'] = date('Y-m-d H:i:s');
        $report['duration_ms'] = max(0, (int) round((microtime(true) - $startedAtMicro) * 1000));
    }

    /**
     * @param array<string,mixed> $report
     */
    private function recordFinished(?Telemetry $telemetry, ?TelemetrySpan $span, array $report): void
    {
        $level = $report['ok'] === true ? 'info' : 'warning';
        $summary = $report['ok'] === true
            ? 'Materializacao comercial concluida.'
            : 'Materializacao comercial executada sem registros materializados.';

        $telemetry?->recordEvent($level, 'gold.materialize.finished', $summary, $this->eventContext($report), 'gold', 'commercial_materializer');
        $span?->finish((string) ($report['status'] ?? 'unknown'), $this->spanResult($report));
    }

    /**
     * @param array<string,mixed> $report
     */
    private function recordFailed(?Telemetry $telemetry, ?TelemetrySpan $span, array $report): void
    {
        $telemetry?->recordEvent('error', 'gold.materialize.failed', 'Materializacao comercial falhou.', $this->eventContext($report), 'gold', 'commercial_materializer');
        $span?->finish('failed', $this->spanResult($report));
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function eventContext(array $report): array
    {
        return [
            'date_range' => $report['date_range'] ?? null,
            'source_counts' => $report['source_counts'] ?? [],
            'source_period_counts' => $report['source_period_counts'] ?? [],
            'target_before_counts' => $report['target_before_counts'] ?? [],
            'target_after_counts' => $report['target_after_counts'] ?? [],
            'inserted_counts' => $report['inserted_counts'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'errors' => $report['errors'] ?? [],
            'error_meta' => $report['error_meta'] ?? [],
            'zero_reason' => $report['zero_reason'] ?? null,
            'duration_ms' => $report['duration_ms'] ?? 0,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function spanResult(array $report): array
    {
        return [
            'ok' => $report['ok'] ?? false,
            'status' => $report['status'] ?? 'unknown',
            'source_counts' => $report['source_counts'] ?? [],
            'inserted_counts' => $report['inserted_counts'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'zero_reason' => $report['zero_reason'] ?? null,
        ];
    }

    private function normalizeDate(?string $value, string $field): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' deve seguir o formato YYYY-MM-DD.');
        }

        return $value;
    }

    private function formatRangeLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
            return $start . ' ate ' . $end;
        }

        return $startDate->format('d/m/Y') . ' ate ' . $endDate->format('d/m/Y');
    }

    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }
}
