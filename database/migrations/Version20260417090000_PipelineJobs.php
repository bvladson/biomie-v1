<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417090000';
    }

    public function getDescription(): string
    {
        return 'Fase 5: tabela biomie_pipeline_jobs para rastreamento de execucoes dos jobs de materializacao gold.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260417090000_PipelineJobs.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260417090000_PipelineJobs.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_pipeline_jobs']);
    }
};
