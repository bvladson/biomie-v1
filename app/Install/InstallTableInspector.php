<?php

declare(strict_types=1);

namespace BIomie\Install;

use PDO;
use Throwable;

final class InstallTableInspector
{
    /**
     * @return array<string,list<array<string,mixed>>>
     */
    public function inspect(PDO $pdo): array
    {
        $groups = [
            'Essenciais' => ['biomie_schema_migrations'],
            'Autenticacao e admin' => [
                'biomie_users',
                'biomie_access_logs',
                'biomie_roles',
                'biomie_permissions',
                'biomie_role_permissions',
                'biomie_user_roles',
                'biomie_admin_logs',
            ],
            'Omie e bronze' => [
                'biomie_omie_events',
                'biomie_sync_runs',
                'biomie_pipeline_jobs',
            ],
            'Silver e canonicas' => [
                'biomie_pedidos_canonicos',
                'biomie_notas_fiscais_canonicas',
                'biomie_titulos_financeiros_canonicos',
                'biomie_clientes_dim',
            ],
            'Gold e analiticas' => [
                'mart_bridge_pedido_nfe_titulo',
                'mart_comercial_pedido',
                'mart_fiscal_nfe',
                'mart_financeiro_titulo',
                'mart_vendedor_dia',
                'mart_cliente_periodo',
                'mart_produto_periodo',
            ],
            'Geografia' => [
                'biomie_clientes_geo_dim',
                'biomie_geo_enrichment_queue',
                'biomie_geo_sales_fact',
                'mart_compradores_por_geo',
            ],
            'CNAE' => [
                'biomie_clientes_dim',
                'mart_vendas_por_cnae_cnpj',
            ],
            'Compradores' => [
                'mart_compradores_por_geo',
                'mart_vendas_por_cnae_cnpj',
            ],
        ];

        $result = [];
        foreach ($groups as $group => $tables) {
            $result[$group] = [];
            foreach (array_values(array_unique($tables)) as $table) {
                $exists = $this->tableExists($pdo, $table);
                $result[$group][] = [
                    'table' => $table,
                    'name' => $table,
                    'exists' => $exists,
                    'rows' => $exists ? $this->countRows($pdo, $table) : null,
                    'migration_hint' => $this->migrationHint($table),
                    'status' => $exists ? 'OK' : 'Ausente',
                ];
            }
        }

        return $result;
    }

    public function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function countRows(PDO $pdo, string $table): ?int
    {
        try {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $quoted);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }
    }

    private function migrationHint(string $table): string
    {
        return match ($table) {
            'mart_compradores_por_geo' => 'Version20260419000000_CompradoresPorGeo',
            'mart_vendas_por_cnae_cnpj' => 'Version20260419010000_VendasPorCnaeCnpj',
            'biomie_roles', 'biomie_permissions', 'biomie_role_permissions', 'biomie_user_roles', 'biomie_admin_logs' => 'Version20260501090000_AdminRbac',
            'biomie_clientes_geo_dim', 'biomie_geo_enrichment_queue', 'biomie_geo_sales_fact' => 'Version20260416190000_GeographicAndClientDimensions',
            default => 'Migrations do BIomie',
        };
    }
}
