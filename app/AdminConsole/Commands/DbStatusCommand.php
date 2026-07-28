<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

final class DbStatusCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'db:status'; }
    public function description(): string { return 'Mostra conexao atual com o banco e tabelas presentes.'; }
    public function group(): string { return 'banco'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $lines = [];
        $metrics = [];
        $warnings = [];

        try {
            $dbName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $dbVersion = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $charset = (string) $this->pdo->query('SELECT @@character_set_database')->fetchColumn();
            $collation = (string) $this->pdo->query('SELECT @@collation_database')->fetchColumn();

            $lines[] = "Banco ativo: {$dbName}";
            $lines[] = "Versao: {$dbVersion}";
            $lines[] = "Charset: {$charset}";
            $lines[] = "Collation: {$collation}";

            $metrics['db_name'] = $dbName;
            $metrics['db_version'] = $dbVersion;
            $metrics['charset'] = $charset;
            $metrics['collation'] = $collation;

            // Total de tabelas
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\''
            );
            $stmt->execute();
            $totalTables = (int) $stmt->fetchColumn();
            $lines[] = "Total de tabelas: {$totalTables}";
            $metrics['total_tables'] = $totalTables;

            // Tabelas principais: existe/vazia/ok
            $keyTables = [
                'biomie_users',
                'biomie_schema_migrations',
                'biomie_pedidos_canonicos',
                'biomie_notas_fiscais_canonicas',
                'biomie_omie_bronze',
                'mart_comercial_pedido',
                'mart_fiscal_nfe',
            ];

            $tableRows = [];
            foreach ($keyTables as $table) {
                $existsStmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $existsStmt->execute([':t' => $table]);
                $exists = (int) $existsStmt->fetchColumn() > 0;

                if (!$exists) {
                    $tableRows[] = [$table, 'ausente', '-'];
                    continue;
                }

                $count = (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
                $tableRows[] = [$table, 'presente', (string) $count];
            }

            $tables = [[
                'title'   => 'Tabelas principais',
                'headers' => ['Tabela', 'Status', 'Registros'],
                'rows'    => $tableRows,
            ]];

            return AdminCommandResult::ok(
                'Status do banco',
                "Conexao ativa com {$dbName}. {$totalTables} tabelas encontradas.",
                outputLines: $lines,
                metrics: $metrics,
                tables: $tables,
            );
        } catch (Throwable $e) {
            return AdminCommandResult::error(
                'Status do banco',
                'Nao foi possivel consultar o banco de dados.',
                errors: ['Erro interno registrado no log.'],
            );
        }
    }
}
