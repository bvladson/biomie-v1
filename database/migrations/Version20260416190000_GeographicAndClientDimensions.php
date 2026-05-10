<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Camada Geografia Analitica e Clientes (Phase 2)
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260416190000';
    }

    public function getDescription(): string
    {
        return 'Camada Mestra de Analytics Geografico: Clientes Dimensao, Geografia Dimensao, e Fact de Vendas + Fila de Enriquecimento.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260416190000_GeographicAndClientDimensions.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260416190000_GeographicAndClientDimensions.sql');
        $this->addIndexIfMissing(
            $pdo,
            'biomie_geo_vendas_diaria',
            'idx_geo_diaria_data_escopo',
            '(`data_referencia`, `escopo_geo`)'
        );
        $this->addIndexIfMissing(
            $pdo,
            'biomie_geo_vendas_diaria',
            'idx_geo_diaria_scope_location',
            '(`escopo_geo`, `uf`, `cidade_norm`, `bairro_norm`)'
        );
        $this->addIndexIfMissing(
            $pdo,
            'biomie_vendas_geo_cliente_fact',
            'idx_vendas_geo_data_uf_cidade_bairro',
            '(`data_referencia`, `uf`, `cidade`, `bairro`)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_geo_vendas_diaria',
            'biomie_vendas_geo_cliente_fact',
            'biomie_clientes_geo_dim',
            'biomie_clientes_dim',
            'biomie_clientes_para_enriquecer'
        ]);
    }

    private function addIndexIfMissing(\PDO $pdo, string $table, string $index, string $definition): void
    {
        if (!$this->tableExists($pdo, $table) || $this->indexExists($pdo, $table, $index)) {
            return;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD INDEX %s %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($index),
            $definition
        ));
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
            LIMIT 1
        SQL);
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index_name
            LIMIT 1
        SQL);
        $stmt->execute([
            'table' => $table,
            'index_name' => $index,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
