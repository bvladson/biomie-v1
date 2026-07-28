<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260517000001';
    }

    public function getDescription(): string
    {
        return 'Remove tabela pivot2 staging (entrada CSV proibida). Se houver dados, emite alerta e nao apaga.';
    }

    public function getType(): string
    {
        return 'destructive';
    }

    public function up(\PDO $pdo): void
    {
        $table = 'omie_pivot2_faturamento_staging';

        $exists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        )->fetchColumn();

        if (!$exists) {
            return;
        }

        $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

        if ($rowCount > 0) {
            // Tabela tem dados — nao apagar automaticamente. Renomear para _deprecated e registrar aviso.
            $deprecated = $table . '_deprecated_' . date('Ymd');
            $pdo->exec("RENAME TABLE `{$table}` TO `{$deprecated}`");

            $msg = sprintf(
                'AVISO: tabela %s tinha %d linhas. Renomeada para %s. '
                . 'Entrada de dados via CSV/planilha e PROIBIDA no BIomie. '
                . 'Fonte oficial: API Omie -> canonical -> marts. Remova a tabela manualmente apos auditoria.',
                $table,
                $rowCount,
                $deprecated
            );

            error_log('[BIomie][migration] ' . $msg);

            if ($this->tableExists($pdo, 'biomie_pipeline_jobs')) {
                $pdo->prepare(
                    "INSERT INTO biomie_pipeline_jobs (job_type, status, payload, created_at)
                     VALUES ('migration_warning', 'warning', ?, NOW())"
                )->execute([json_encode(['migration' => $this->getVersion(), 'message' => $msg])]);
            }

            return;
        }

        $pdo->exec("DROP TABLE `{$table}`");
    }

    public function down(\PDO $pdo): void
    {
        // Nao recriar: esta tabela nunca deveria existir.
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        )->fetchColumn() > 0;
    }
};
