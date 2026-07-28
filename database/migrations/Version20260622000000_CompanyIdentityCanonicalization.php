<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260622000000';
    }

    public function getDescription(): string
    {
        return 'Canonicaliza o slug da ErgoOffice e alinha a identidade multiempresa.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE biomie_companies
                SET slug = 'ERGOOFFICE',
                    nome_fantasia = 'Ergo Office',
                    razao_social = CASE
                        WHEN TRIM(COALESCE(razao_social, '')) = '' THEN 'Ergo Office Comercio de Moveis LTDA'
                        ELSE razao_social
                    END,
                    canal_padrao = 'licitacao'
              WHERE id = 5
                AND slug IN ('ERGO', 'ERGO_OFFICE', 'ERGOOFFICE')"
        );
    }

    public function down(\PDO $pdo): void
    {
        // Identidade canônica não deve voltar a um slug ambíguo.
    }
};
