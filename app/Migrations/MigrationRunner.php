<?php

declare(strict_types=1);

namespace BIomie\Migrations;

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Support\ProductionDatabaseGuard;
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
                'file_basename' => basename((string) $migration['path']),
                'checksum' => $migration['checksum'],
                'status' => 'pending',
                'checksum_match' => true,
                'executed_at' => null,
                'rolled_back_at' => null,
                'execution_ms' => null,
                'app_version' => null,
                'domain_group' => self::classifyDomainGroup((string) $migration['version'], (string) $migration['description'], basename((string) $migration['path'])),
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
     * Status enriquecido com diagnósticos de conexão, ambiente e arquivos soltos.
     * Usado tanto pelo CLI quanto pelo admin para exibição completa.
     *
     * @return array{summary:array<string,int>,migrations:list<array<string,mixed>>,diagnostics:array<string,mixed>}
     */
    public function statusWithDiagnostics(): array
    {
        $status = $this->status();

        $path = $this->migrationsPath !== '' ? $this->migrationsPath : Bootstrap::rootPath(
            (string) $this->config->get('migrations.path', 'database/migrations')
        );

        // Info de conexão (sem senha)
        $connectionInfo = [];
        foreach (['db', 'flight_plan_db'] as $connName) {
            if (Database::isConfigured($this->config, $connName)) {
                $connectionInfo[$connName] = [
                    'configured' => true,
                    'host' => (string) $this->config->get($connName . '.host', ''),
                    'port' => (int) $this->config->get($connName . '.port', 3306),
                    'database' => (string) $this->config->get($connName . '.name', ''),
                    'user' => (string) $this->config->get($connName . '.user', ''),
                    'charset' => (string) $this->config->get($connName . '.charset', 'utf8mb4'),
                    'driver' => (string) $this->config->get($connName . '.driver', 'mysql'),
                ];
            } else {
                $connectionInfo[$connName] = ['configured' => false];
            }
        }

        // Detecção de arquivos SQL soltos
        $looseSqlFiles = [];
        $sqlFiles = glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        foreach (($sqlFiles ?: []) as $sqlFile) {
            $looseSqlFiles[] = basename($sqlFile);
        }

        // Versão do servidor MySQL/MariaDB
        $dbVersion = null;
        $dbName = null;
        try {
            $actualConn = $this->resolveActualConnection('canonical');
            if ($this->isActualConnectionConfigured($actualConn)) {
                $pdo = $this->connection($actualConn);
                $dbVersion = (string) ($pdo->query('SELECT VERSION()')->fetchColumn() ?: '');
                $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            }
        } catch (Throwable) {}

        $status['diagnostics'] = [
            'migrations_path' => $path,
            'migrations_table' => $this->tableName,
            'checksum_algorithm' => 'v2:relative-path-plus-normalized-content',
            'environment' => (string) $this->config->get('app.env', getenv('APP_ENV') ?: 'production'),
            'app_version' => $this->appVersion,
            'php_version' => PHP_VERSION,
            'db_version' => $dbVersion,
            'db_name' => $dbName,
            'connections' => $connectionInfo,
            'loose_sql_files' => $looseSqlFiles,
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        return $status;
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
            // Portão de produção ANTES do DDL: no MariaDB, up() faz commit
            // implícito e não há rollback. Produção só com intenção declarada.
            ProductionDatabaseGuard::assertWriteAllowed($pdo, 'migrate ' . $version);
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
                $executionMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->logger->error('Falha ao aplicar migration.', [
                    'version' => $version,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'message' => $exception->getMessage(),
                ]);
                $this->logMigrationFailure($migration, $exception, $executionMs);

                throw new MigrationFailedException(
                    sprintf('Falha ao aplicar migration %s: %s', $version, $exception->getMessage()),
                    $version,
                    (string) $migration['description'],
                    basename((string) $migration['path']),
                    $actualConnection,
                    $executionMs,
                    $exception
                );
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
        // down() costuma ser DROP TABLE — o DDL mais destrutivo, e com commit
        // implícito no MariaDB. Produção só com intenção declarada.
        ProductionDatabaseGuard::assertWriteAllowed($pdo, 'rollback ' . (string) $migration['version']);

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
     * Diagnostica checksums sem alterar metadados no banco.
     *
     * @return array{summary:array<string,int>,legacy_roots:list<string>,migrations:list<array<string,mixed>>}
     */
    public function checksumDiagnose(): array
    {
        $summary = [
            'total' => 0,
            'applied' => 0,
            'pending' => 0,
            'path_only_mismatch' => 0,
            'content_mismatch' => 0,
            'missing_file' => 0,
            'unknown' => 0,
            'already_v2_match' => 0,
            'new_matches' => 0,
            'legacy_matches' => 0,
        ];
        $rows = [];
        $legacyRoots = $this->legacyChecksumRootCandidates();

        foreach ($this->discoverMigrations() as $migration) {
            $summary['total']++;

            $file = (string) $migration['path'];
            $sources = $this->checksumSources($migration);
            $fileExists = is_file($file);
            $newChecksum = $fileExists ? (string) $migration['checksum'] : null;
            $legacyChecksum = $fileExists ? $this->computeLegacyChecksum($file, $sources) : null;
            $legacyMatches = [];
            foreach ($legacyRoots as $root) {
                if (!$fileExists) {
                    continue;
                }
                try {
                    $candidate = $this->computeLegacyChecksum($file, $sources, $root);
                } catch (Throwable) {
                    continue;
                }
                $legacyMatches[$root] = $candidate;
            }

            $actualConnection = $this->resolveActualConnection((string) $migration['logical_connection']);
            $record = null;
            if ($this->isActualConnectionConfigured($actualConnection)) {
                try {
                    $record = $this->fetchAppliedRecord($actualConnection, (string) $migration['version']);
                } catch (Throwable) {
                    $record = null;
                }
            }

            $stored = is_array($record) ? (string) ($record['checksum'] ?? '') : '';
            $status = is_array($record) ? (string) ($record['status'] ?? 'applied') : 'pending';
            $matchNew = $stored !== '' && $newChecksum !== null && hash_equals($stored, $newChecksum);
            $matchLegacyCurrent = $stored !== '' && $legacyChecksum !== null && hash_equals($stored, $legacyChecksum);
            $matchedLegacyRoots = [];
            foreach ($legacyMatches as $root => $candidate) {
                if ($stored !== '' && hash_equals($stored, $candidate)) {
                    $matchedLegacyRoots[] = $root;
                }
            }
            $matchLegacy = $matchLegacyCurrent || $matchedLegacyRoots !== [];

            if (!$fileExists) {
                $classification = 'missing_file';
            } elseif ($record === null) {
                $classification = 'pending';
            } elseif ($matchNew) {
                $classification = 'already_v2_match';
            } elseif ($matchLegacy) {
                $classification = 'path_only_mismatch';
            } elseif ($stored !== '') {
                $classification = 'content_mismatch';
            } else {
                $classification = 'unknown';
            }

            if (isset($summary[$classification])) {
                $summary[$classification]++;
            }
            if ($record !== null && $status === 'applied') {
                $summary['applied']++;
            }
            if ($matchNew) {
                $summary['new_matches']++;
            }
            if ($matchLegacy) {
                $summary['legacy_matches']++;
            }

            $rows[] = [
                'migration' => $migration['version'],
                'description' => $migration['description'],
                'status' => $status,
                'connection' => $actualConnection,
                'stored_checksum' => $stored !== '' ? $stored : null,
                'legacy_checksum' => $legacyChecksum,
                'new_checksum' => $newChecksum,
                'match_legacy' => $matchLegacy,
                'match_legacy_current_path' => $matchLegacyCurrent,
                'match_legacy_roots' => $matchedLegacyRoots,
                'match_new' => $matchNew,
                'classification' => $classification,
                'file_exists' => $fileExists,
                'file' => $file,
                'file_basename' => basename($file),
            ];
        }

        return [
            'summary' => $summary,
            'legacy_roots' => $legacyRoots,
            'migrations' => $rows,
        ];
    }

    /**
     * Gera um plano auditavel de repair de checksums e, apenas com --apply, executa o UPDATE controlado.
     *
     * @return array<string,mixed>
     */
    public function repairChecksumsDryRun(?string $reason, bool $apply = false): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new RuntimeException('Informe --reason para gerar o dry-run de repair de checksums.');
        }

        $startedAt = date('Y-m-d H:i:s');
        $diagnosis = $this->checksumDiagnose();
        $rows = [];
        $eligible = [];
        $pending = [];
        $blockers = [];
        $summary = [
            'total_discovered' => (int) ($diagnosis['summary']['total'] ?? 0),
            'applied' => (int) ($diagnosis['summary']['applied'] ?? 0),
            'pending' => (int) ($diagnosis['summary']['pending'] ?? 0),
            'path_only_mismatch' => 0,
            'content_mismatch_structurally_verified' => 0,
            'content_mismatch_unverified' => 0,
            'missing_file' => 0,
            'already_v2_match' => 0,
            'eligible_for_future_repair' => 0,
            'not_touched_pending' => 0,
            'updates_executed' => 0,
        ];

        foreach ($diagnosis['migrations'] as $row) {
            $classification = (string) ($row['classification'] ?? 'unknown');
            $status = (string) ($row['status'] ?? 'unknown');
            $migration = (string) ($row['migration'] ?? '');
            $eligibleForRepair = false;
            $structuralVerification = null;
            $decisionReason = 'Classificacao nao elegivel para repair.';

            if ($classification === 'missing_file') {
                $summary['missing_file']++;
                $blockers[] = sprintf('Migration %s sem arquivo correspondente.', $migration);
                $decisionReason = 'Arquivo ausente; repair bloqueado.';
            } elseif ($classification === 'pending') {
                $classification = 'pending_not_touched';
                $summary['not_touched_pending']++;
                $decisionReason = 'Migration pendente; nao tocar em metadados.';
            } elseif (($row['match_new'] ?? false) === true) {
                $classification = 'already_v2_match';
                $summary['already_v2_match']++;
                $decisionReason = 'Checksum armazenado ja corresponde ao algoritmo v2.';
            } elseif ($classification === 'path_only_mismatch') {
                $summary['path_only_mismatch']++;
                $eligibleForRepair = $status === 'applied';
                $decisionReason = 'Checksum armazenado bate com algoritmo legado em root conhecido; v2 remove dependencia de path absoluto.';
            } elseif ($classification === 'content_mismatch') {
                $actualConnection = (string) ($row['connection'] ?? $this->resolveActualConnection('canonical'));
                $structuralVerification = $this->structuralVerificationForMigration(
                    $this->connection($actualConnection),
                    $migration
                );

                if (($structuralVerification['verified'] ?? false) === true) {
                    $classification = 'content_mismatch_structurally_verified';
                    $summary['content_mismatch_structurally_verified']++;
                    $eligibleForRepair = $status === 'applied';
                    $decisionReason = 'Checksum historico nao reproduzido, mas efeito estrutural esperado foi confirmado via information_schema.';
                } else {
                    $classification = 'content_mismatch_unverified';
                    $summary['content_mismatch_unverified']++;
                    $blockers[] = sprintf('Migration %s tem content_mismatch sem validacao estrutural completa.', $migration);
                    $decisionReason = 'Content mismatch sem validacao estrutural completa; repair bloqueado.';
                }
            }

            if ($status !== 'applied' && $eligibleForRepair) {
                $eligibleForRepair = false;
                $blockers[] = sprintf('Migration %s com status %s apareceu como elegivel indevidamente.', $migration, $status);
                $decisionReason = 'Status nao aplicado; repair bloqueado.';
            }

            $planRow = [
                'migration' => $migration,
                'file' => $row['file'] ?? null,
                'file_basename' => $row['file_basename'] ?? null,
                'status' => $status,
                'connection' => $row['connection'] ?? null,
                'stored_checksum' => $row['stored_checksum'] ?? null,
                'legacy_checksum' => $row['legacy_checksum'] ?? null,
                'new_checksum_v2' => $row['new_checksum'] ?? null,
                'match_legacy' => (bool) ($row['match_legacy'] ?? false),
                'match_legacy_roots' => $row['match_legacy_roots'] ?? [],
                'match_new_v2' => (bool) ($row['match_new'] ?? false),
                'classification' => $classification,
                'eligible_for_future_repair' => $eligibleForRepair,
                'decision_reason' => $decisionReason,
                'repair_reason' => $reason,
                'structural_verification' => $structuralVerification,
            ];

            if ($eligibleForRepair) {
                $eligible[] = $planRow;
                $summary['eligible_for_future_repair']++;
            }
            if ($classification === 'pending_not_touched') {
                $pending[] = $planRow;
            }

            $rows[] = $planRow;
        }

        if ($summary['missing_file'] > 0) {
            $blockers[] = 'missing_file > 0.';
        }
        if ($summary['content_mismatch_unverified'] > 0) {
            $blockers[] = 'Ha content_mismatch sem validacao estrutural.';
        }
        if ($summary['eligible_for_future_repair'] !== 34) {
            $blockers[] = sprintf(
                'Total de elegiveis esperado era 34, obtido %d.',
                $summary['eligible_for_future_repair']
            );
        }
        foreach ($pending as $pendingRow) {
            if (($pendingRow['eligible_for_future_repair'] ?? false) === true) {
                $blockers[] = sprintf('Migration pendente %s apareceu como elegivel.', (string) $pendingRow['migration']);
            }
        }

        $canonicalConnection = $this->resolveActualConnection('canonical');
        $canonicalPdo = $this->connection($canonicalConnection);
        $environment = $this->repairPlanEnvironment();
        $auditPayload = [
            'timestamp' => $startedAt,
            'mode' => $apply ? 'apply' : 'dry-run',
            'apply_requested' => $apply,
            'updates_executed' => 0,
            'reason' => $reason,
            'environment' => $environment,
            'history_table' => $this->historyTableMetadata($canonicalPdo),
            'summary' => $summary,
            'blockers' => array_values(array_unique($blockers)),
            'eligible_rows' => $eligible,
            'pending_not_touched' => $pending,
            'migrations' => $rows,
        ];

        if ($blockers !== []) {
            $auditLogPath = $this->writeRepairDryRunLog($auditPayload);
            throw new RuntimeException(
                'Plano de repair bloqueado: ' . implode(' ', array_values(array_unique($blockers)))
                . ' Log: ' . $auditLogPath
            );
        }

        if (!$apply) {
            $auditLogPath = $this->writeRepairDryRunLog($auditPayload);
            $auditPayload['audit_log_path'] = $auditLogPath;
            return $auditPayload;
        }

        $backupPath = $this->writeRepairBackup($auditPayload);
        $updates = $this->applyChecksumRepair($eligible);
        $finishedAt = date('Y-m-d H:i:s');

        $summary['updates_executed'] = count($updates);
        $applyPayload = [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'mode' => 'apply',
            'reason' => $reason,
            'environment' => $environment,
            'history_table' => $auditPayload['history_table'],
            'backup_path' => $backupPath,
            'summary' => $summary,
            'updates_executed' => count($updates),
            'migrations_updated' => $updates,
            'pending_not_touched' => $pending,
            'blockers' => [],
        ];
        $applyLogPath = $this->writeRepairApplyLog($applyPayload);

        return [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'mode' => 'apply',
            'reason' => $reason,
            'backup_path' => $backupPath,
            'apply_log_path' => $applyLogPath,
            'summary' => $summary,
            'updates_executed' => count($updates),
            'migrations_updated' => $updates,
            'pending_not_touched' => $pending,
        ];
    }

    /**
     * @param list<array<string,mixed>> $eligible
     * @return list<array<string,mixed>>
     */
    private function applyChecksumRepair(array $eligible): array
    {
        $updated = [];
        $byConnection = [];
        foreach ($eligible as $row) {
            $connection = (string) ($row['connection'] ?? $this->resolveActualConnection('canonical'));
            $byConnection[$connection][] = $row;
        }

        foreach ($byConnection as $connection => $rows) {
            $pdo = $this->connection((string) $connection);
            foreach ($rows as $row) {
                $record = $this->fetchAppliedRecord((string) $connection, (string) $row['migration']);
                if ($record === null || (string) ($record['status'] ?? '') !== 'applied') {
                    throw new RuntimeException('Repair abortado: registro aplicado nao encontrado para ' . (string) $row['migration']);
                }
                if (!hash_equals((string) ($record['checksum'] ?? ''), (string) $row['stored_checksum'])) {
                    throw new RuntimeException('Repair abortado: checksum mudou antes do UPDATE em ' . (string) $row['migration']);
                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(sprintf(
                    'UPDATE %s SET checksum = :checksum WHERE schema_version = :version AND checksum = :old_checksum AND status = :status',
                    $this->migrationTable()
                ));

                foreach ($rows as $row) {
                    $stmt->execute([
                        'checksum' => (string) $row['new_checksum_v2'],
                        'version' => (string) $row['migration'],
                        'old_checksum' => (string) $row['stored_checksum'],
                        'status' => 'applied',
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Repair abortado: UPDATE inesperado para ' . (string) $row['migration']);
                    }

                    $updated[] = [
                        'migration' => $row['migration'],
                        'connection' => $connection,
                        'file_basename' => $row['file_basename'] ?? null,
                        'old_checksum' => $row['stored_checksum'],
                        'new_checksum' => $row['new_checksum_v2'],
                        'classification' => $row['classification'],
                        'decision_reason' => $row['decision_reason'],
                    ];
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $updated;
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
        $buffer = "BIOMIE-MIGRATION-CHECKSUM:v2\n";
        $buffer .= $this->relativeChecksumPath($migrationFile) . "\n";
        $content = file_get_contents($migrationFile);
        if ($content === false) {
            throw new RuntimeException('Falha ao ler migration para checksum: ' . $migrationFile);
        }

        $buffer .= $this->normalizeChecksumContent($content);
        foreach (array_values(array_unique($sources)) as $source) {
            if (!is_file($source)) {
                throw new RuntimeException('Arquivo extra de checksum nao encontrado: ' . $source);
            }

            $sourceContent = file_get_contents($source);
            if ($sourceContent === false) {
                throw new RuntimeException('Falha ao ler checksum source: ' . $source);
            }

            $buffer .= "\n" . $this->relativeChecksumPath($source) . "\n" . $this->normalizeChecksumContent($sourceContent);
        }

        return hash('sha256', $buffer);
    }

    /**
     * @param list<string> $sources
     */
    private function computeLegacyChecksum(string $migrationFile, array $sources, ?string $projectRootOverride = null): string
    {
        $buffer = $this->legacyChecksumPath($migrationFile, $projectRootOverride) . "\n";
        $content = file_get_contents($migrationFile);
        if ($content === false) {
            throw new RuntimeException('Falha ao ler migration para checksum legado: ' . $migrationFile);
        }

        $buffer .= $content;
        foreach (array_values(array_unique($sources)) as $source) {
            if (!is_file($source)) {
                throw new RuntimeException('Arquivo extra de checksum legado nao encontrado: ' . $source);
            }

            $sourceContent = file_get_contents($source);
            if ($sourceContent === false) {
                throw new RuntimeException('Falha ao ler checksum source legado: ' . $source);
            }

            $buffer .= "\n" . $this->legacyChecksumPath($source, $projectRootOverride) . "\n" . $sourceContent;
        }

        return hash('sha256', $buffer);
    }

    private function normalizeChecksumContent(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return rtrim($content, "\n") . "\n";
    }

    private function relativeChecksumPath(string $path): string
    {
        $normalizedPath = $this->normalizePath((string) (realpath($path) ?: $path));
        $root = $this->normalizePath((string) (realpath(Bootstrap::rootPath()) ?: Bootstrap::rootPath()));
        if ($normalizedPath === $root) {
            return '.';
        }

        $prefix = rtrim($root, '/') . '/';
        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, strlen($prefix));
        }

        return 'external/' . basename($normalizedPath);
    }

    private function legacyChecksumPath(string $path, ?string $projectRootOverride = null): string
    {
        $normalizedPath = $this->normalizePath((string) (realpath($path) ?: $path));
        if ($projectRootOverride === null || $projectRootOverride === '') {
            return $normalizedPath;
        }

        $root = $this->normalizePath((string) (realpath(Bootstrap::rootPath()) ?: Bootstrap::rootPath()));
        $prefix = rtrim($root, '/') . '/';
        if (str_starts_with($normalizedPath, $prefix)) {
            return rtrim($this->normalizePath($projectRootOverride), '/') . '/' . substr($normalizedPath, strlen($prefix));
        }

        return $normalizedPath;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @param array<string,mixed> $migration
     * @return list<string>
     */
    private function checksumSources(array $migration): array
    {
        $instance = $migration['instance'] ?? null;
        if ($instance instanceof MigrationInterface) {
            return $instance->getChecksumSources();
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function legacyChecksumRootCandidates(): array
    {
        $roots = [
            Bootstrap::rootPath(),
        ];

        $envRoots = getenv('BIOMIE_MIGRATION_LEGACY_ROOTS');
        if (is_string($envRoots) && trim($envRoots) !== '') {
            foreach (preg_split('/[,;' . preg_quote(PATH_SEPARATOR, '/') . ']+/', $envRoots) ?: [] as $root) {
                $root = trim($root);
                if ($root !== '') {
                    $roots[] = $root;
                }
            }
        }

        foreach ([
            Bootstrap::rootPath('cron/hostinger/worker.sh'),
            Bootstrap::rootPath('cron/hostinger/sync_finance.sh'),
            Bootstrap::rootPath('cron/hostinger/sync_commercial.sh'),
            Bootstrap::rootPath('cron/hostinger/materialize.sh'),
            Bootstrap::rootPath('cronjobs/materialize_competencia_snapshots.sh'),
        ] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (preg_match_all('/(?:cd\s+|php\s+)(\/home\/[^\s"\']+?\/biomie)(?:\s|\/)/', $content, $matches)) {
                foreach ($matches[1] as $root) {
                    $roots[] = $root;
                }
            }
        }

        $normalized = [];
        foreach ($roots as $root) {
            $root = rtrim($this->normalizePath($root), '/');
            if ($root !== '' && !in_array($root, $normalized, true)) {
                $normalized[] = $root;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function repairPlanEnvironment(): array
    {
        $dbVersion = null;
        $dbName = null;
        $actualConnection = $this->resolveActualConnection('canonical');
        if ($this->isActualConnectionConfigured($actualConnection)) {
            try {
                $pdo = $this->connection($actualConnection);
                $dbVersion = (string) ($pdo->query('SELECT VERSION()')->fetchColumn() ?: '');
                $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            } catch (Throwable) {}
        }

        return [
            'environment' => (string) $this->config->get('app.env', getenv('APP_ENV') ?: 'production'),
            'app_version' => $this->appVersion,
            'php_version' => PHP_VERSION,
            'db_version' => $dbVersion,
            'db_name' => $dbName,
            'migrations_table' => $this->tableName,
            'checksum_algorithm' => 'v2:relative-path-plus-normalized-content',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function historyTableMetadata(PDO $pdo): array
    {
        $columns = $pdo->query('SHOW COLUMNS FROM ' . $this->migrationTable())?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexes = $pdo->query('SHOW INDEX FROM ' . $this->migrationTable())?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $primaryKey = [];
        $uniqueKeys = [];
        foreach ($indexes as $index) {
            $keyName = (string) ($index['Key_name'] ?? '');
            $column = (string) ($index['Column_name'] ?? '');
            if ($keyName === 'PRIMARY') {
                $primaryKey[] = $column;
            }
            if ((int) ($index['Non_unique'] ?? 1) === 0 && $keyName !== 'PRIMARY') {
                $uniqueKeys[$keyName][] = $column;
            }
        }

        return [
            'table' => $this->tableName,
            'primary_key' => $primaryKey,
            'unique_keys' => $uniqueKeys,
            'version_field' => 'schema_version',
            'checksum_field' => 'checksum',
            'status_field' => 'status',
            'executed_at_field' => 'executed_at',
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    /**
     * @return array{verified:bool,expected:int,found:int,missing:list<string>,checks:list<array<string,mixed>>}
     */
    private function structuralVerificationForMigration(PDO $pdo, string $version): array
    {
        $definitions = $this->structuralVerificationDefinitions();
        $checks = $definitions[$version] ?? [];
        $checked = [];
        $missing = [];

        foreach ($checks as $check) {
            [$kind, $table, $name] = $check;
            $ok = match ($kind) {
                'table_absent' => !$this->tableExistsReadOnly($pdo, $table),
                'table' => $this->tableExistsReadOnly($pdo, $table),
                'column' => $this->columnExistsReadOnly($pdo, $table, $name),
                'index' => $this->indexExistsReadOnly($pdo, $table, $name),
                default => false,
            };

            $checked[] = [
                'kind' => $kind,
                'table' => $table,
                'name' => $name,
                'ok' => $ok,
            ];

            if (!$ok) {
                $missing[] = $kind . ':' . $table . ($name !== '' ? '.' . $name : '');
            }
        }

        return [
            'verified' => $checks !== [] && $missing === [],
            'expected' => count($checks),
            'found' => count($checks) - count($missing),
            'missing' => $missing,
            'checks' => $checked,
        ];
    }

    /**
     * @return array<string,list<array{0:string,1:string,2:string}>>
     */
    private function structuralVerificationDefinitions(): array
    {
        return [
            '20260515000000' => [
                ['index', 'mart_bridge_pedido_nfe_titulo', 'idx_bridge_vendor_nfe_date'],
                ['index', 'mart_bridge_pedido_nfe_titulo', 'idx_bridge_vendor_faturamento'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_vendor_status_comp'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_pedido_origem'],
                ['index', 'biomie_pedidos_canonicos', 'idx_pedido_vendor_status_data'],
                ['index', 'biomie_notas_fiscais_itens_canonicos', 'idx_nf_itens_nota_key'],
            ],
            '20260516000000' => [
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_cliente'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_comp_status_cliente'],
                ['index', 'mart_cliente_periodo', 'idx_mart_cliente_periodo_comp'],
            ],
            '20260516000001' => [
                ['index', 'biomie_notas_fiscais_itens_canonicos', 'idx_nf_itens_produto_nota'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_nota'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_vendor_comp_status'],
                ['index', 'mart_bridge_pedido_nfe_titulo', 'idx_bridge_nota_key'],
                ['index', 'mart_produto_periodo', 'idx_mart_produto_periodo_comp_prod'],
            ],
            '20260516000002' => [
                ['index', 'mart_compradores_por_geo', 'idx_geo_mart_bairro_comp'],
                ['index', 'mart_compradores_por_geo', 'idx_geo_mart_cnae_comp'],
                ['index', 'mart_compradores_por_geo', 'idx_geo_mart_cliente_comp'],
                ['index', 'biomie_clientes_geo_dim', 'idx_clientes_geo_bairro_norm'],
                ['index', 'biomie_clientes_geo_dim', 'idx_clientes_geo_codigo'],
                ['index', 'biomie_bairros_fortaleza_dim', 'idx_bairros_dim_nome_norm'],
            ],
            '20260516000004' => [
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_nota'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_cliente'],
                ['index', 'biomie_notas_fiscais_canonicas', 'idx_nf_status_comp_vendor_arch10'],
                ['index', 'biomie_notas_fiscais_itens_canonicos', 'idx_itens_nota_produto_arch10'],
                ['index', 'biomie_notas_fiscais_itens_canonicos', 'idx_itens_produto_nota_arch10'],
                ['index', 'mart_compradores_por_geo', 'idx_geo_compradores_city_bairro_comp'],
            ],
            '20260517000001' => [
                ['table_absent', 'omie_pivot2_faturamento_staging', ''],
            ],
            '20260517000002' => [
                ['column', 'mart_compradores_por_geo', 'cidade_key'],
                ['column', 'mart_compradores_por_geo', 'bairro_key'],
                ['column', 'mart_compradores_por_geo', 'cidade_nome'],
                ['column', 'mart_compradores_por_geo', 'bairro_nome'],
                ['index', 'mart_compradores_por_geo', 'idx_geo_buyers_city_bairro_key_comp'],
                ['column', 'mart_vendas_por_cnae_cnpj', 'cidade_key'],
                ['column', 'mart_vendas_por_cnae_cnpj', 'bairro_key'],
                ['index', 'mart_vendas_por_cnae_cnpj', 'idx_cnae_city_bairro_key_comp'],
                ['column', 'biomie_clientes_dim', 'cidade_key'],
                ['column', 'biomie_clientes_dim', 'bairro_key'],
                ['index', 'biomie_clientes_dim', 'idx_clientes_dim_city_bairro_key'],
                ['column', 'biomie_clientes_geo_dim', 'cidade_key'],
                ['column', 'biomie_clientes_geo_dim', 'bairro_key'],
                ['index', 'biomie_clientes_geo_dim', 'idx_geo_dim_city_bairro_key'],
            ],
            '20260518120000' => [
                ['column', 'mart_compradores_por_geo', 'geo_quality'],
                ['column', 'mart_compradores_por_geo', 'geo_scope'],
                ['index', 'mart_compradores_por_geo', 'idx_geo_buyers_quality_scope'],
            ],
        ];
    }

    private function tableExistsReadOnly(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExistsReadOnly(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExistsReadOnly(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeRepairDryRunLog(array $payload): string
    {
        $dir = Bootstrap::rootPath('storage/logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/migration_repair_dry_run_' . date('Ymd_His') . '.json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException('Falha ao gravar log de dry-run de repair: ' . $path);
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeRepairBackup(array $payload): string
    {
        $dir = Bootstrap::rootPath('storage/backups/migrations');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $planByMigration = [];
        foreach (($payload['migrations'] ?? []) as $planRow) {
            if (is_array($planRow) && isset($planRow['migration'])) {
                $planByMigration[(string) $planRow['migration']] = $planRow;
            }
        }

        $connections = [];
        foreach (($payload['migrations'] ?? []) as $planRow) {
            if (is_array($planRow) && isset($planRow['connection'])) {
                $connections[] = (string) $planRow['connection'];
            }
        }
        $connections = array_values(array_unique(array_filter($connections)));

        $records = [];
        $seen = [];
        foreach ($connections as $connection) {
            if (!$this->isActualConnectionConfigured($connection)) {
                continue;
            }

            $pdo = $this->connection($connection);
            $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            $stmt = $pdo->query(sprintf(
                'SELECT * FROM %s ORDER BY schema_version',
                $this->migrationTable()
            ));
            foreach (($stmt?->fetchAll(PDO::FETCH_ASSOC) ?: []) as $record) {
                $key = $dbName . ':' . (string) ($record['id'] ?? '') . ':' . (string) ($record['schema_version'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $version = (string) ($record['schema_version'] ?? '');
                $plan = $planByMigration[$version] ?? null;
                $records[] = [
                    'connection' => $connection,
                    'database' => $dbName,
                    'table' => $this->tableName,
                    'record' => $record,
                    'predicted_new_checksum_v2' => is_array($plan) ? ($plan['new_checksum_v2'] ?? null) : null,
                    'classification' => is_array($plan) ? ($plan['classification'] ?? null) : null,
                    'eligible_for_future_repair' => is_array($plan) ? (bool) ($plan['eligible_for_future_repair'] ?? false) : false,
                    'reason' => $payload['reason'] ?? null,
                ];
            }
        }

        $backup = [
            'timestamp' => date('Y-m-d H:i:s'),
            'reason' => $payload['reason'] ?? null,
            'environment' => $payload['environment'] ?? [],
            'history_table' => $payload['history_table'] ?? [],
            'total_records' => count($records),
            'summary_before_repair' => $payload['summary'] ?? [],
            'records' => $records,
        ];

        $path = $dir . '/schema_history_before_repair_' . date('Ymd_His') . '.json';
        $json = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException('Falha ao gravar backup da tabela de migrations: ' . $path);
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeRepairApplyLog(array $payload): string
    {
        $dir = Bootstrap::rootPath('storage/logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/migration_repair_apply_' . date('Ymd_His') . '.json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException('Falha ao gravar log de apply de repair: ' . $path);
        }

        return $path;
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
     * Executa exatamente uma migration específica sem rodar as anteriores pendentes.
     *
     * @return array{applied:list<array<string,mixed>>,skipped:list<array<string,mixed>>}
     */
    public function migrateOne(string $targetVersion): array
    {
        $migration = $this->findMigrationByVersion($targetVersion);
        if ($migration === null) {
            throw new RuntimeException('Migration ' . $targetVersion . ' nao encontrada.');
        }

        $actualConnection = $this->resolveActualConnection((string) $migration['logical_connection']);
        if (!$this->isActualConnectionConfigured($actualConnection)) {
            throw new RuntimeException(sprintf(
                'Conexao "%s" nao configurada para migration %s.',
                $actualConnection,
                $targetVersion
            ));
        }

        $record = $this->fetchAppliedRecord($actualConnection, $targetVersion);
        if ($record !== null && (string) ($record['status'] ?? 'applied') === 'applied') {
            return [
                'applied' => [],
                'skipped' => [[
                    'version' => $targetVersion,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'reason' => 'already_applied',
                ]],
            ];
        }

        $pdo = $this->connection($actualConnection);
        // Portão de produção ANTES do DDL (commit implícito, sem rollback).
        ProductionDatabaseGuard::assertWriteAllowed($pdo, 'migrate-one ' . $targetVersion);
        $startedAt = microtime(true);

        try {
            /** @var MigrationInterface $instance */
            $instance = $migration['instance'];
            $instance->up($pdo);
            $executionMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->storeAppliedRecord($actualConnection, $migration, $executionMs);
            $this->logger->info('Migration individual aplicada.', [
                'version' => $targetVersion,
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'execution_ms' => $executionMs,
            ]);

            return [
                'applied' => [[
                    'version' => $targetVersion,
                    'description' => $migration['description'],
                    'connection' => $actualConnection,
                    'type' => $migration['type'],
                    'execution_ms' => $executionMs,
                ]],
                'skipped' => [],
            ];
        } catch (Throwable $exception) {
            $executionMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('Falha ao aplicar migration individual.', [
                'version' => $targetVersion,
                'description' => $migration['description'],
                'connection' => $actualConnection,
                'message' => $exception->getMessage(),
            ]);
            $this->logMigrationFailure($migration, $exception, $executionMs);

            throw new MigrationFailedException(
                sprintf('Falha ao aplicar migration %s: %s', $targetVersion, $exception->getMessage()),
                $targetVersion,
                (string) $migration['description'],
                basename((string) $migration['path']),
                $actualConnection,
                $executionMs,
                $exception
            );
        }
    }

    /**
     * Registra falha de migration em biomie_operational_events (best-effort; ignora se tabela ausente).
     *
     * @param array<string,mixed> $migration
     */
    private function logMigrationFailure(array $migration, Throwable $exception, int $executionMs): void
    {
        try {
            $conn = $this->resolveActualConnection('canonical');
            if (!$this->isActualConnectionConfigured($conn)) {
                return;
            }
            $pdo = $this->connections[$conn] ?? Database::connect($this->config, $conn);
            $stmt = $pdo->prepare(
                "INSERT INTO biomie_operational_events
                 (level_name, event_name, domain_name, source_name, summary, context_json, occurred_at)
                 VALUES ('ERROR', 'migration.failed', 'migrations', 'migration_runner', :summary, :context, NOW())"
            );
            $stmt->execute([
                'summary' => sprintf(
                    'Migration %s falhou: %s',
                    $migration['version'],
                    mb_substr($exception->getMessage(), 0, 200)
                ),
                'context' => json_encode([
                    'schema_version' => $migration['version'],
                    'description'    => $migration['description'],
                    'file'           => basename((string) $migration['path']),
                    'connection'     => $this->resolveActualConnection((string) $migration['logical_connection']),
                    'error_message'  => $exception->getMessage(),
                    'execution_ms'   => $executionMs,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            // biomie_operational_events pode nao existir ainda — ignorar silenciosamente
        }
    }

    /**
     * Classifica uma migration em grupo/domínio por heurística.
     */
    private static function classifyDomainGroup(string $version, string $description, string $filename): string
    {
        $lower = strtolower($description . ' ' . $filename);

        if (str_contains($lower, 'rbac') || str_contains($lower, 'admin') || str_contains($lower, 'access_control') || str_contains($lower, 'accesscontrol')) {
            return 'RBAC/Admin';
        }
        if (str_contains($lower, 'geo') || str_contains($lower, 'geographic') || str_contains($lower, 'compradores') || str_contains($lower, 'regiao')) {
            return 'Geografia';
        }
        if (str_contains($lower, 'cnae') || str_contains($lower, 'cnpj')) {
            return 'CNAE/Compradores';
        }
        if (str_contains($lower, 'gold') || str_contains($lower, 'mart') || str_contains($lower, 'opportunity') || str_contains($lower, 'forecast')) {
            return 'Gold/Analytics';
        }
        if (str_contains($lower, 'index') || str_contains($lower, 'performance')) {
            return 'Performance';
        }
        if (str_contains($lower, 'observ') || str_contains($lower, 'pipeline') || str_contains($lower, 'status_check') || str_contains($lower, 'omie')) {
            return 'Observabilidade';
        }
        if (str_contains($lower, 'governance') || str_contains($lower, 'enrichment')) {
            return 'Governanca';
        }
        if (str_contains($lower, 'canonical') || str_contains($lower, 'baseline') || str_contains($lower, 'bronze')) {
            return 'Core/Canonical';
        }
        if (str_contains($lower, 'reconcil') || str_contains($lower, 'competencia') || str_contains($lower, 'snapshot')) {
            return 'Reconciliacao';
        }
        if (str_contains($lower, 'auth') || str_contains($lower, 'flight_plan') || str_contains($lower, 'flightplan')) {
            return 'Core';
        }

        return 'Geral';
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
