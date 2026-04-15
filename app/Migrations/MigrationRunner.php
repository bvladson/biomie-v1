<?php

declare(strict_types=1);

namespace BIomie\Migrations;

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    /** @var array<string, PDO> */
    private array $connections = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $discoveredMigrations = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $migrationsPath = '',
        private readonly string $tableName = 'biomie_schema_migrations',
        private readonly string $appVersion = 'dev',
    ) {
    }

    /**
     * @return array{summary:array<string,int>,migrations:list<array<string,mixed>>}
     */
    public function status(): array
    {
        $rows = [];
        $summary = [
            'discovered' => 0,
            'applied' => 0,
            'pending' => 0,
            'rolled_back' => 0,
            'missing_connection' => 0,
            'connection_error' => 0,
            'checksum_mismatch' => 0,
        ];

        foreach ($this->discoverMigrations() as $migration) {
            $summary['discovered']++;

            $logicalConnection = (string) $migration['logical_connection'];
            $actualConnection = $this->resolveActualConnection($logicalConnection);
            $row = [
                'version' => $migration['version'],
                'description' => $migration['description'],
                'type' => $migration['type'],
                'logical_connection' => $logicalConnection,
                'actual_connection' => $actualConnection,
                'file' => $migration['path'],
                'checksum' => $migration['checksum'],
                'status' => 'pending',
                'checksum_match' => true,
                'executed_at' => null,
                'rolled_back_at' => null,
                'execution_ms' => null,
                'app_version' => null,
            ];

            if (!$this->isActualConnectionConfigured($actualConnection)) {
                $row['status'] = 'missing_connection';
                $summary['missing_connection']++;
                $rows[] = $row;
                continue;
            }

            try {
                $applied = $this->fetchAppliedRecord($actualConnection, (string) $migration['version']);
            } catch (Throwable $exception) {
                $row['status'] = 'connection_error';
                $row['error'] = $exception->getMessage();
                $summary['connection_error']++;
                $rows[] = $row;
                continue;
            }

            if ($applied === null) {
                $summary['pending']++;
                $rows[] = $row;
                continue;
            }

            $row['executed_at'] = $applied['executed_at'] ?? null;
            $row['rolled_back_at'] = $applied['rolled_back_at'] ?? null;
            $row['execution_ms'] = isset($applied['execution_ms']) ? (int) $applied['execution_ms'] : null;
            $row['app_version'] = $applied['app_version'] ?? null;
            $row['status'] = (string) ($applied['status'] ?? 'applied');
            $row['checksum_match'] = hash_equals(
                (string) ($applied['checksum'] ?? ''),
                (string) $migration['checksum']
            );

            if (!$row['checksum_match']) {
                $summary['checksum_mismatch']++;
            }

            if ($row['status'] === 'rolled_back') {
                $summary['rolled_back']++;
            } else {
                $summary['applied']++;
            }

            $rows[] = $row;
        }

        return [
            'summary' => $summary,
            'migrations' => $rows,
        ];
    }

    /**
     * @return array{applied:list<array<string,mixed>>,skipped:list<array<string,mixed>>}
     */
    public function migrate(?string $targetVersion = null): array
    {
        $applied = [];
        $skipped = [];

        foreach ($this->discoverMigrations() as $migration) {
            $version = (string) $migration['version'];
            if ($targetVersion !== null && strcmp($version, $targetVersion) > 0) {
                break;
            }

            $actualConnection = $this->resolveActualConnection((string) $migration['logical_connection']);
            if (!$this->isActualConnectionConfigured($actualConnection)) {
                throw new RuntimeException(sprintf(
                    'Conexao "%s" nao configurada para migration %s.',
                    $actualConnection,
                    $version
                ));
            }

            $record = $this->fetchAppliedRecord($actualConnection, $version);
            if ($record !== null
                && (string) ($record['status'] ?? 'applied') === 'applied'
            ) {
                if (!hash_equals((string) ($record['checksum'] ?? ''), (string) $migration['checksum'])) {
                    throw new RuntimeException(sprintf(
                        'Checksum divergente na migration %s. Rode verify antes de seguir.',
                        $version
                    ));
                }

                $skipped[] = [
                    'version' => $version,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'reason' => 'already_applied',
                ];
                continue;
            }

            $pdo = $this->connection($actualConnection);
            $startedAt = microtime(true);

            try {
                /** @var MigrationInterface $instance */
                $instance = $migration['instance'];
                $instance->up($pdo);
                $executionMs = (int) round((microtime(true) - $startedAt) * 1000);

                $this->storeAppliedRecord($actualConnection, $migration, $executionMs);
                $this->logger->info('Migration aplicada com sucesso.', [
                    'version' => $version,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'type' => $migration['type'],
                    'execution_ms' => $executionMs,
                ]);

                $applied[] = [
                    'version' => $version,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'type' => $migration['type'],
                    'execution_ms' => $executionMs,
                ];
            } catch (Throwable $exception) {
                $this->logger->error('Falha ao aplicar migration.', [
                    'version' => $version,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'message' => $exception->getMessage(),
                ]);

                throw new RuntimeException(sprintf(
                    'Falha ao aplicar migration %s: %s',
                    $version,
                    $exception->getMessage()
                ), 0, $exception);
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rollback(?string $version = null): array
    {
        $migration = $version !== null
            ? $this->findMigrationByVersion($version)
            : $this->latestAppliedMigration();

        if ($migration === null) {
            throw new RuntimeException('Nenhuma migration aplicada encontrada para rollback.');
        }

        $actualConnection = $this->resolveActualConnection((string) $migration['logical_connection']);
        if (!$this->isActualConnectionConfigured($actualConnection)) {
            throw new RuntimeException(sprintf(
                'Conexao "%s" nao configurada para rollback da migration %s.',
                $actualConnection,
                (string) $migration['version']
            ));
        }

        $record = $this->fetchAppliedRecord($actualConnection, (string) $migration['version']);
        if ($record === null || (string) ($record['status'] ?? '') !== 'applied') {
            throw new RuntimeException(sprintf(
                'Migration %s nao esta aplicada no banco atual.',
                (string) $migration['version']
            ));
        }

        $pdo = $this->connection($actualConnection);

        try {
            /** @var MigrationInterface $instance */
            $instance = $migration['instance'];
            $instance->down($pdo);
            $this->markRolledBack($actualConnection, (string) $migration['version']);

            $this->logger->warning('Rollback de migration executado.', [
                'version' => $migration['version'],
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'type' => $migration['type'],
            ]);

            return [
                'version' => $migration['version'],
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'type' => $migration['type'],
                'status' => 'rolled_back',
            ];
        } catch (Throwable $exception) {
            $this->logger->error('Falha ao executar rollback de migration.', [
                'version' => $migration['version'],
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(sprintf(
                'Falha no rollback da migration %s: %s',
                (string) $migration['version'],
                $exception->getMessage()
            ), 0, $exception);
        }
    }

    /**
     * @return array{verified:list<array<string,mixed>>,mismatches:list<array<string,mixed>>}
     */
    public function verifyChecksums(): array
    {
        $verified = [];
        $mismatches = [];

        foreach ($this->discoverMigrations() as $migration) {
            $actualConnection = $this->resolveActualConnection((string) $migration['logical_connection']);
            if (!$this->isActualConnectionConfigured($actualConnection)) {
                continue;
            }

            $record = $this->fetchAppliedRecord($actualConnection, (string) $migration['version']);
            if ($record === null || (string) ($record['status'] ?? '') !== 'applied') {
                continue;
            }

            $matches = hash_equals((string) ($record['checksum'] ?? ''), (string) $migration['checksum']);
            $row = [
                'version' => $migration['version'],
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'stored_checksum' => $record['checksum'] ?? null,
                'current_checksum' => $migration['checksum'],
                'matches' => $matches,
            ];

            if ($matches) {
                $verified[] = $row;
            } else {
                $mismatches[] = $row;
            }
        }

        return [
            'verified' => $verified,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function discoverMigrations(): array
    {
        if ($this->discoveredMigrations !== null) {
            return $this->discoveredMigrations;
        }

        $path = $this->migrationsPath !== '' ? $this->migrationsPath : Bootstrap::rootPath(
            (string) $this->config->get('migrations.path', 'database/migrations')
        );

        $files = glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Version*.php');
        if ($files === false) {
            throw new RuntimeException('Falha ao listar arquivos de migration em ' . $path);
        }

        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $instance = require $file;
            if (!$instance instanceof MigrationInterface) {
                throw new RuntimeException('Arquivo de migration invalido: ' . $file);
            }

            $version = $instance->getVersion();
            if (isset($migrations[$version])) {
                throw new RuntimeException('Versao de migration duplicada: ' . $version);
            }

            $migrations[$version] = [
                'version' => $version,
                'description' => $instance->getDescription(),
                'logical_connection' => $instance->getConnectionName(),
                'type' => $instance->getType(),
                'path' => $file,
                'checksum' => $this->computeChecksum($file, $instance->getChecksumSources()),
                'instance' => $instance,
            ];
        }

        $this->discoveredMigrations = array_values($migrations);
        usort(
            $this->discoveredMigrations,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['version'],
                (string) $right['version']
            )
        );

        return $this->discoveredMigrations;
    }

    /**
     * @param list<string> $sources
     */
    private function computeChecksum(string $migrationFile, array $sources): string
    {
        $buffer = $migrationFile . "\n";
        $content = file_get_contents($migrationFile);
        if ($content === false) {
            throw new RuntimeException('Falha ao ler migration para checksum: ' . $migrationFile);
        }

        $buffer .= $content;
        foreach (array_values(array_unique($sources)) as $source) {
            if (!is_file($source)) {
                throw new RuntimeException('Arquivo extra de checksum nao encontrado: ' . $source);
            }

            $sourceContent = file_get_contents($source);
            if ($sourceContent === false) {
                throw new RuntimeException('Falha ao ler checksum source: ' . $source);
            }

            $buffer .= "\n" . $source . "\n" . $sourceContent;
        }

        return hash('sha256', $buffer);
    }

    private function resolveActualConnection(string $logicalConnection): string
    {
        return match ($logicalConnection) {
            'canonical' => Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db',
            'primary' => 'db',
            default => $logicalConnection,
        };
    }

    private function isActualConnectionConfigured(string $connection): bool
    {
        return Database::isConfigured($this->config, $connection);
    }

    private function connection(string $actualConnection): PDO
    {
        if (isset($this->connections[$actualConnection])) {
            return $this->connections[$actualConnection];
        }

        $pdo = Database::connect($this->config, $actualConnection);
        $this->ensureVersionTable($pdo);
        $this->connections[$actualConnection] = $pdo;

        return $pdo;
    }

    private function ensureVersionTable(PDO $pdo): void
    {
        $pdo->exec(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    schema_version VARCHAR(32) NOT NULL,
                    description VARCHAR(255) NOT NULL,
                    logical_connection VARCHAR(64) NOT NULL,
                    actual_connection VARCHAR(64) NOT NULL,
                    migration_type VARCHAR(32) NOT NULL DEFAULT 'expansive',
                    checksum CHAR(64) NOT NULL,
                    app_version VARCHAR(64) NOT NULL DEFAULT 'dev',
                    status ENUM('applied','rolled_back') NOT NULL DEFAULT 'applied',
                    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    execution_ms INT UNSIGNED NULL,
                    rolled_back_at DATETIME NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_schema_version (schema_version),
                    KEY idx_schema_status (status, executed_at),
                    KEY idx_schema_connection (actual_connection, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            $this->migrationTable()
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchAppliedRecord(string $actualConnection, string $version): ?array
    {
        $pdo = $this->connection($actualConnection);

        $stmt = $pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE schema_version = :version LIMIT 1',
            $this->migrationTable()
        ));
        $stmt->execute(['version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string,mixed> $migration
     */
    private function storeAppliedRecord(string $actualConnection, array $migration, int $executionMs): void
    {
        $pdo = $this->connection($actualConnection);

        $stmt = $pdo->prepare(sprintf(
            <<<'SQL'
                INSERT INTO %s (
                    schema_version, description, logical_connection, actual_connection,
                    migration_type, checksum, app_version, status, executed_at, execution_ms, rolled_back_at
                ) VALUES (
                    :schema_version, :description, :logical_connection, :actual_connection,
                    :migration_type, :checksum, :app_version, 'applied', NOW(), :execution_ms, NULL
                )
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    logical_connection = VALUES(logical_connection),
                    actual_connection = VALUES(actual_connection),
                    migration_type = VALUES(migration_type),
                    checksum = VALUES(checksum),
                    app_version = VALUES(app_version),
                    status = 'applied',
                    executed_at = NOW(),
                    execution_ms = VALUES(execution_ms),
                    rolled_back_at = NULL
            SQL,
            $this->migrationTable()
        ));

        $stmt->execute([
            'schema_version' => $migration['version'],
            'description' => $migration['description'],
            'logical_connection' => $migration['logical_connection'],
            'actual_connection' => $actualConnection,
            'migration_type' => $migration['type'],
            'checksum' => $migration['checksum'],
            'app_version' => $this->appVersion,
            'execution_ms' => $executionMs,
        ]);
    }

    private function markRolledBack(string $actualConnection, string $version): void
    {
        $pdo = $this->connection($actualConnection);
        $stmt = $pdo->prepare(sprintf(
            'UPDATE %s SET status = :status, rolled_back_at = NOW() WHERE schema_version = :version',
            $this->migrationTable()
        ));
        $stmt->execute([
            'status' => 'rolled_back',
            'version' => $version,
        ]);
    }

    private function migrationTable(): string
    {
        return '`' . str_replace('`', '``', $this->tableName) . '`';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findMigrationByVersion(string $version): ?array
    {
        foreach ($this->discoverMigrations() as $migration) {
            if ((string) $migration['version'] === $version) {
                return $migration;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestAppliedMigration(): ?array
    {
        $latest = null;

        foreach ($this->status()['migrations'] as $row) {
            if (($row['status'] ?? '') !== 'applied') {
                continue;
            }

            if ($latest === null) {
                $latest = $row;
                continue;
            }

            if (strcmp((string) ($row['executed_at'] ?? ''), (string) ($latest['executed_at'] ?? '')) > 0) {
                $latest = $row;
            }
        }

        if ($latest === null) {
            return null;
        }

        return $this->findMigrationByVersion((string) $latest['version']);
    }
}
