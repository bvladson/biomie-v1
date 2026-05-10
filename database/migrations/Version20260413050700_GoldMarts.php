<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050700';
    }

    public function getDescription(): string
    {
        return 'Camada gold: marts consolidados (bridge, vendedor_dia, cliente_periodo, produto_periodo).';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050700_GoldMarts.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050700_GoldMarts.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'mart_produto_periodo',
            'mart_cliente_periodo',
            'mart_vendedor_dia',
            'mart_bridge_pedido_nfe_titulo',
            'mart_financeiro_titulo',
            'mart_fiscal_nfe',
            'mart_comercial_pedido',
        ]);
    }
};
