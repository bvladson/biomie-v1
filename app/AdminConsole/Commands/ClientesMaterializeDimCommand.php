<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Config;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\Logger;
use BIomie\TableHealthService;
use Throwable;

final class ClientesMaterializeDimCommand implements AdminCommandInterface
{
    private const ALLOWED_REBUILD = ['', '0', '1', 'true', 'false'];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string { return 'clientes:materialize-dim'; }
    public function description(): string { return 'Materializa biomie_clientes_dim a partir de pedidos/NF canonicos. Nao chama API Omie.'; }
    public function group(): string { return 'clientes'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return true; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        $dryRun = $args['dry_run'] ?? '';
        if (!in_array($dryRun, ['', '0', '1', 'true', 'false'], true)) {
            return 'Parametro dry_run invalido. Use: 0 ou 1.';
        }
        $rebuild = $args['rebuild'] ?? '';
        if (!in_array($rebuild, self::ALLOWED_REBUILD, true)) {
            return 'Parametro rebuild invalido. Use: 0 ou 1.';
        }
        return null;
    }

    /**
     * @param array<string,string> $args
     */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $dryRun = in_array($args['dry_run'] ?? '0', ['1', 'true'], true);

        try {
            $pdo = Database::connect($this->config, $this->connectionName);
            $tableHealth = new TableHealthService($pdo, $this->logger);

            if ($dryRun) {
                return $this->runDryRun($pdo, $tableHealth);
            }

            $service = new CustomerDimensionMaterializationService($pdo, $this->logger, $tableHealth);
            $report  = $service->run($context->userId);

            $ok         = (bool) ($report['ok'] ?? false);
            $status     = (string) ($report['status'] ?? 'unknown');
            $durationMs = (int) ($report['duration_ms'] ?? 0);

            $fc = is_array($report['final_counts'] ?? null) ? $report['final_counts'] : [];
            $cc = is_array($report['candidate_counts'] ?? null) ? $report['candidate_counts'] : [];
            $performance = is_array($report['performance'] ?? null) ? $report['performance'] : [];

            $metrics = array_merge(
                ['status' => $status, 'duration_ms' => $durationMs],
                [
                    'inserted' => $report['inserted_count'] ?? 0,
                    'updated' => $report['updated_count'] ?? 0,
                    'unchanged' => $report['unchanged_count'] ?? 0,
                ],
                array_filter($fc, 'is_scalar'),
                array_filter($performance, 'is_scalar'),
            );

            $lines = array_map(
                static fn (string $k, mixed $v): string => is_scalar($v) ? "{$k}: {$v}" : '',
                array_keys($metrics),
                array_values($metrics),
            );
            $lines = array_values(array_filter($lines));

            $warnings = is_array($report['warnings'] ?? null) ? array_values(array_map('strval', $report['warnings'])) : [];
            $errors   = is_array($report['errors'] ?? null)   ? array_values(array_map('strval', $report['errors'])) : [];

            if ($ok) {
                return AdminCommandResult::ok(
                    'Dimensao de clientes',
                    sprintf(
                        'Dimensao materializada: %d inseridos, %d atualizados, %d sem mudanca.',
                        (int) ($report['inserted_count'] ?? 0),
                        (int) ($report['updated_count'] ?? 0),
                        (int) ($report['unchanged_count'] ?? 0),
                    ),
                    outputLines: $lines,
                    warnings:    $warnings,
                    metrics:     $metrics,
                )->withDurationMs($durationMs);
            }

            if ($warnings !== [] && $errors === []) {
                return AdminCommandResult::warning(
                    'Dimensao de clientes',
                    "Materializacao concluida com avisos. Status: {$status}.",
                    outputLines: $lines,
                    warnings:    $warnings,
                )->withDurationMs($durationMs);
            }

            return AdminCommandResult::error(
                'Dimensao de clientes',
                "Materializacao nao concluida. Status: {$status}.",
                errors: $errors !== [] ? $errors : ["Status final: {$status}"],
            );
        } catch (Throwable $e) {
            $this->logger->error('AdminConsole: clientes:materialize-dim falhou.', [
                'message' => $e->getMessage(),
                'user_id' => $context->userId,
            ]);
            return AdminCommandResult::error(
                'Dimensao de clientes',
                'Nao foi possivel executar a materializacao de clientes agora.',
                errors: ['Erro tecnico registrado no log de observabilidade.'],
            );
        }
    }

    private function runDryRun(
        \PDO $pdo,
        TableHealthService $tableHealth,
    ): AdminCommandResult {
        $sourceTables = [
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
        ];
        $targetTables = [
            'biomie_clientes_dim',
            'biomie_clientes_geo_dim',
        ];

        $rows  = [];
        $lines = ['[DRY RUN] Nenhum dado sera alterado.'];

        foreach ($sourceTables as $table) {
            $exists = $tableHealth->tableExists($table);
            $count  = $exists
                ? (int) $pdo->query(sprintf('SELECT COUNT(DISTINCT codigo_cliente_omie) FROM `%s` WHERE codigo_cliente_omie IS NOT NULL', $table))->fetchColumn()
                : 0;
            $rows[] = [$table, 'fonte', $exists ? 'ok' : 'ausente', number_format($count, 0, ',', '.') . ' clientes unicos'];
        }

        foreach ($targetTables as $table) {
            $exists = $tableHealth->tableExists($table);
            $count  = $exists ? (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn() : 0;
            $rows[] = [$table, 'destino', $exists ? ($count > 0 ? 'ok' : 'vazio') : 'ausente', number_format($count, 0, ',', '.')];
        }

        // Candidate preview
        if ($tableHealth->tableExists('biomie_pedidos_canonicos') || $tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
            $unionParts = [];
            if ($tableHealth->tableExists('biomie_pedidos_canonicos')) {
                $unionParts[] = 'SELECT codigo_cliente_omie, documento_cliente, nome_cliente, codigo_vendedor_omie, nome_vendedor FROM biomie_pedidos_canonicos WHERE codigo_cliente_omie IS NOT NULL';
            }
            if ($tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
                $unionParts[] = 'SELECT codigo_cliente_omie, documento_cliente, nome_cliente, codigo_vendedor_omie, nome_vendedor FROM biomie_notas_fiscais_canonicas WHERE codigo_cliente_omie IS NOT NULL';
            }

            if ($unionParts !== []) {
                $union = implode(' UNION ALL ', $unionParts);
                $preview = $pdo->query("
                    SELECT
                        COUNT(DISTINCT codigo_cliente_omie)                               AS total_candidatos,
                        COUNT(DISTINCT CASE WHEN documento_cliente IS NOT NULL AND documento_cliente != '' THEN codigo_cliente_omie END) AS com_documento,
                        COUNT(DISTINCT CASE WHEN (codigo_vendedor_omie IS NULL OR codigo_vendedor_omie = '' OR codigo_vendedor_omie = 'Sem vendedor') THEN codigo_cliente_omie END) AS sem_vendedor
                    FROM ({$union}) u
                ");

                if ($preview !== false) {
                    $r = $preview->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($r)) {
                        $total  = (int) $r['total_candidatos'];
                        $comDoc = (int) $r['com_documento'];
                        $semVend = (int) $r['sem_vendedor'];
                        $lines[] = "Candidatos unicos: {$total}";
                        $lines[] = "Com documento: {$comDoc} / Sem documento: " . ($total - $comDoc);
                        $lines[] = "Sem vendedor responsavel: {$semVend}";
                        $lines[] = "Clientes novos estimados: " . max(0, $total - (int) $pdo->query('SELECT COUNT(*) FROM biomie_clientes_dim')->fetchColumn());
                    }
                }
            }
        }

        $tables = [[
            'title'   => 'Simulacao (dry run)',
            'headers' => ['Tabela', 'Tipo', 'Status atual', 'Registros'],
            'rows'    => $rows,
        ]];

        return AdminCommandResult::ok(
            'Dimensao de clientes (dry run)',
            'Simulacao concluida. Nenhum dado foi alterado. Execute sem dry_run para materializar.',
            outputLines: $lines,
            tables:      $tables,
        );
    }
}
