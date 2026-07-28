<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260501090000';
    }

    public function getDescription(): string
    {
        return 'Fase 4: RBAC administrativo, permissoes, logs administrativos e seeds padrao.';
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
            __DIR__ . '/sql/Version20260501090000_AdminRbac.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260501090000_AdminRbac.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_role_permissions',
            'biomie_user_roles',
            'biomie_permissions',
            'biomie_roles',
        ]);
    }
};
