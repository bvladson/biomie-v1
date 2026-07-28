<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050400';
    }

    public function getDescription(): string
    {
        return 'Usuarios, papeis e trilha de acesso do modulo autenticado.';
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
            __DIR__ . '/sql/Version20260413050400_AuthSchema.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050400_AuthSchema.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_access_logs',
            'biomie_users',
        ]);
    }
};
