<?php

declare(strict_types=1);

namespace BIomie\Migrations;

use PDO;
use RuntimeException;

abstract class AbstractMigration implements MigrationInterface
{
    public function getConnectionName(): string
    {
        return 'canonical';
    }

    public function getType(): string
    {
        return 'expansive';
    }

    public function getChecksumSources(): array
    {
        return [];
    }

    protected function executeSqlFile(PDO $pdo, string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo SQL de migration nao encontrado: ' . $path);
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Falha ao ler arquivo SQL de migration: ' . $path);
        }

        $this->executeSql($pdo, $sql);
    }

    /**
     * @param list<string> $statements
     */
    protected function executeStatements(PDO $pdo, array $statements): void
    {
        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }

            $pdo->exec($trimmed);
        }
    }

    /**
     * @param list<string> $tables
     */
    protected function dropTables(PDO $pdo, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $quoted = array_map(
            static fn (string $table): string => '`' . str_replace('`', '``', $table) . '`',
            $tables
        );

        $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', $quoted));
    }

    protected function executeSql(PDO $pdo, string $sql): void
    {
        $normalized = str_replace("\r\n", "\n", $sql);
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;

        $parts = preg_split('/;\s*(?:\n|$)/', $normalized);
        if ($parts === false) {
            throw new RuntimeException('Falha ao quebrar statements SQL da migration.');
        }

        $statements = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            $statements[] = $trimmed;
        }

        $this->executeStatements($pdo, $statements);
    }
}
