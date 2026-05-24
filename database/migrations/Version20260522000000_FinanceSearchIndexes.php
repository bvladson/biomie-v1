<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260522000000';
    }

    public function getDescription(): string
    {
        return 'Q.5.DB: indice data_vencimento+status_titulo para finance_search (operacional) e data_emissao para data_field=emissao.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Principal: cobre finance_search com date_field=vencimento (padrão operacional).
        // Também cobre queries de vencido/aberto/a_vencer que filtram por date_vencimento + status_titulo.
        $this->maybeAddIndex(
            $pdo,
            'biomie_titulos_financeiros_canonicos',
            'idx_titulo_vencimento_status',
            ['data_vencimento', 'status_titulo'],
            'ALTER TABLE biomie_titulos_financeiros_canonicos'
            . ' ADD INDEX idx_titulo_vencimento_status (data_vencimento, status_titulo)'
        );

        // Secundário: cobre date_field=emissao (menos frequente, mas relevante para auditorias).
        $this->maybeAddIndex(
            $pdo,
            'biomie_titulos_financeiros_canonicos',
            'idx_titulo_emissao',
            ['data_emissao'],
            'ALTER TABLE biomie_titulos_financeiros_canonicos'
            . ' ADD INDEX idx_titulo_emissao (data_emissao)'
        );
    }

    public function down(\PDO $pdo): void
    {
        foreach (['idx_titulo_vencimento_status', 'idx_titulo_emissao'] as $idx) {
            if ($this->tableExists($pdo, 'biomie_titulos_financeiros_canonicos')
                && $this->indexExists($pdo, 'biomie_titulos_financeiros_canonicos', $idx)) {
                try {
                    $pdo->exec("ALTER TABLE `biomie_titulos_financeiros_canonicos` DROP INDEX `{$idx}`");
                } catch (\Throwable) {
                    // ignorar
                }
            }
        }
    }

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
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
