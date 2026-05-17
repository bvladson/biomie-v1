<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

/**
 * Detecta artefatos de import manual proibidos.
 *
 * Regra absoluta: entrada de dados via CSV/planilha/Excel e proibida no BIomie.
 * A fonte oficial e exclusivamente a API Omie -> canonico -> marts.
 */
final class ProhibitedManualImportHealthCheck implements CheckInterface
{
    private const PROHIBITED_TABLES = [
        'omie_pivot2_faturamento_staging',
        'omie_pivot2_faturamento_staging_deprecated',
    ];

    private const PROHIBITED_FILES = [
        'tools/dev/import_pivot2_csv.php',
        'tools/dev/reconcile_pivot2_fiscal.php',
        'docs/reconciliacao-pivot2-omie.md',
        'database/migrations/Version20260517000000_Pivot2FaturamentoStaging.php',
        'storage/imports',
    ];

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly string $rootPath = '',
    ) {}

    public function id(): string    { return 'prohibited_manual_import'; }
    public function label(): string { return 'Import Manual Proibido'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $violations = [];
        $metrics = [
            'prohibited_tables_found' => 0,
            'prohibited_files_found'  => 0,
            'deprecated_tables_found' => 0,
        ];

        // Check prohibited tables in DB
        if ($this->pdo !== null) {
            foreach (self::PROHIBITED_TABLES as $table) {
                try {
                    $exists = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = DATABASE() AND table_name = '{$table}'"
                    )->fetchColumn();

                    if ($exists) {
                        $isDeprecated = str_contains($table, '_deprecated');

                        try {
                            $rows = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                        } catch (Throwable) {
                            $rows = -1;
                        }

                        if ($isDeprecated) {
                            $metrics['deprecated_tables_found']++;
                            $violations[] = "Tabela deprecated ainda existe: {$table} ({$rows} linhas). Remova apos auditoria.";
                        } else {
                            $metrics['prohibited_tables_found']++;
                            $violations[] = "TABELA PROIBIDA encontrada: {$table} ({$rows} linhas). Executa migration 20260517000001.";
                        }
                    }
                } catch (Throwable) {
                    // Se nao conseguiu verificar, ignora silenciosamente
                }
            }
        }

        // Check prohibited files on disk
        $root = $this->rootPath !== '' ? $this->rootPath : $this->guessRoot();
        if ($root !== '') {
            foreach (self::PROHIBITED_FILES as $rel) {
                $full = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (file_exists($full)) {
                    $metrics['prohibited_files_found']++;
                    $violations[] = "Arquivo proibido encontrado: {$rel}. Execute: git rm {$rel}";
                }
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $total = (int) $metrics['prohibited_tables_found'] + (int) $metrics['prohibited_files_found'];
        $deprecated = (int) $metrics['deprecated_tables_found'];

        if ($total > 0) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                "Import manual proibido detectado: {$total} violacao(oes). " .
                'Fonte oficial: API Omie -> canonico -> marts. CSV/planilha proibido.',
                $metrics,
                array_merge(
                    ['Remover artefatos listados. Ver docs/omie-source-of-truth.md.'],
                    $violations
                ),
                $ms,
            );
        }

        if ($deprecated > 0) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                "Tabela(s) deprecated de import manual ainda presente(s): {$deprecated}. Remova apos auditoria.",
                $metrics,
                ['DROP TABLE omie_pivot2_faturamento_staging_deprecated_* apos confirmar que nao ha dados uteis.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'Nenhum artefato de import manual proibido encontrado.',
            $metrics,
            $ms,
        );
    }

    private function guessRoot(): string
    {
        // __FILE__ = app/SystemHealth/Checks/ProhibitedManualImportHealthCheck.php
        $candidate = dirname(__DIR__, 3);
        return is_dir($candidate) ? $candidate : '';
    }
}
