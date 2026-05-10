<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417150000';
    }

    public function getDescription(): string
    {
        return 'Fase 6: colunas de governança (tipo_pessoa, documento_normalizado, segmento_cnae) em biomie_clientes_dim e mart_cliente_periodo.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260417150000_GovernanceEnrichment.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260417150000_GovernanceEnrichment.sql');
    }

    public function down(\PDO $pdo): void
    {
        // Remove colunas adicionadas; não destrói dados existentes
        $pdo->exec('ALTER TABLE biomie_clientes_dim
            DROP COLUMN IF EXISTS tipo_pessoa,
            DROP COLUMN IF EXISTS documento_normalizado');

        $pdo->exec('ALTER TABLE mart_cliente_periodo
            DROP COLUMN IF EXISTS tipo_pessoa,
            DROP COLUMN IF EXISTS segmento_cnae');
    }
};
