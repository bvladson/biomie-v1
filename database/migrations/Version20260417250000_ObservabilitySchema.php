<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417250000';
    }

    public function getDescription(): string
    {
        return 'Fase 11: biomie_health_alerts — alertas automáticos de saúde do pipeline (AlertEngine).';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260417250000_ObservabilitySchema.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260417250000_ObservabilitySchema.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_health_alerts']);
    }
};
