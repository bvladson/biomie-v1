<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Phase 2A — hardening da fundacao multiempresa.
 *
 * - normaliza CNPJ e cria unicidade quando informado;
 * - separa credenciais/integracoes do cadastro da empresa;
 * - materializa o canal nas principais tabelas comerciais;
 * - adiciona indices compostos para joins tenant-safe.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260620010000';
    }

    public function getDescription(): string
    {
        return 'Phase 2A — CNPJ unico, integracoes por empresa, canal persistido e indices tenant-safe.';
    }

    public function up(PDO $pdo): void
    {
        $this->normalizeCompanies($pdo);
        $this->createCompanyIntegrations($pdo);
        $this->seedIntegrationMetadata($pdo);
        $this->addBusinessChannel($pdo);
        $this->addTenantJoinIndexes($pdo);
    }

    private function normalizeCompanies(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE biomie_companies MODIFY cnpj VARCHAR(14) NULL DEFAULT NULL");

        $stmt = $pdo->prepare(
            'UPDATE biomie_companies
                SET cnpj = :cnpj,
                    razao_social = :razao,
                    nome_fantasia = :fantasia
              WHERE id = :id'
        );
        $companies = [
            [1, '26537584000394', 'Magazine dos Moveis LTDA', 'Mag Moveis - Filial'],
            [2, '40914338000173', 'Expert Servicos, Comercio de Moveis e Licitacoes LTDA', 'Expert Moveis e Licitacoes'],
            [4, '26537584000122', 'Magazine dos Moveis LTDA', 'Mag Moveis - Matriz'],
            [5, '49410635000156', 'Ergo Office Comercio de Moveis LTDA', 'Ergo Office'],
        ];
        foreach ($companies as [$id, $cnpj, $razao, $fantasia]) {
            $stmt->execute([
                'id' => $id,
                'cnpj' => $cnpj,
                'razao' => $razao,
                'fantasia' => $fantasia,
            ]);
        }
        $pdo->exec("UPDATE biomie_companies SET cnpj = NULL WHERE cnpj = ''");

        if (!$this->indexExists($pdo, 'biomie_companies', 'uq_company_cnpj')) {
            $pdo->exec('ALTER TABLE biomie_companies ADD UNIQUE KEY uq_company_cnpj (cnpj)');
        }
    }

    private function createCompanyIntegrations(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_company_integrations (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id            TINYINT UNSIGNED NOT NULL,
  provider              VARCHAR(32) NOT NULL DEFAULT 'omie',
  app_key               VARCHAR(128) NOT NULL,
  app_secret_encrypted  TEXT NULL,
  webhook_token_hash    CHAR(64) NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  secret_rotated_at     DATETIME NULL,
  last_used_at          DATETIME NULL,
  last_success_at       DATETIME NULL,
  last_error_at         DATETIME NULL,
  last_error_summary    VARCHAR(255) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_provider (company_id, provider),
  UNIQUE KEY uq_provider_webhook_hash (provider, webhook_token_hash),
  KEY idx_integration_active (provider, active, company_id),
  CONSTRAINT fk_integration_company
    FOREIGN KEY (company_id) REFERENCES biomie_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function seedIntegrationMetadata(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
INSERT INTO biomie_company_integrations (company_id, provider, app_key, active)
SELECT id, 'omie', omie_app_key,
       IF(ativo = 1 AND omie_app_key <> '', 1, 0)
  FROM biomie_companies
 WHERE omie_app_key <> ''
ON DUPLICATE KEY UPDATE
  app_key = VALUES(app_key),
  active = VALUES(active)
SQL);
    }

    private function addBusinessChannel(PDO $pdo): void
    {
        foreach ([
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
            'biomie_titulos_financeiros_canonicos',
            'biomie_vendedores_omie',
            'mart_comercial_pedido',
            'mart_fiscal_nfe',
            'mart_financeiro_titulo',
            'mart_bridge_pedido_nfe_titulo',
            'mart_cliente_periodo',
            'mart_produto_periodo',
            'mart_vendedor_dia',
            'mart_compradores_por_geo',
            'mart_vendas_por_cnae_cnpj',
            'mart_fiscal_items',
        ] as $table) {
            if (!$this->columnExists($pdo, $table, 'business_channel')) {
                $pdo->exec(
                    "ALTER TABLE `{$table}`
                     ADD COLUMN business_channel ENUM('varejo','licitacao','outros')
                     NOT NULL DEFAULT 'varejo'"
                );
            }
        }

        $pdo->exec(
            "UPDATE biomie_pedidos_canonicos
                SET business_channel = CASE
                  WHEN business_unit_scope = 'licitacao'
                    OR UPPER(COALESCE(nome_vendedor, '')) LIKE '%LICITAC%'
                  THEN 'licitacao' ELSE 'varejo' END"
        );
        $pdo->exec(
            "UPDATE biomie_notas_fiscais_canonicas
                SET business_channel = CASE
                  WHEN business_unit_scope = 'licitacao'
                    OR UPPER(COALESCE(nome_vendedor, '')) LIKE '%LICITAC%'
                  THEN 'licitacao' ELSE 'varejo' END"
        );
        $pdo->exec(
            "UPDATE biomie_titulos_financeiros_canonicos
                SET business_channel = CASE
                  WHEN UPPER(COALESCE(nome_vendedor, '')) LIKE '%LICITAC%'
                  THEN 'licitacao' ELSE 'varejo' END"
        );
        $pdo->exec(
            "UPDATE biomie_vendedores_omie
                SET business_channel = CASE
                  WHEN business_unit_scope = 'licitacao'
                    OR UPPER(COALESCE(nome, '')) LIKE '%LICITAC%'
                  THEN 'licitacao' ELSE 'varejo' END"
        );
        $pdo->exec(
            "UPDATE biomie_notas_fiscais_itens_canonicos i
               JOIN biomie_notas_fiscais_canonicas n
                 ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                SET i.business_channel = n.business_channel"
        );
    }

    private function addTenantJoinIndexes(PDO $pdo): void
    {
        $indexes = [
            ['biomie_pedidos_canonicos', 'idx_company_order_code', 'company_id, codigo_pedido'],
            ['biomie_notas_fiscais_canonicas', 'idx_company_nf_order', 'company_id, pedido_id_omie'],
            ['biomie_notas_fiscais_canonicas', 'idx_company_nf_client', 'company_id, codigo_cliente_omie'],
            ['biomie_notas_fiscais_itens_canonicos', 'idx_company_item_note', 'company_id, nota_key'],
            ['biomie_titulos_financeiros_canonicos', 'idx_company_title_note', 'company_id, nota_key'],
            ['biomie_titulos_financeiros_canonicos', 'idx_company_title_order', 'company_id, pedido_id_omie'],
            ['biomie_clientes_dim', 'idx_company_client_code', 'company_id, codigo_cliente_omie'],
            ['biomie_clientes_geo_dim', 'idx_company_geo_client', 'company_id, codigo_cliente_omie'],
            ['biomie_vendedores_omie', 'idx_company_vendor_code', 'company_id, codigo_omie'],
        ];
        foreach ($indexes as [$table, $name, $columns]) {
            if (!$this->indexExists($pdo, $table, $name)) {
                $pdo->exec("ALTER TABLE `{$table}` ADD KEY `{$name}` ({$columns})");
            }
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_company_integrations']);
    }
};
