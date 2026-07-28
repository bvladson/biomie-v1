<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    /** @var array<string,string> */
    private const TABLES = [
        'biomie_competencia_snapshots' => 'uq_company_scope_competencia_snap',
        'biomie_competencia_sync_catalog' => 'uq_company_scope_competencia_cat',
    ];

    public function getVersion(): string
    {
        return '20260718030000';
    }

    public function getDescription(): string
    {
        return 'Isola snapshots comerciais por empresa, canal e escopo de visibilidade.';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::TABLES as $table => $targetIndex) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }

            if (!$this->columnExists($pdo, $table, 'business_channel')) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN business_channel VARCHAR(16) NOT NULL DEFAULT 'varejo' AFTER company_id",
                    $table,
                ));
            }
            if (!$this->columnExists($pdo, $table, 'visibility_scope')) {
                // A legacy snapshot cannot prove which visibility context built
                // it. Keep it available for audit, but unreachable by scoped reads.
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN visibility_scope VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER business_channel",
                    $table,
                ));
            }

            $indexes = $this->uniqueIndexColumns($pdo, $table);
            $clauses = [];
            $hasTarget = false;
            foreach ($indexes as $indexName => $columns) {
                if ($columns === ['company_id', 'business_channel', 'visibility_scope', 'competencia']) {
                    $hasTarget = true;
                    continue;
                }
                if ($columns === ['competencia'] || $columns === ['company_id', 'competencia']) {
                    $clauses[] = sprintf('DROP INDEX `%s`', $indexName);
                }
            }
            if (!$hasTarget) {
                $clauses[] = sprintf(
                    'ADD UNIQUE KEY `%s` (`company_id`, `business_channel`, `visibility_scope`, `competencia`)',
                    $targetIndex,
                );
            }
            if ($clauses !== []) {
                $pdo->exec(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
            }
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (self::TABLES as $table => $targetIndex) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }

            $duplicates = (int) $pdo->query(sprintf(
                'SELECT COUNT(*) FROM (
                    SELECT company_id, competencia
                      FROM `%s`
                     GROUP BY company_id, competencia
                    HAVING COUNT(*) > 1
                ) scoped_snapshots',
                $table,
            ))->fetchColumn();
            if ($duplicates > 0) {
                throw new RuntimeException(
                    'Rollback recusado: existem snapshots de multiplos escopos para a mesma empresa/competencia.'
                );
            }

            $clauses = [];
            if ($this->indexExists($pdo, $table, $targetIndex)) {
                $clauses[] = sprintf('DROP INDEX `%s`', $targetIndex);
            }
            if (!$this->hasUniqueColumns($pdo, $table, ['company_id', 'competencia'])) {
                $legacyName = $table === 'biomie_competencia_snapshots'
                    ? 'uq_company_competencia_snap'
                    : 'uq_company_competencia_cat';
                $clauses[] = sprintf(
                    'ADD UNIQUE KEY `%s` (`company_id`, `competencia`)',
                    $legacyName,
                );
            }
            if ($this->columnExists($pdo, $table, 'visibility_scope')) {
                $clauses[] = 'DROP COLUMN visibility_scope';
            }
            if ($this->columnExists($pdo, $table, 'business_channel')) {
                $clauses[] = 'DROP COLUMN business_channel';
            }
            if ($clauses !== []) {
                $pdo->exec(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table_name' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string,list<string>> */
    private function uniqueIndexColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND NON_UNIQUE = 0
                AND INDEX_NAME <> \'PRIMARY\'
              ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute(['table_name' => $table]);

        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            if ($name !== '' && $column !== '') {
                $indexes[$name] ??= [];
                $indexes[$name][] = $column;
            }
        }
        return $indexes;
    }

    /** @param list<string> $columns */
    private function hasUniqueColumns(PDO $pdo, string $table, array $columns): bool
    {
        foreach ($this->uniqueIndexColumns($pdo, $table) as $indexColumns) {
            if ($indexColumns === $columns) {
                return true;
            }
        }
        return false;
    }
};
