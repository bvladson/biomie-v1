<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

final class GoldStatusCommand implements AdminCommandInterface
{
    private const MART_TABLES = [
        'mart_bridge_pedido_nfe_titulo' => 'Bridge pedido-NF-titulo',
        'mart_comercial_pedido'         => 'Comercial (pedidos)',
        'mart_fiscal_nfe'               => 'Fiscal (NFs)',
        'mart_financeiro_titulo'        => 'Financeiro (titulos)',
        'mart_vendedor_dia'             => 'Vendedor por dia',
        'mart_cliente_periodo'          => 'Cliente por periodo',
        'mart_produto_periodo'          => 'Produto por periodo',
        'mart_compradores_por_geo'      => 'Compradores por geo',
        'mart_vendas_por_cnae_cnpj'     => 'Vendas por CNAE/CNPJ',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'gold:status'; }
    public function description(): string { return 'Exibe status das tabelas gold/mart (existencia e contagem).'; }
    public function group(): string { return 'gold'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $rows = [];
        $warnings = [];
        $metrics = [];
        $allOk = true;

        foreach (self::MART_TABLES as $table => $label) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $stmt->execute([':t' => $table]);
                $exists = (int) $stmt->fetchColumn() > 0;

                if (!$exists) {
                    $rows[] = [$label, $table, 'ausente', '-', 'Tabela nao existe. Execute migrations.'];
                    $allOk = false;
                    $warnings[] = "Tabela {$table} nao existe.";
                    continue;
                }

                $count = (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
                $metrics[$table] = $count;

                if ($count === 0) {
                    $diagnostic = 'Estrutura pronta, materializacao pendente.';
                    $rows[] = [$label, $table, 'vazio', '0', $diagnostic];
                    $warnings[] = "Tabela {$table} esta vazia.";
                    $allOk = false;
                } else {
                    $rows[] = [$label, $table, 'ok', number_format($count, 0, ',', '.'), 'Materializado.'];
                }
            } catch (Throwable) {
                $rows[] = [$label, $table, 'erro', '-', 'Erro ao consultar.'];
                $allOk = false;
            }
        }

        $tables = [[
            'title'   => 'Tabelas Gold / Mart',
            'headers' => ['Label', 'Tabela', 'Status', 'Registros', 'Diagnostico'],
            'rows'    => $rows,
        ]];

        if (!$allOk) {
            return AdminCommandResult::warning(
                'Status Gold/Mart',
                'Algumas tabelas gold estao vazias ou ausentes. Execute gold:materialize-commercial.',
                warnings: $warnings,
                tables: $tables,
            );
        }

        return AdminCommandResult::ok(
            'Status Gold/Mart',
            'Todas as tabelas gold consultadas possuem dados.',
            metrics: $metrics,
            tables: $tables,
        );
    }
}
