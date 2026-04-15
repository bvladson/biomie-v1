<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050300';
    }

    public function getDescription(): string
    {
        return 'Metas mensais e trilha de auditoria do Plano de Voo.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050300_FlightPlanTargets.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050300_FlightPlanTargets.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_sales_targets_audit',
            'biomie_sales_targets_monthly',
        ]);
    }
};
