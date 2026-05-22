<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260516000001';
    }

    public function getDescription(): string
    {
        return 'Product 360: indices de performance para NFs canônicas, itens, bridge e mart_produto_periodo.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Itens por produto + nota: acesso principal de todas as queries Product 360
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_itens_canonicos',
            'idx_nf_itens_produto_nota',
            ['codigo_produto', 'nota_key'],
            'ALTER TABLE biomie_notas_fiscais_itens_canonicos'
            . ' ADD INDEX idx_nf_itens_produto_nota (codigo_produto, nota_key)'
        );

        // NFs por status + competência: filtro fiscal principal
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_status_comp_nota',
            ['status_fiscal', 'data_competencia', 'nota_key'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_status_comp_nota (status_fiscal, data_competencia, nota_key)'
        );

        // NFs por vendedor + competência: aba Vendedores
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_vendor_comp_status',
            ['codigo_vendedor_omie', 'data_competencia', 'status_fiscal'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_vendor_comp_status (codigo_vendedor_omie, data_competencia, status_fiscal)'
        );

        // Bridge por nota_key: aba Pedidos vinculados
        $this->maybeAddIndex(
            $pdo,
            'mart_bridge_pedido_nfe_titulo',
            'idx_bridge_nota_key',
            ['nota_key'],
            'ALTER TABLE mart_bridge_pedido_nfe_titulo'
            . ' ADD INDEX idx_bridge_nota_key (nota_key)'
        );

        // Mart produto por competência + produto: refresh substitutivo e debug CLI
        $this->maybeAddIndex(
            $pdo,
            'mart_produto_periodo',
            'idx_mart_produto_periodo_comp_prod',
            ['competencia', 'codigo_produto'],
            'ALTER TABLE mart_produto_periodo'
            . ' ADD INDEX idx_mart_produto_periodo_comp_prod (competencia, codigo_produto)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['biomie_notas_fiscais_itens_canonicos', 'idx_nf_itens_produto_nota'],
            ['biomie_notas_fiscais_canonicas',        'idx_nf_status_comp_nota'],
            ['biomie_notas_fiscais_canonicas',        'idx_nf_vendor_comp_status'],
            ['mart_bridge_pedido_nfe_titulo',          'idx_bridge_nota_key'],
            ['mart_produto_periodo',                   'idx_mart_produto_periodo_comp_prod'],
        ];

        foreach ($drops as [$table, $index]) {
            if ($this->tableExists($pdo, $table) && $this->indexExists($pdo, $table, $index)) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                } catch (\Throwable) {
                    // Ignorar se falhar
                }
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param list<string> $requiredColumns */
    private function maybeAddIndex(\PDO $pdo, string $table, string $index, array $requiredColumns, string $ddl): void
    {
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        foreach ($requiredColumns as $col) {
            if (!$this->columnExists($pdo, $table, $col)) {
                return;
            }
        }
        if ($this->indexExists($pdo, $table, $index)) {
            return;
        }
        $pdo->exec($ddl);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
