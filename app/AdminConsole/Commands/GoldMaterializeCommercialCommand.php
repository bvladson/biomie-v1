<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\CommercialMaterializationService;
use BIomie\Config;
use BIomie\Database;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\TableHealthService;
use PDO;
use Throwable;

final class GoldMaterializeCommercialCommand implements AdminCommandInterface
{
    private const ALLOWED_SCOPES = ['all'];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string { return 'gold:materialize-commercial'; }
    public function description(): string { return 'Executa materializacao comercial real (bridge + marts). Nao chama API Omie.'; }
    public function group(): string { return 'gold'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return true; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        $scope = $args['scope'] ?? 'all';
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            return 'Parametro scope invalido. Use: all.';
        }
        $dryRun = $args['dry_run'] ?? '';
        if (!in_array($dryRun, ['', '0', '1', 'true', 'false'], true)) {
            return 'Parametro dry_run invalido. Use: 0 ou 1.';
        }
        return null;
    }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $dryRun = in_array($args['dry_run'] ?? '0', ['1', 'true'], true);

        try {
            $pdo = Database::connect($this->config, $this->connectionName);
            $tableHealth = new TableHealthService($pdo, $this->logger);

            if ($dryRun) {
                return $this->runDryRun($pdo, $tableHealth);
            }

            $pipeline = $tableHealth->tableExists('biomie_pipeline_jobs')
                ? new PipelineStatusRepository($pdo, $this->logger)
                : null;

            $builder = new GoldMartBuilder($pdo, $this->logger, $pipeline);
            $service = new CommercialMaterializationService($pdo, $this->logger, $builder, $tableHealth);

            $report = $service->run($context->userId);

            $ok = (bool) ($report['ok'] ?? false);
            $status = (string) ($report['status'] ?? 'unknown');
            $durationMs = (int) ($report['duration_ms'] ?? 0);

            $builderCounts = $report['builder_counts'] ?? [];
            $metrics = array_merge(
                ['status' => $status, 'duration_ms' => $durationMs],
                is_array($builderCounts) ? $builderCounts : [],
            );

            $lines = [];
            foreach ($metrics as $k => $v) {
                if (is_scalar($v)) {
                    $lines[] = "{$k}: {$v}";
                }
            }

            if ($ok) {
                return AdminCommandResult::ok(
                    'Materializacao comercial',
                    'Visao comercial atualizada com sucesso.',
                    outputLines: $lines,
                    metrics: $metrics,
                )->withDurationMs($durationMs);
            }

            $errors = is_array($report['errors'] ?? null) ? array_values(array_map('strval', $report['errors'])) : [];
            $warnings = is_array($report['warnings'] ?? null) ? array_values(array_map('strval', $report['warnings'])) : [];

            if ($warnings !== [] && $errors === []) {
                return AdminCommandResult::warning(
                    'Materializacao comercial',
                    "Materializacao concluida com avisos. Status: {$status}.",
                    outputLines: $lines,
                    warnings: $warnings,
                )->withDurationMs($durationMs);
            }

            return AdminCommandResult::error(
                'Materializacao comercial',
                "Materializacao nao concluida. Status: {$status}.",
                errors: $errors !== [] ? $errors : ["Status final: {$status}"],
            );
        } catch (Throwable $e) {
            $this->logger->error('AdminConsole: gold:materialize-commercial falhou.', [
                'message' => $e->getMessage(),
                'user_id' => $context->userId,
            ]);
            return AdminCommandResult::error(
                'Materializacao comercial',
                'Nao foi possivel executar a materializacao agora.',
                errors: ['Erro tecnico registrado no log de observabilidade.'],
            );
        }
    }

    private function runDryRun(
        PDO $pdo,
        TableHealthService $tableHealth,
    ): AdminCommandResult {
        $sourceTables = [
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
        ];
        $targetTables = [
            'mart_bridge_pedido_nfe_titulo',
            'mart_comercial_pedido',
            'mart_fiscal_nfe',
            'mart_vendedor_dia',
            'mart_cliente_periodo',
            'mart_produto_periodo',
        ];

        $lines = ['[DRY RUN] Nenhum dado sera alterado.'];
        $rows = [];

        foreach ($sourceTables as $table) {
            $exists = $tableHealth->tableExists($table);
            $count = $exists ? (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn() : 0;
            $rows[] = [$table, 'fonte', $exists ? 'ok' : 'ausente', number_format($count, 0, ',', '.')];
        }
        foreach ($targetTables as $table) {
            $exists = $tableHealth->tableExists($table);
            $count = $exists ? (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn() : 0;
            $rows[] = [$table, 'destino', $exists ? ($count > 0 ? 'ok' : 'vazio') : 'ausente', number_format($count, 0, ',', '.')];
        }

        $tables = [[
            'title'   => 'Simulacao (dry run)',
            'headers' => ['Tabela', 'Tipo', 'Status atual', 'Registros atuais'],
            'rows'    => $rows,
        ]];

        return AdminCommandResult::ok(
            'Materializacao comercial (dry run)',
            'Simulacao concluida. Nenhum dado foi alterado. Execute sem dry_run para materializar.',
            outputLines: $lines,
            tables: $tables,
        );
    }
}
