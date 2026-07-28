<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

final class DbCountsCommand implements AdminCommandInterface
{
    private const GROUPS = [
        'bronze'    => ['biomie_omie_bronze'],
        'canonical' => [
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
            'biomie_vendedores_omie',
            'biomie_titulos_financeiros_canonicos',
        ],
        'gold' => [
            'mart_comercial_pedido',
            'mart_fiscal_nfe',
            'mart_cliente_periodo',
            'mart_produto_periodo',
            'mart_vendedor_dia',
            'mart_bridge_pedido_nfe_titulo',
            'mart_compradores_por_geo',
            'mart_vendas_por_cnae_cnpj',
        ],
        'admin' => ['biomie_users', 'biomie_admin_logs'],
    ];

    private const ALLOWED_GROUPS = ['all', 'canonical', 'gold', 'omie', 'bronze', 'admin'];

    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'db:counts'; }
    public function description(): string { return 'Exibe contagem de registros nas principais tabelas.'; }
    public function group(): string { return 'banco'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        $group = $args['group'] ?? 'all';
        if (!in_array($group, self::ALLOWED_GROUPS, true)) {
            return 'Parametro group invalido. Use: ' . implode(', ', self::ALLOWED_GROUPS) . '.';
        }
        return null;
    }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $filterGroup = $args['group'] ?? 'all';

        $groupsToQuery = $filterGroup === 'all'
            ? self::GROUPS
            : (isset(self::GROUPS[$filterGroup]) ? [$filterGroup => self::GROUPS[$filterGroup]] : self::GROUPS);

        $tables = [];
        $metrics = [];

        foreach ($groupsToQuery as $groupKey => $tableNames) {
            $rows = [];
            foreach ($tableNames as $table) {
                try {
                    $existsStmt = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                    );
                    $existsStmt->execute([':t' => $table]);
                    $exists = (int) $existsStmt->fetchColumn() > 0;

                    if (!$exists) {
                        $rows[] = [$table, 'ausente', '-'];
                        continue;
                    }

                    $count = (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
                    $status = $count > 0 ? 'ok' : 'vazio';
                    $rows[] = [$table, $status, number_format($count, 0, ',', '.')];
                    $metrics[$table] = $count;
                } catch (Throwable) {
                    $rows[] = [$table, 'erro', '-'];
                }
            }

            $tables[] = [
                'title'   => ucfirst($groupKey),
                'headers' => ['Tabela', 'Status', 'Registros'],
                'rows'    => $rows,
            ];
        }

        $total = array_sum($metrics);

        return AdminCommandResult::ok(
            'Contagem de tabelas',
            sprintf('Total de %s registros nas tabelas consultadas.', number_format($total, 0, ',', '.')),
            metrics: $metrics,
            tables: $tables,
        );
    }
}
