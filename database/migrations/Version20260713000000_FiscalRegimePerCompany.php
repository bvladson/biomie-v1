<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Regime tributário POR EMPRESA na Inteligência Fiscal.
 *
 * Antes: fiscal_establishments tinha uma única linha placeholder
 * (source_company_id='primary', sem CNPJ) que nunca casava com os IDs reais das
 * empresas — o contexto fiscal vinha vazio e o painel assumia Lucro Real para
 * todo mundo.
 *
 * Agora: um estabelecimento por empresa, com CNPJ/razão vindos de
 * biomie_companies e o regime correto do grupo:
 *
 *   Lucro Real ............. MAG_FILIAL, MAG_MATRIZ
 *   Lucro Presumido ........ ERGOOFFICE
 *   Simples Nacional (EPP) . EXPERT, UNI_DESIGN
 *
 * Idempotente: usa ON DUPLICATE KEY / UPDATE por source_company_id.
 */
return new class extends AbstractMigration {

    /** slug => regime (espelha BIomie\Modules\Fiscal\Domain\TaxRegime::bySlug()) */
    private const REGIMES = [
        'MAG_FILIAL' => 'lucro_real',
        'MAG_MATRIZ' => 'lucro_real',
        'ERGOOFFICE' => 'lucro_presumido',
        'EXPERT'     => 'simples_nacional',
        'UNI_DESIGN' => 'simples_nacional',
    ];

    public function getVersion(): string
    {
        return '20260713000000';
    }

    public function getDescription(): string
    {
        return 'Inteligência Fiscal: um fiscal_establishments por empresa com o regime tributário correto (Real / Presumido / Simples-EPP).';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'fiscal_establishments')) {
            return;
        }

        // Remove o placeholder que nunca casou com empresa nenhuma.
        $pdo->exec("DELETE FROM fiscal_establishments WHERE source_company_id = 'primary'");

        $companies = $pdo->query(
            'SELECT id, slug, cnpj, razao_social FROM biomie_companies ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $select = $pdo->prepare(
            'SELECT id FROM fiscal_establishments WHERE source_company_id = :cid LIMIT 1'
        );
        $insert = $pdo->prepare(
            "INSERT INTO fiscal_establishments
                (source_system, source_company_id, cnpj, razao_social, uf, municipio,
                 codigo_ibge, regime_tributario, operation_profile, is_primary)
             VALUES
                ('omie', :cid, :cnpj, :razao, 'CE', 'Fortaleza',
                 '2304400', :regime, 'comercio_mercadorias', 1)"
        );
        $update = $pdo->prepare(
            'UPDATE fiscal_establishments
                SET cnpj = :cnpj, razao_social = :razao, regime_tributario = :regime,
                    operation_profile = :perfil, is_primary = 1
              WHERE source_company_id = :cid'
        );

        foreach ($companies as $company) {
            $slug   = strtoupper(trim((string) ($company['slug'] ?? '')));
            $regime = self::REGIMES[$slug] ?? 'lucro_real';
            $cid    = (string) ($company['id'] ?? '');
            if ($cid === '') {
                continue;
            }

            $params = [
                'cid'    => $cid,
                'cnpj'   => (string) ($company['cnpj'] ?? ''),
                'razao'  => (string) ($company['razao_social'] ?? $slug),
                'regime' => $regime,
            ];

            $select->execute(['cid' => $cid]);
            if ($select->fetchColumn() !== false) {
                $update->execute($params + ['perfil' => 'comercio_mercadorias']);
            } else {
                $insert->execute($params);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'fiscal_establishments')) {
            return;
        }

        // Volta ao placeholder anterior (um único estabelecimento genérico).
        $pdo->exec('DELETE FROM fiscal_establishments');
        $pdo->exec(
            "INSERT INTO fiscal_establishments
                (source_system, source_company_id, uf, municipio, codigo_ibge,
                 regime_tributario, operation_profile, is_primary)
             VALUES ('omie', 'primary', 'CE', 'Fortaleza', '2304400',
                     'lucro_real', 'comercio_mercadorias', 1)"
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
