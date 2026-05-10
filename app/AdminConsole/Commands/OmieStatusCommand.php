<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Config;
use PDO;
use Throwable;

final class OmieStatusCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {}

    public function name(): string { return 'omie:status'; }
    public function description(): string { return 'Exibe status da integracao Omie (credenciais, contagens, ultimo sync).'; }
    public function group(): string { return 'omie'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $lines = [];
        $metrics = [];
        $warnings = [];

        // Credentials configured (never expose values)
        $appKey = (string) $this->config->get('omie.app_key', '');
        $appSecret = (string) $this->config->get('omie.app_secret', '');
        $credOk = $appKey !== '' && $appSecret !== '' && $appKey !== 'SUA_APP_KEY' && $appSecret !== 'SEU_APP_SECRET';
        $lines[] = 'Credenciais configuradas: ' . ($credOk ? 'sim' : 'nao');
        $metrics['credentials_ok'] = $credOk ? 1 : 0;

        if (!$credOk) {
            $warnings[] = 'Credenciais Omie nao configuradas. Sincronizacao indisponivel.';
        }

        // Bronze — each query in its own try-catch so a column miss does not
        // hide a successful COUNT and produce a confusing generic warning.
        $bronzeExists = false;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_omie_bronze']);
            $bronzeExists = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {}

        if (!$bronzeExists) {
            $warnings[] = 'Tabela biomie_omie_bronze nao existe.';
        } else {
            try {
                $bronzeCount = (int) $this->pdo->query('SELECT COUNT(*) FROM `biomie_omie_bronze`')->fetchColumn();
                $lines[] = "Bronze (registros): {$bronzeCount}";
                $metrics['bronze_count'] = $bronzeCount;
            } catch (Throwable) {
                $warnings[] = 'Nao foi possivel contar registros em biomie_omie_bronze.';
            }

            try {
                $lastBronze = $this->pdo->query(
                    'SELECT MAX(ingested_at) FROM `biomie_omie_bronze`'
                )?->fetchColumn();
                if ($lastBronze) {
                    $lines[] = "Ultimo bronze: {$lastBronze}";
                    $metrics['last_bronze_sync'] = (string) $lastBronze;
                }
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'unknown column') || str_contains($msg, 'column not found')) {
                    $warnings[] = 'Coluna ingested_at ausente em biomie_omie_bronze — verifique a migration.';
                }
                // Otherwise silently skip the timestamp metric (non-critical)
            }
        }

        // Canonical counts
        $canonicalTables = [
            'biomie_pedidos_canonicos'          => 'Pedidos canonicos',
            'biomie_notas_fiscais_canonicas'    => 'NFs canonicas',
            'biomie_titulos_financeiros_canonicos' => 'Titulos financeiros',
        ];

        $rows = [];
        foreach ($canonicalTables as $table => $label) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $stmt->execute([':t' => $table]);
                $exists = (int) $stmt->fetchColumn() > 0;

                if (!$exists) {
                    $rows[] = [$label, $table, 'ausente', '-'];
                    continue;
                }

                $count = (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
                $rows[] = [$label, $table, $count > 0 ? 'ok' : 'vazio', number_format($count, 0, ',', '.')];
                $metrics[$table] = $count;
            } catch (Throwable) {
                $rows[] = [$label, $table, 'erro', '-'];
            }
        }

        // Sync runs
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_pipeline_jobs']);
            $syncTableExists = (int) $stmt->fetchColumn() > 0;

            if ($syncTableExists) {
                $syncCount = (int) $this->pdo->query('SELECT COUNT(*) FROM `biomie_pipeline_jobs`')->fetchColumn();
                $lines[] = "Sync runs registrados: {$syncCount}";
                $metrics['sync_runs'] = $syncCount;

                $lastSync = $this->pdo->query(
                    'SELECT MAX(started_at) FROM `biomie_pipeline_jobs`'
                )?->fetchColumn();
                if ($lastSync) {
                    $lines[] = "Ultimo sync: {$lastSync}";
                }
            }
        } catch (Throwable) {
            // pipeline_jobs table may not exist — silently skip
        }

        $tables = [];
        if ($rows !== []) {
            $tables[] = [
                'title'   => 'Tabelas canonicas',
                'headers' => ['Label', 'Tabela', 'Status', 'Registros'],
                'rows'    => $rows,
            ];
        }

        if ($warnings !== []) {
            return AdminCommandResult::warning(
                'Status Omie',
                'Integracao Omie com alertas.',
                outputLines: $lines,
                warnings: $warnings,
            );
        }

        return AdminCommandResult::ok(
            'Status Omie',
            'Integracao Omie configurada.',
            outputLines: $lines,
            metrics: $metrics,
            tables: $tables,
        );
    }
}
