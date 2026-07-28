<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050200';
    }

    public function getDescription(): string
    {
        return 'Snapshots historicos por competencia e catalogo de materializacao.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050200_CompetenciaSnapshots.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050200_CompetenciaSnapshots.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_competencia_sync_catalog',
            'biomie_competencia_snapshots',
        ]);
    }
};
