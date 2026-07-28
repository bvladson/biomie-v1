<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050500';
    }

    public function getDescription(): string
    {
        return 'Runs e artefatos de reconciliacao gerencial versionados.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050500_ReconciliationTables.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050500_ReconciliationTables.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_reconciliacoes_diferencas',
            'biomie_reconciliacoes_vendedores',
            'biomie_reconciliacoes_runs',
        ]);
    }
};
