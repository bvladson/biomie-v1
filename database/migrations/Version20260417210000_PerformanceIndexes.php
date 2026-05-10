<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417210000';
    }

    public function getDescription(): string
    {
        return 'Fase 10: indices compostos de cobertura para padroes de consulta frequentes (performance e escalabilidade).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Cada chamada verifica: tabela existe + colunas existem + índice ausente.
        // Se qualquer pré-condição falhar, a adição é pulada silenciosamente.

        $this->maybeAddIndex(
            $pdo,
            'biomie_pedidos_canonicos',
            'idx_pedido_fat_status_vendor',
            ['data_faturamento', 'status_comercial', 'codigo_vendedor_omie'],
            "ALTER TABLE biomie_pedidos_canonicos"
            . " ADD INDEX idx_pedido_fat_status_vendor (data_faturamento, status_comercial, codigo_vendedor_omie)"
        );

        $this->maybeAddIndex(
            $pdo,
            'biomie_titulos_financeiros_canonicos',
            'idx_titulo_comp_status_vendor',
            ['data_competencia', 'status_titulo', 'codigo_vendedor_omie'],
            "ALTER TABLE biomie_titulos_financeiros_canonicos"
            . " ADD INDEX idx_titulo_comp_status_vendor (data_competencia, status_titulo, codigo_vendedor_omie)"
        );

        $this->maybeAddIndex(
            $pdo,
            'biomie_clientes_dim',
            'idx_clientes_ultima_compra',
            ['ultima_compra_em'],
            "ALTER TABLE biomie_clientes_dim ADD INDEX idx_clientes_ultima_compra (ultima_compra_em)"
        );

        $this->maybeAddIndex(
            $pdo,
            'mart_vendedor_dia',
            'idx_vendedor_dia_vendor_comp',
            ['codigo_vendedor_omie', 'competencia'],
            "ALTER TABLE mart_vendedor_dia ADD INDEX idx_vendedor_dia_vendor_comp (codigo_vendedor_omie, competencia)"
        );

        $this->maybeAddIndex(
            $pdo,
            'biomie_vendas_geo_cliente_fact',
            'idx_vendas_geo_data_uf_cidade',
            ['data_referencia', 'uf', 'cidade'],
            "ALTER TABLE biomie_vendas_geo_cliente_fact ADD INDEX idx_vendas_geo_data_uf_cidade (data_referencia, uf, cidade)"
        );

        $this->maybeAddIndex(
            $pdo,
            'mart_cliente_periodo',
            'idx_cliente_periodo_recorrencia',
            ['competencia', 'tipo_recorrencia'],
            "ALTER TABLE mart_cliente_periodo ADD INDEX idx_cliente_periodo_recorrencia (competencia, tipo_recorrencia)"
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['biomie_pedidos_canonicos',             'idx_pedido_fat_status_vendor'],
            ['biomie_titulos_financeiros_canonicos', 'idx_titulo_comp_status_vendor'],
            ['biomie_clientes_dim',                  'idx_clientes_ultima_compra'],
            ['mart_vendedor_dia',                    'idx_vendedor_dia_vendor_comp'],
            ['biomie_vendas_geo_cliente_fact',       'idx_vendas_geo_data_uf_cidade'],
            ['mart_cliente_periodo',                 'idx_cliente_periodo_recorrencia'],
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

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Adiciona um índice somente se: tabela existe, todas as colunas existem e índice ainda não existe.
     *
     * @param list<string> $requiredColumns
     */
    private function maybeAddIndex(
        \PDO $pdo,
        string $table,
        string $index,
        array $requiredColumns,
        string $ddl
    ): void {
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
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
