<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260506210000';
    }

    public function getDescription(): string
    {
        return 'Fase 3: matriz RBAC granular para paginas, APIs, jobs, logs e integracoes.';
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
            __DIR__ . '/sql/Version20260506210000_RbacPhase3Permissions.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260506210000_RbacPhase3Permissions.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->executeSql($pdo, <<<'SQL'
DELETE FROM biomie_permissions
WHERE permission_key IN (
  'sales.view', 'sales.sync', 'sales.export',
  'commercial.view',
  'financial.view',
  'finance.sync', 'finance.export',
  'fiscal.view', 'fiscal.sync', 'fiscal.export',
  'reconciliation.view',
  'omie.view',
  'jobs.view', 'jobs.run',
  'logs.view',
  'admin.view', 'admin.manage',
  'settings.view', 'settings.manage',
  'documents.full', 'export'
)
SQL);
    }
};

