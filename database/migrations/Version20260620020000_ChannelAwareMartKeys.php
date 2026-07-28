<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260620020000';
    }

    public function getDescription(): string
    {
        return 'Phase 2D — inclui business_channel nas chaves unicas dos marts comerciais.';
    }

    public function up(PDO $pdo): void
    {
        $definitions = [
            ['mart_comercial_pedido', 'uq_company_mart_pedido', 'company_id, business_channel, pedido_id_omie'],
            ['mart_fiscal_nfe', 'uq_company_mart_nfe', 'company_id, business_channel, nota_key'],
            ['mart_financeiro_titulo', 'uq_company_mart_titulo', 'company_id, business_channel, titulo_key'],
            ['mart_bridge_pedido_nfe_titulo', 'uq_company_bridge_pedido', 'company_id, business_channel, pedido_id_omie'],
            ['mart_cliente_periodo', 'uq_company_cliente_periodo', 'company_id, business_channel, competencia, codigo_cliente_omie'],
            ['mart_produto_periodo', 'uq_company_produto_periodo', 'company_id, business_channel, competencia, codigo_produto'],
            ['mart_vendedor_dia', 'uq_company_vendedor_dia', 'company_id, business_channel, competencia, dia, codigo_vendedor_omie'],
            ['mart_compradores_por_geo', 'uq_company_compradores_geo', 'company_id, business_channel, competencia, codigo_vendedor_omie, codigo_cliente_omie, uf, cidade_norm, bairro_norm'],
            ['mart_vendas_por_cnae_cnpj', 'uq_company_cnae_cliente', 'company_id, business_channel, competencia, codigo_cliente_omie, documento_normalizado'],
            ['mart_fiscal_items', 'uk_company_nota_item', 'company_id, business_channel, nota_key, sequencia_item'],
        ];

        foreach ($definitions as [$table, $index, $columns]) {
            if ($this->indexExists($pdo, $table, $index)) {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
            $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$columns})");
        }
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function down(PDO $pdo): void
    {
        // Rollback deliberadamente manual: remover canal pode colapsar linhas distintas.
    }
};
