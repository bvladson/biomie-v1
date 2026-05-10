<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260418000000';
    }

    public function getDescription(): string
    {
        return 'Fase 12: controle de acesso granular — module em access_logs, biomie_data_export_log, modules_json em users.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260418000000_AccessControl.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260418000000_AccessControl.sql');
    }

    public function down(\PDO $pdo): void
    {
        // biomie_data_export_log é trilha de auditoria — não deve ser removida em down() de produção
        // Apenas remove a coluna module e modules_json (não destrutivo)
        try { $pdo->exec('ALTER TABLE biomie_access_logs DROP COLUMN module'); } catch (\Throwable) {}
        try { $pdo->exec('ALTER TABLE biomie_users DROP COLUMN modules_json'); } catch (\Throwable) {}
    }
};
