<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050600';
    }

    public function getDescription(): string
    {
        return 'Eventos, metricas e spans operacionais para observabilidade forte.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050600_OperationalObservability.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050600_OperationalObservability.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_operational_spans',
            'biomie_operational_metrics',
            'biomie_operational_events',
        ]);
    }
};
