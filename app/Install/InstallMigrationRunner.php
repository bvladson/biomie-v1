<?php

declare(strict_types=1);

namespace BIomie\Install;

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;
use Throwable;

final class InstallMigrationRunner
{
    public function __construct(private readonly InstallLogger $installLogger)
    {
    }

    /**
     * @return array{ok:bool,message:string,result:array<string,mixed>}
     */
    public function status(): array
    {
        try {
            $runner = $this->runner();
            $result = $runner->status();
            return [
                'ok' => true,
                'message' => 'Status de migrations carregado.',
                'result' => $this->sanitizeStatus($result),
            ];
        } catch (Throwable $exception) {
            $this->installLogger->error('migration_status_failed', ['message' => $exception->getMessage()]);
            return [
                'ok' => false,
                'message' => 'Nao foi possivel carregar o status das migrations.',
                'result' => ['summary' => [], 'migrations' => []],
            ];
        }
    }

    /**
     * @return array{ok:bool,message:string,result:array<string,mixed>}
     */
    public function migrate(): array
    {
        $started = microtime(true);
        try {
            $this->installLogger->info('migration_run_started');
            $result = $this->runner()->migrate();
            $this->installLogger->info('migration_run_finished', [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'applied' => count($result['applied'] ?? []),
                'skipped' => count($result['skipped'] ?? []),
            ]);

            return [
                'ok' => true,
                'message' => 'Migrations aplicadas com sucesso.',
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            $this->installLogger->error('migration_run_failed', ['message' => $exception->getMessage()]);
            return [
                'ok' => false,
                'message' => 'Uma migration falhou. A execucao foi interrompida; veja o log sanitizado.',
                'result' => [],
            ];
        }
    }

    private function runner(): MigrationRunner
    {
        $config = new Config();
        $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
        return new MigrationRunner(
            $config,
            $logger,
            Bootstrap::rootPath(trim((string) $config->get('migrations.path', 'database/migrations'), '/')),
            (string) $config->get('migrations.table', 'biomie_schema_migrations'),
            (string) $config->get('app.version', (string) $config->get('migrations.app_version', 'dev'))
        );
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function sanitizeStatus(array $status): array
    {
        foreach ($status['migrations'] ?? [] as &$migration) {
            if (isset($migration['error'])) {
                $migration['error'] = 'Falha de conexao ou permissao no banco.';
            }
            if (isset($migration['file'])) {
                $migration['file'] = basename((string) $migration['file']);
            }
        }
        unset($migration);

        return $status;
    }
}
