<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;
use Throwable;

final class MigrationsStatusCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    public function name(): string { return 'migrations:status'; }
    public function description(): string { return 'Exibe status das migrations: descobertas, aplicadas, pendentes.'; }
    public function group(): string { return 'migrations'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        try {
            $runner = new MigrationRunner($this->config, $this->logger);
            $status = $runner->statusWithDiagnostics();
        } catch (Throwable) {
            return AdminCommandResult::error(
                'Status de migrations',
                'Nao foi possivel consultar o status das migrations.',
                errors: ['Erro interno registrado no log.'],
            );
        }

        $summary = $status['summary'] ?? [];
        $discovered = (int) ($summary['discovered'] ?? 0);
        $applied = (int) ($summary['applied'] ?? 0);
        $pending = (int) ($summary['pending'] ?? 0);
        $rolledBack = (int) ($summary['rolled_back'] ?? 0);
        $checksumMismatch = (int) ($summary['checksum_mismatch'] ?? 0);

        $lines = [
            "Descobertas: {$discovered}",
            "Aplicadas: {$applied}",
            "Pendentes: {$pending}",
            "Revertidas: {$rolledBack}",
            "Checksum divergente: {$checksumMismatch}",
            "Tabela de controle: biomie_schema_migrations",
        ];

        $warnings = [];
        if ($pending > 0) {
            $warnings[] = "{$pending} migration(s) encontrada(s) nao aplicada(s).";
        }
        if ($checksumMismatch > 0) {
            $warnings[] = "{$checksumMismatch} migration(s) com checksum alterado apos aplicacao.";
        }

        // Build table of individual migrations
        $migrationRows = [];
        foreach (($status['migrations'] ?? []) as $migration) {
            $version = (string) ($migration['schema_version'] ?? $migration['version'] ?? '?');
            $desc = mb_substr((string) ($migration['description'] ?? ''), 0, 60);
            $mStatus = (string) ($migration['status'] ?? 'unknown');
            $executedAt = (string) ($migration['executed_at'] ?? '-');
            $connection = (string) ($migration['connection'] ?? '-');
            $migrationRows[] = [$version, $desc, $mStatus, $executedAt, $connection];
        }

        $tables = [];
        if ($migrationRows !== []) {
            $tables[] = [
                'title'   => 'Migrations',
                'headers' => ['Versao', 'Descricao', 'Status', 'Executada em', 'Conexao'],
                'rows'    => $migrationRows,
            ];
        }

        $metrics = [
            'discovered'       => $discovered,
            'applied'          => $applied,
            'pending'          => $pending,
            'rolled_back'      => $rolledBack,
            'checksum_mismatch' => $checksumMismatch,
        ];

        if ($warnings !== []) {
            return AdminCommandResult::warning(
                'Status de migrations',
                "{$applied}/{$discovered} migrations aplicadas. {$pending} pendente(s).",
                outputLines: $lines,
                warnings: $warnings,
            );
        }

        return AdminCommandResult::ok(
            'Status de migrations',
            "{$applied}/{$discovered} migrations aplicadas. Nenhuma pendencia.",
            outputLines: $lines,
            metrics: $metrics,
            tables: $tables,
        );
    }
}
