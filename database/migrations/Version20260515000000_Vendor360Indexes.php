<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260515000000';
    }

    public function getDescription(): string
    {
        return 'Vendor 360: indices de performance para bridge, NFs canonicas e pedidos canonicos.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Índices para mart_bridge_pedido_nfe_titulo — consultas do drawer Vendor 360
        $this->maybeAddIndex(
            $pdo,
            'mart_bridge_pedido_nfe_titulo',
            'idx_bridge_vendor_nfe_date',
            ['codigo_vendedor_omie', 'data_competencia_nfe', 'bridge_status'],
            'ALTER TABLE mart_bridge_pedido_nfe_titulo'
            . ' ADD INDEX idx_bridge_vendor_nfe_date (codigo_vendedor_omie, data_competencia_nfe, bridge_status)'
        );

        $this->maybeAddIndex(
            $pdo,
            'mart_bridge_pedido_nfe_titulo',
            'idx_bridge_vendor_faturamento',
            ['codigo_vendedor_omie', 'data_faturamento', 'bridge_status'],
            'ALTER TABLE mart_bridge_pedido_nfe_titulo'
            . ' ADD INDEX idx_bridge_vendor_faturamento (codigo_vendedor_omie, data_faturamento, bridge_status)'
        );

        // Índice para NFs canonicas — filtro oficial do realizado
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_vendor_status_comp',
            ['codigo_vendedor_omie', 'status_fiscal', 'data_competencia'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_vendor_status_comp (codigo_vendedor_omie, status_fiscal, data_competencia)'
        );

        // Índice para rastrear NF a partir do pedido de origem
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_pedido_origem',
            ['codigo_pedido_origem'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_pedido_origem (codigo_pedido_origem)'
        );

        // Índice para pedidos canonicos — filtro de carteira por vendedor/status/data
        $this->maybeAddIndex(
            $pdo,
            'biomie_pedidos_canonicos',
            'idx_pedido_vendor_status_data',
            ['codigo_vendedor_omie', 'status_comercial', 'data_emissao'],
            'ALTER TABLE biomie_pedidos_canonicos'
            . ' ADD INDEX idx_pedido_vendor_status_data (codigo_vendedor_omie, status_comercial, data_emissao)'
        );

        // Índice para itens de NF — join com NFs por nota_key
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_itens_canonicos',
            'idx_nf_itens_nota_key',
            ['nota_key'],
            'ALTER TABLE biomie_notas_fiscais_itens_canonicos'
            . ' ADD INDEX idx_nf_itens_nota_key (nota_key)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['mart_bridge_pedido_nfe_titulo',           'idx_bridge_vendor_nfe_date'],
            ['mart_bridge_pedido_nfe_titulo',           'idx_bridge_vendor_faturamento'],
            ['biomie_notas_fiscais_canonicas',          'idx_nf_vendor_status_comp'],
            ['biomie_notas_fiscais_canonicas',          'idx_nf_pedido_origem'],
            ['biomie_pedidos_canonicos',                'idx_pedido_vendor_status_data'],
            ['biomie_notas_fiscais_itens_canonicos',    'idx_nf_itens_nota_key'],
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
