<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417170000';
    }

    public function getDescription(): string
    {
        return 'Fase 7: mart_oportunidades — inteligência comercial (clientes dormentes, queda de compra, concentração, geo, carteira de vendedores).';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260417170000_OpportunityMart.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260417170000_OpportunityMart.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['mart_oportunidades']);
    }
};
