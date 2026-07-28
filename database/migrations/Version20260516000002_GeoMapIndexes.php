<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260516000002';
    }

    public function getDescription(): string
    {
        return 'Geo Map 360: indices de performance para mart_compradores_por_geo, biomie_clientes_geo_dim e biomie_bairros_fortaleza_dim.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Bairro + competência: acesso principal do Geo 360 e do mapa
        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'idx_geo_mart_bairro_comp',
            ['bairro_norm', 'competencia'],
            'ALTER TABLE mart_compradores_por_geo'
            . ' ADD INDEX idx_geo_mart_bairro_comp (bairro_norm, competencia)'
        );

        // CNAE + competência + bairro: aba CNAEs do Geo 360
        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'idx_geo_mart_cnae_comp',
            ['cnae_codigo', 'competencia'],
            'ALTER TABLE mart_compradores_por_geo'
            . ' ADD INDEX idx_geo_mart_cnae_comp (cnae_codigo, competencia, bairro_norm)'
        );

        // Cliente + competência: lookup de clientes por bairro
        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'idx_geo_mart_cliente_comp',
            ['codigo_cliente_omie', 'competencia'],
            'ALTER TABLE mart_compradores_por_geo'
            . ' ADD INDEX idx_geo_mart_cliente_comp (codigo_cliente_omie, competencia)'
        );

        // Bairro normalizado na dim de geo do cliente: join NF → bairro
        $this->maybeAddIndex(
            $pdo,
            'biomie_clientes_geo_dim',
            'idx_clientes_geo_bairro_norm',
            ['bairro_norm'],
            'ALTER TABLE biomie_clientes_geo_dim'
            . ' ADD INDEX idx_clientes_geo_bairro_norm (bairro_norm)'
        );

        // Código do cliente na dim geo: join principal com NFs e mart
        $this->maybeAddIndex(
            $pdo,
            'biomie_clientes_geo_dim',
            'idx_clientes_geo_codigo',
            ['codigo_cliente_omie'],
            'ALTER TABLE biomie_clientes_geo_dim'
            . ' ADD INDEX idx_clientes_geo_codigo (codigo_cliente_omie)'
        );

        // Bairro normalizado na dim oficial: lookup de nome oficial
        $this->maybeAddIndex(
            $pdo,
            'biomie_bairros_fortaleza_dim',
            'idx_bairros_dim_nome_norm',
            ['bairro_nome_normalizado', 'ativo'],
            'ALTER TABLE biomie_bairros_fortaleza_dim'
            . ' ADD INDEX idx_bairros_dim_nome_norm (bairro_nome_normalizado, ativo)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['mart_compradores_por_geo',   'idx_geo_mart_bairro_comp'],
            ['mart_compradores_por_geo',   'idx_geo_mart_cnae_comp'],
            ['mart_compradores_por_geo',   'idx_geo_mart_cliente_comp'],
            ['biomie_clientes_geo_dim',    'idx_clientes_geo_bairro_norm'],
            ['biomie_clientes_geo_dim',    'idx_clientes_geo_codigo'],
            ['biomie_bairros_fortaleza_dim', 'idx_bairros_dim_nome_norm'],
        ];

        foreach ($drops as [$table, $index]) {
            if ($this->tableExists($pdo, $table) && $this->indexExists($pdo, $table, $index)) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                } catch (\Throwable) {}
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<string> $requiredColumns */
    private function maybeAddIndex(\PDO $pdo, string $table, string $index, array $requiredColumns, string $ddl): void
    {
        if (!$this->tableExists($pdo, $table)) return;
        foreach ($requiredColumns as $col) {
            if (!$this->columnExists($pdo, $table, $col)) return;
        }
        if ($this->indexExists($pdo, $table, $index)) return;
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
