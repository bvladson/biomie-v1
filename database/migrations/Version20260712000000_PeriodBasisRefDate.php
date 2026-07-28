<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Coluna gerada data_ref (period basis) nas 3 tabelas canônicas + índice
 * composto (company_id, data_ref).
 *
 * Motivação: filtros de período usavam COALESCE(...) direto no WHERE, o que
 * impede uso de índice (feedback registrado em 2026-06). A coluna STORED
 * materializa a mesma expressão e o índice cobre o padrão dominante de
 * consulta "empresa + intervalo de datas".
 *
 * Expressões (todas as colunas-fonte são DATE — equivalência exata):
 *   notas    → COALESCE(data_competencia, data_emissao)
 *   pedidos  → COALESCE(data_faturamento, data_emissao)
 *   títulos  → COALESCE(data_competencia, data_vencimento)
 */
return new class extends AbstractMigration {

    private const TABLES = [
        'biomie_notas_fiscais_canonicas' => [
            'expr'  => 'COALESCE(data_competencia, data_emissao)',
            'index' => 'idx_nf_company_dataref',
        ],
        'biomie_pedidos_canonicos' => [
            'expr'  => 'COALESCE(data_faturamento, data_emissao)',
            'index' => 'idx_ped_company_dataref',
        ],
        'biomie_titulos_financeiros_canonicos' => [
            'expr'  => 'COALESCE(data_competencia, data_vencimento)',
            'index' => 'idx_tit_company_dataref',
        ],
    ];

    public function getVersion(): string
    {
        return '20260712000000';
    }

    public function getDescription(): string
    {
        return 'Generated column data_ref (period basis) + índice (company_id, data_ref) nas tabelas canônicas de NF, pedidos e títulos.';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::TABLES as $table => $def) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }

            if (!$this->columnExists($pdo, $table, 'data_ref')) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s ADD COLUMN data_ref DATE GENERATED ALWAYS AS (%s) STORED',
                    $table,
                    $def['expr']
                ));
            }

            if (!$this->indexExists($pdo, $table, $def['index'])) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s ADD INDEX %s (company_id, data_ref)',
                    $table,
                    $def['index']
                ));
            }
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (self::TABLES as $table => $def) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }

            if ($this->indexExists($pdo, $table, $def['index'])) {
                $pdo->exec(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $def['index']));
            }

            if ($this->columnExists($pdo, $table, 'data_ref')) {
                $pdo->exec(sprintf('ALTER TABLE %s DROP COLUMN data_ref', $table));
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND COLUMN_NAME  = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND INDEX_NAME   = :i'
        );
        $stmt->execute(['t' => $table, 'i' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
