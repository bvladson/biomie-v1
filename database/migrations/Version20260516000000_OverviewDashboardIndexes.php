<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260516000000';
    }

    public function getDescription(): string
    {
        return 'Overview dashboard: indices de performance para top_clients (NFs canonicas e mart_cliente_periodo) e comparativo mensal.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // top_clients fallback query: status_fiscal + data_competencia + codigo_cliente_omie
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_status_comp_cliente',
            ['status_fiscal', 'data_competencia', 'codigo_cliente_omie'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_status_comp_cliente (status_fiscal, data_competencia, codigo_cliente_omie)'
        );

        // month_comparison query: data_competencia + status_fiscal + codigo_cliente_omie
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_comp_status_cliente',
            ['data_competencia', 'status_fiscal', 'codigo_cliente_omie'],
            'ALTER TABLE biomie_notas_fiscais_canonicas'
            . ' ADD INDEX idx_nf_comp_status_cliente (data_competencia, status_fiscal, codigo_cliente_omie)'
        );

        // mart_cliente_periodo: primary lookups by competencia + codigo_cliente_omie
        $this->maybeAddIndex(
            $pdo,
            'mart_cliente_periodo',
            'idx_mart_cliente_periodo_comp',
            ['competencia', 'codigo_cliente_omie'],
            'ALTER TABLE mart_cliente_periodo'
            . ' ADD INDEX idx_mart_cliente_periodo_comp (competencia, codigo_cliente_omie)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_cliente'],
            ['biomie_notas_fiscais_canonicas', 'idx_nf_comp_status_cliente'],
            ['mart_cliente_periodo',           'idx_mart_cliente_periodo_comp'],
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
