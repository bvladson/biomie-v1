<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260506223000';
    }

    public function getDescription(): string
    {
        return 'Fase 4: tabela biomie_jobs para fila persistente de jobs operacionais.';
    }

    public function getConnectionName(): string
    {
        return 'primary';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260506223000_BiomieJobs.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260506223000_BiomieJobs.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_jobs']);
    }
};
