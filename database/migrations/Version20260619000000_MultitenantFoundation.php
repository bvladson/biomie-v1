<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Fase 0 — Fundação Multi-Empresa (BIomie Grupo)
 *
 * O que esta migration faz:
 *  1. Cria biomie_companies  — cadastro central de empresas + credenciais Omie
 *  2. Cria biomie_user_companies — mapeamento usuário ↔ empresa + permissões por canal
 *  3. Insere as 5 empresas do Grupo MAG (company_id 1-5)
 *  4. Liga todos os usuários existentes à MAG Filial (company_id = 1)
 *  5. Adiciona coluna company_id TINYINT DEFAULT 1 em todas as tabelas canônicas,
 *     marts e operacionais (dados existentes ficam como company_id=1 — sem quebra)
 *  6. Substitui UNIQUE keys simples por UNIQUE keys compostas (company_id + campo)
 *     para que múltiplas empresas possam ter os mesmos códigos Omie sem conflito
 *  7. Altera PRIMARY KEYs compostas em tabelas cuja PK era uma coluna de negócio
 *
 * Após esta migration o BIomie continua 100% funcional para a MAG Filial.
 * Nenhuma API ou tela precisa ser alterada ainda — company_id=1 é o DEFAULT.
 */
return new class extends AbstractMigration {

    public function getVersion(): string
    {
        return '20260619000000';
    }

    public function getDescription(): string
    {
        return 'Fase 0 — Fundação multi-empresa: biomie_companies, biomie_user_companies e company_id em todas as tabelas canônicas/marts do Grupo MAG.';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function colExists(PDO $pdo, string $table, string $col): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function idxExists(PDO $pdo, string $table, string $idxName): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $idxName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> column names in the PRIMARY KEY */
    private function getPkCols(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = "PRIMARY"
             ORDER BY SEQ_IN_INDEX'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function addCompanyId(PDO $pdo, string $table): void
    {
        if (!$this->colExists($pdo, $table, 'company_id')) {
            $pdo->exec("ALTER TABLE `{$table}`
                        ADD COLUMN company_id TINYINT UNSIGNED NOT NULL DEFAULT 1");
        }
    }

    /**
     * Troca um UNIQUE simples por um UNIQUE composto que inclui company_id.
     * Idempotente: verifica existência antes de dropar/criar.
     */
    private function swapUniq(PDO $pdo, string $tbl, string $oldIdx, string $newIdx, string $newColsSql): void
    {
        if ($this->idxExists($pdo, $tbl, $newIdx)) {
            return; // já migrado
        }
        if ($this->idxExists($pdo, $tbl, $oldIdx)) {
            $pdo->exec("ALTER TABLE `{$tbl}` DROP INDEX `{$oldIdx}`");
        }
        $pdo->exec("ALTER TABLE `{$tbl}` ADD UNIQUE KEY `{$newIdx}` ({$newColsSql})");
    }

    /** Igual a swapUniq mas para índices não-únicos (filtros de leitura). */
    private function addIdx(PDO $pdo, string $tbl, string $idxName, string $colsSql): void
    {
        if (!$this->idxExists($pdo, $tbl, $idxName)) {
            $pdo->exec("ALTER TABLE `{$tbl}` ADD KEY `{$idxName}` ({$colsSql})");
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // UP
    // ──────────────────────────────────────────────────────────────────────

    public function up(PDO $pdo): void
    {
        // ── A. NOVAS TABELAS ─────────────────────────────────────────────
        $this->createBiomieCompanies($pdo);
        $this->createBiomieUserCompanies($pdo);

        // ── B. DADOS INICIAIS ────────────────────────────────────────────
        $this->insertCompanies($pdo);
        $this->linkExistingUsersToMagFilial($pdo);

        // ── C. ADICIONAR company_id ─────────────────────────────────────
        $this->addCompanyIdToCanonical($pdo);
        $this->addCompanyIdToMarts($pdo);
        $this->addCompanyIdToOperational($pdo);
        $this->addCompanyIdToFiscal($pdo);

        // ── D. MIGRAR UNIQUE KEYS ───────────────────────────────────────
        $this->migrateCanonicalUniqueKeys($pdo);
        $this->migrateMartUniqueKeys($pdo);
        $this->migrateOperationalUniqueKeys($pdo);
        $this->migrateFiscalUniqueKeys($pdo);

        // ── E. MIGRAR PRIMARY KEYS COMPOSTAS ────────────────────────────
        $this->migrateCompositePrimaryKeys($pdo);

        // ── F. ÍNDICES DE FILTRO ─────────────────────────────────────────
        $this->addFilterIndexes($pdo);
    }

    // ──────────────────────────────────────────────────────────────────────
    // A. NOVAS TABELAS
    // ──────────────────────────────────────────────────────────────────────

    private function createBiomieCompanies(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_companies (
  id               TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(32)  NOT NULL COMMENT 'Ex: MAG_FILIAL, EXPERT, UNI_DESIGN',
  cnpj             VARCHAR(18)  NOT NULL DEFAULT '' COMMENT 'Preencher após migration',
  razao_social     VARCHAR(120) NOT NULL,
  nome_fantasia    VARCHAR(80)  NOT NULL,
  grupo            VARCHAR(32)  NOT NULL DEFAULT 'GRUPO_MAG',
  omie_app_key     VARCHAR(64)  NOT NULL DEFAULT '',
  omie_app_secret  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'TODO: criptografar — pendência futura',
  omie_webhook_token VARCHAR(64) NOT NULL DEFAULT '',
  canal_padrao     ENUM('varejo','licitacao') NOT NULL DEFAULT 'licitacao',
  meta_mensal      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  ativo            TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cadastro central de empresas do Grupo MAG — fonte de verdade para credenciais Omie'
SQL);
    }

    private function createBiomieUserCompanies(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_user_companies (
  user_id       INT UNSIGNED     NOT NULL,
  company_id    TINYINT UNSIGNED NOT NULL,
  can_varejo    TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Acesso ao canal Varejo',
  can_licitacao TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Acesso ao canal Licitação',
  can_admin     TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Admin dessa empresa',
  is_default    TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Empresa aberta no login',
  PRIMARY KEY (user_id, company_id),
  KEY idx_uc_user_default (user_id, is_default),
  KEY idx_uc_company (company_id),
  CONSTRAINT fk_uc_user    FOREIGN KEY (user_id)    REFERENCES biomie_users(id)     ON DELETE CASCADE,
  CONSTRAINT fk_uc_company FOREIGN KEY (company_id) REFERENCES biomie_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mapeamento usuário ↔ empresa com permissões por canal comercial'
SQL);
    }

    // ──────────────────────────────────────────────────────────────────────
    // B. DADOS INICIAIS
    // ──────────────────────────────────────────────────────────────────────

    private function insertCompanies(PDO $pdo): void
    {
        // INSERT IGNORE garante idempotência — re-run não duplica
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO biomie_companies
  (id, slug, razao_social, nome_fantasia, grupo, omie_app_key, omie_app_secret, canal_padrao, meta_mensal, ativo)
VALUES
  -- id=1: empresa atual; credenciais já em produção via .env.local.php
  (1, 'MAG_FILIAL',  'MAG Filial',  'MAG Filial',  'GRUPO_MAG',
      '', '', 'varejo',    350000.00, 1),

  -- id=2: Expert — foco licitação
  (2, 'EXPERT',      'Expert',      'Expert',      'GRUPO_MAG',
      '', '', 'licitacao',      0.00, 1),

  -- id=3: Uni Design — foco licitação
  (3, 'UNI_DESIGN',  'Uni Design',  'Uni Design',  'GRUPO_MAG',
      '', '', 'licitacao',      0.00, 1),

  -- id=4: MAG Matriz — foco licitação
  (4, 'MAG_MATRIZ',  'MAG Matriz',  'MAG Matriz',  'GRUPO_MAG',
      '', '', 'licitacao',      0.00, 1),

  -- id=5: ErgoOffice — credenciais pendentes; ativo=0 até configurar
  (5, 'ERGO',        'ErgoOffice',  'ErgoOffice',  'GRUPO_MAG',
      '',              '',                           'licitacao',      0.00, 0)
SQL);
    }

    private function linkExistingUsersToMagFilial(PDO $pdo): void
    {
        // Liga todos os usuários existentes à MAG Filial com acesso varejo por padrão
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO biomie_user_companies
  (user_id, company_id, can_varejo, can_licitacao, can_admin, is_default)
SELECT id, 1, 1, 0, 0, 1
FROM biomie_users
SQL);
    }

    // ──────────────────────────────────────────────────────────────────────
    // C. ADICIONAR company_id
    // ──────────────────────────────────────────────────────────────────────

    private function addCompanyIdToCanonical(PDO $pdo): void
    {
        $tables = [
            'biomie_omie_bronze',
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
            'biomie_titulos_financeiros_canonicos',
            'biomie_vendedores_omie',
            'biomie_clientes_dim',
            'biomie_clientes_geo_dim',
            'biomie_documentos_xml_canonicos',
            'biomie_bridge_nf_titulo',
            'biomie_bridge_nf_titulo_items',
            'biomie_eventos_canonicos',
        ];
        foreach ($tables as $t) {
            $this->addCompanyId($pdo, $t);
        }

        // biomie_bridge_nf_titulo_summary tem PRIMARY KEY na coluna de negócio (nota_key)
        // A coluna company_id deve vir BEFORE nota_key para poder compor a nova PK
        if (!$this->colExists($pdo, 'biomie_bridge_nf_titulo_summary', 'company_id')) {
            $pdo->exec("ALTER TABLE `biomie_bridge_nf_titulo_summary`
                        ADD COLUMN company_id TINYINT UNSIGNED NOT NULL DEFAULT 1 FIRST");
        }
    }

    private function addCompanyIdToMarts(PDO $pdo): void
    {
        $tables = [
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
            'mart_oportunidades',
            'mart_forecasts',
            'biomie_geo_vendas_diaria',
            'biomie_vendas_geo_cliente_fact',
            'biomie_estoque_locais_dim',
            'biomie_produtos_estoque_saldos',
            'biomie_produtos_estoque_movimentos',
        ];
        foreach ($tables as $t) {
            $this->addCompanyId($pdo, $t);
        }

        // mart_fiscal_monthly_settlement tem PRIMARY KEY na coluna competencia
        if (!$this->colExists($pdo, 'mart_fiscal_monthly_settlement', 'company_id')) {
            $pdo->exec("ALTER TABLE `mart_fiscal_monthly_settlement`
                        ADD COLUMN company_id TINYINT UNSIGNED NOT NULL DEFAULT 1 FIRST");
        }
    }

    private function addCompanyIdToOperational(PDO $pdo): void
    {
        $tables = [
            'biomie_sync_state',
            'biomie_sync_cursors',
            'biomie_sync_runs',
            'biomie_competencia_snapshots',
            'biomie_competencia_sync_catalog',
            'biomie_clientes_para_enriquecer',
            'biomie_cliente_cnae_audit',
            'biomie_reconciliacoes_runs',
            'biomie_reconciliacoes_diferencas',
            'biomie_reconciliacoes_vendedores',
            'biomie_sales_targets_monthly',
            'biomie_sales_targets_audit',
            'biomie_vendor_scope_rules',
            'biomie_operation_scope_rules',
            'biomie_data_exclusion_rules',
            'biomie_divergencias',
            'biomie_webhook_inbox',
        ];
        foreach ($tables as $t) {
            $this->addCompanyId($pdo, $t);
        }
    }

    private function addCompanyIdToFiscal(PDO $pdo): void
    {
        $tables = [
            'fiscal_document_tax_snapshot',
            'fiscal_purchase_entries',
            'fiscal_purchase_entry_items',
            'fiscal_source_registry',
            // fiscal_establishments já tem source_company_id como discriminador próprio
        ];
        foreach ($tables as $t) {
            $this->addCompanyId($pdo, $t);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // D. MIGRAR UNIQUE KEYS
    // ──────────────────────────────────────────────────────────────────────

    private function migrateCanonicalUniqueKeys(PDO $pdo): void
    {
        // biomie_pedidos_canonicos
        $this->swapUniq($pdo, 'biomie_pedidos_canonicos',
            'uq_pedido_omie', 'uq_company_pedido',
            'company_id, pedido_id_omie');

        // biomie_notas_fiscais_canonicas
        $this->swapUniq($pdo, 'biomie_notas_fiscais_canonicas',
            'uq_nota_key', 'uq_company_nota',
            'company_id, nota_key');

        // biomie_notas_fiscais_itens_canonicos
        $this->swapUniq($pdo, 'biomie_notas_fiscais_itens_canonicos',
            'uq_invoice_item', 'uq_company_invoice_item',
            'company_id, nota_key, sequencia_item');

        // biomie_titulos_financeiros_canonicos
        $this->swapUniq($pdo, 'biomie_titulos_financeiros_canonicos',
            'uq_titulo_key', 'uq_company_titulo',
            'company_id, titulo_key');

        // biomie_vendedores_omie
        $this->swapUniq($pdo, 'biomie_vendedores_omie',
            'uq_codigo_omie', 'uq_company_vendedor',
            'company_id, codigo_omie');

        // biomie_clientes_dim
        $this->swapUniq($pdo, 'biomie_clientes_dim',
            'uq_codigo_cliente_omie', 'uq_company_cliente',
            'company_id, codigo_cliente_omie');

        // biomie_clientes_geo_dim
        $this->swapUniq($pdo, 'biomie_clientes_geo_dim',
            'uq_geo_cliente_omie', 'uq_company_geo_cliente',
            'company_id, codigo_cliente_omie');

        // biomie_documentos_xml_canonicos
        $this->swapUniq($pdo, 'biomie_documentos_xml_canonicos',
            'uq_xml_key', 'uq_company_xml',
            'company_id, xml_key');

        // biomie_bridge_nf_titulo
        $this->swapUniq($pdo, 'biomie_bridge_nf_titulo',
            'uk_nota_key', 'uk_company_nota_titulo',
            'company_id, nota_key');

        // biomie_bridge_nf_titulo_items
        $this->swapUniq($pdo, 'biomie_bridge_nf_titulo_items',
            'uk_nota_titulo', 'uk_company_nota_titulo_item',
            'company_id, nota_key, titulo_key');
    }

    private function migrateMartUniqueKeys(PDO $pdo): void
    {
        $this->swapUniq($pdo, 'mart_comercial_pedido',
            'uq_mart_pedido', 'uq_company_mart_pedido',
            'company_id, pedido_id_omie');

        $this->swapUniq($pdo, 'mart_fiscal_nfe',
            'uq_mart_nfe', 'uq_company_mart_nfe',
            'company_id, nota_key');

        $this->swapUniq($pdo, 'mart_financeiro_titulo',
            'uq_mart_titulo', 'uq_company_mart_titulo',
            'company_id, titulo_key');

        $this->swapUniq($pdo, 'mart_bridge_pedido_nfe_titulo',
            'uq_bridge_pedido', 'uq_company_bridge_pedido',
            'company_id, pedido_id_omie');

        $this->swapUniq($pdo, 'mart_cliente_periodo',
            'uq_cliente_periodo', 'uq_company_cliente_periodo',
            'company_id, competencia, codigo_cliente_omie');

        $this->swapUniq($pdo, 'mart_produto_periodo',
            'uq_produto_periodo', 'uq_company_produto_periodo',
            'company_id, competencia, codigo_produto');

        $this->swapUniq($pdo, 'mart_vendedor_dia',
            'uq_vendedor_dia', 'uq_company_vendedor_dia',
            'company_id, competencia, dia, codigo_vendedor_omie');

        $this->swapUniq($pdo, 'mart_compradores_por_geo',
            'uq_vendedor_cliente_geo_comp', 'uq_company_compradores_geo',
            'company_id, competencia, codigo_vendedor_omie, codigo_cliente_omie, uf, cidade_norm, bairro_norm');

        $this->swapUniq($pdo, 'mart_vendas_por_cnae_cnpj',
            'uq_cnae_cliente_competencia', 'uq_company_cnae_cliente',
            'company_id, competencia, codigo_cliente_omie, documento_normalizado');

        $this->swapUniq($pdo, 'mart_fiscal_items',
            'uk_nota_item', 'uk_company_nota_item',
            'company_id, nota_key, sequencia_item');

        $this->swapUniq($pdo, 'mart_oportunidades',
            'uq_oportunidade', 'uq_company_oportunidade',
            'company_id, tipo_entidade, codigo_entidade, tipo_oportunidade, competencia_referencia');

        $this->swapUniq($pdo, 'mart_forecasts',
            'uq_forecast', 'uq_company_forecast',
            'company_id, tipo_forecast, codigo_entidade, competencia_referencia');

        $this->swapUniq($pdo, 'biomie_geo_vendas_diaria',
            'uq_geo_vendas_diaria', 'uq_company_geo_vendas',
            'company_id, escopo_geo, data_referencia, uf, cidade_norm, bairro_norm, segmento_macro, tipo_documento, is_recorrente');

        $this->swapUniq($pdo, 'biomie_estoque_locais_dim',
            'uq_local', 'uq_company_local',
            'company_id, codigo_local');

        $this->swapUniq($pdo, 'biomie_produtos_estoque_saldos',
            'uq_produto_local', 'uq_company_produto_local',
            'company_id, codigo_produto, codigo_local');
    }

    private function migrateOperationalUniqueKeys(PDO $pdo): void
    {
        $this->swapUniq($pdo, 'biomie_sync_state',
            'uq_biomie_sync_state_period', 'uq_company_sync_period',
            'company_id, sync_name, period_start, period_end');

        $this->swapUniq($pdo, 'biomie_sync_cursors',
            'uq_sync_cursor', 'uq_company_cursor',
            'company_id, domain_name, endpoint_group');

        $this->swapUniq($pdo, 'biomie_competencia_snapshots',
            'uq_biomie_competencia_snapshot', 'uq_company_competencia_snap',
            'company_id, competencia');

        $this->swapUniq($pdo, 'biomie_competencia_sync_catalog',
            'uq_biomie_competencia_catalog', 'uq_company_competencia_cat',
            'company_id, competencia');

        $this->swapUniq($pdo, 'biomie_vendor_scope_rules',
            'uq_vendor_scope_code', 'uq_company_vendor_scope',
            'company_id, codigo_vendedor_omie');

        $this->swapUniq($pdo, 'biomie_operation_scope_rules',
            'uq_scope_rule_pattern', 'uq_company_scope_pattern',
            'company_id, normalized_pattern, source_type');

        $this->swapUniq($pdo, 'biomie_data_exclusion_rules',
            'uq_data_exclusion_identifier', 'uq_company_excl_id',
            'company_id, identifier_type, identifier_value');
    }

    private function migrateFiscalUniqueKeys(PDO $pdo): void
    {
        $this->swapUniq($pdo, 'fiscal_document_tax_snapshot',
            'uq_fiscal_snapshot_nota', 'uq_company_fiscal_nota',
            'company_id, nota_key');

        $this->swapUniq($pdo, 'fiscal_purchase_entries',
            'uq_fiscal_purchase_omie', 'uq_company_purchase_omie',
            'company_id, omie_entry_id');

        $this->swapUniq($pdo, 'fiscal_source_registry',
            'uq_fiscal_source_code', 'uq_company_source_code',
            'company_id, source_code');
    }

    // ──────────────────────────────────────────────────────────────────────
    // E. MIGRAR PRIMARY KEYS COMPOSTAS
    // ──────────────────────────────────────────────────────────────────────

    private function migrateCompositePrimaryKeys(PDO $pdo): void
    {
        // biomie_bridge_nf_titulo_summary  → PK era (nota_key); passa a (company_id, nota_key)
        $pk = $this->getPkCols($pdo, 'biomie_bridge_nf_titulo_summary');
        if ($pk === ['nota_key']) {
            $pdo->exec('ALTER TABLE `biomie_bridge_nf_titulo_summary`
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (company_id, nota_key)');
        }

        // mart_fiscal_monthly_settlement → PK era (competencia); passa a (company_id, competencia)
        $pk = $this->getPkCols($pdo, 'mart_fiscal_monthly_settlement');
        if ($pk === ['competencia']) {
            $pdo->exec('ALTER TABLE `mart_fiscal_monthly_settlement`
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (company_id, competencia)');
        }

        // fiscal_purchase_entries → PK era (entry_key); passa a (company_id, entry_key)
        $pk = $this->getPkCols($pdo, 'fiscal_purchase_entries');
        if ($pk === ['entry_key']) {
            $pdo->exec('ALTER TABLE `fiscal_purchase_entries`
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (company_id, entry_key)');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // F. ÍNDICES DE FILTRO (lookup por company_id)
    // ──────────────────────────────────────────────────────────────────────

    private function addFilterIndexes(PDO $pdo): void
    {
        $simpleIdx = [
            // canonical
            'biomie_omie_bronze'                    => 'idx_bronze_company',
            'biomie_eventos_canonicos'              => 'idx_eventos_company',
            'biomie_sync_runs'                      => 'idx_sync_runs_company',
            'biomie_reconciliacoes_runs'            => 'idx_reconc_runs_company',
            'biomie_reconciliacoes_diferencas'      => 'idx_reconc_dif_company',
            'biomie_reconciliacoes_vendedores'      => 'idx_reconc_vend_company',
            'biomie_sales_targets_monthly'          => 'idx_targets_company',
            'biomie_sales_targets_audit'            => 'idx_targets_audit_company',
            'biomie_clientes_para_enriquecer'       => 'idx_clientes_enrich_company',
            'biomie_cliente_cnae_audit'             => 'idx_cliente_cnae_company',
            'biomie_webhook_inbox'                  => 'idx_webhook_company',
            'biomie_divergencias'                   => 'idx_divergencias_company',
            'biomie_vendas_geo_cliente_fact'        => 'idx_vendas_geo_company',
            'biomie_produtos_estoque_movimentos'    => 'idx_estoque_mov_company',
            'fiscal_purchase_entry_items'           => 'idx_fiscal_items_company',
            // marts
            'biomie_geo_vendas_diaria'              => 'idx_geo_vendas_company',
        ];

        foreach ($simpleIdx as $tbl => $idx) {
            $this->addIdx($pdo, $tbl, $idx, 'company_id');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // DOWN (parcial — ALTERs não são revertidos automaticamente)
    // ──────────────────────────────────────────────────────────────────────

    public function down(PDO $pdo): void
    {
        // Remove as novas tabelas; as colunas/índices adicionados precisam de
        // rollback manual se necessário (cenário de emergência)
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->dropTables($pdo, ['biomie_user_companies', 'biomie_companies']);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
};
