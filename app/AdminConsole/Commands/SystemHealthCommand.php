<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;
use PDO;
use Throwable;

final class SystemHealthCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly string $appVersion,
        private readonly string $appEnv,
    ) {}

    public function name(): string { return 'system:health'; }
    public function description(): string { return 'Exibe saude geral do sistema: PHP, banco, migrations e servicos.'; }
    public function group(): string { return 'sistema'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $lines = [];
        $warnings = [];
        $metrics = [];

        // PHP
        $phpVersion = PHP_VERSION;
        $lines[] = "PHP: {$phpVersion}";
        $metrics['php_version'] = $phpVersion;

        // Ambiente
        $lines[] = "Ambiente: {$this->appEnv}";
        $lines[] = "Versao app: {$this->appVersion}";
        $metrics['app_env'] = $this->appEnv;
        $metrics['app_version'] = $this->appVersion;

        // Banco
        try {
            $dbVersion = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $charset = (string) $this->pdo->query('SELECT @@character_set_database')->fetchColumn();
            $timezone = (string) $this->pdo->query('SELECT @@time_zone')->fetchColumn();
            $dbName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $lines[] = "MariaDB/MySQL: {$dbVersion}";
            $lines[] = "Banco ativo: {$dbName}";
            $lines[] = "Charset: {$charset}";
            $lines[] = "Timezone banco: {$timezone}";
            $metrics['db_version'] = $dbVersion;
            $metrics['db_name'] = $dbName;
            $metrics['charset'] = $charset;
        } catch (Throwable) {
            $warnings[] = 'Nao foi possivel consultar versao do banco.';
        }

        // Migrations
        try {
            $runner = new MigrationRunner($this->config, $this->logger);
            $status = $runner->statusWithDiagnostics();
            $summary = $status['summary'] ?? [];
            $discovered = (int) ($summary['discovered'] ?? 0);
            $applied = (int) ($summary['applied'] ?? 0);
            $pending = (int) ($summary['pending'] ?? 0);
            $lines[] = "Migrations: {$applied}/{$discovered} aplicadas, {$pending} pendentes";
            $metrics['migrations_applied'] = $applied;
            $metrics['migrations_pending'] = $pending;
            if ($pending > 0) {
                $warnings[] = "{$pending} migration(s) pendente(s) neste banco.";
            }
        } catch (Throwable) {
            $warnings[] = 'Nao foi possivel verificar status de migrations.';
        }

        // Timezone PHP
        $phpTz = date_default_timezone_get();
        $lines[] = "Timezone PHP: {$phpTz}";
        $metrics['php_timezone'] = $phpTz;

        $status = $warnings === [] ? 'ok' : 'warning';

        if ($status === 'ok') {
            return AdminCommandResult::ok(
                'Saude do sistema',
                'Sistema operacional sem pendencias detectadas.',
                outputLines: $lines,
                metrics: $metrics,
            );
        }

        return AdminCommandResult::warning(
            'Saude do sistema',
            'Sistema operacional com alertas. Verifique os avisos.',
            outputLines: $lines,
            warnings: $warnings,
        );
    }
}
