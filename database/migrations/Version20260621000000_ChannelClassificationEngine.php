<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260621000000';
    }

    public function getDescription(): string
    {
        return 'Phase 2: Cria engine de classificacao de canal (tabela de regras + campos de confianca).';
    }

    public function up(\PDO $pdo): void
    {
        // ── 1. Tabela de regras ───────────────────────────────────────────────
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_channel_classification_rules (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id     TINYINT UNSIGNED NOT NULL,
                priority       SMALLINT UNSIGNED NOT NULL DEFAULT 500,
                rule_type      ENUM(
                                   'manual',
                                   'vendedor_nome_like',
                                   'cliente_cnpj_list',
                                   'pedido_obs_like',
                                   'default'
                               ) NOT NULL,
                match_value    VARCHAR(255) NOT NULL DEFAULT '',
                target_channel ENUM('varejo','licitacao') NOT NULL,
                confidence     ENUM('manual','alta','media','baixa') NOT NULL DEFAULT 'media',
                ativo          TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rule_identity (company_id, rule_type, match_value, priority),
                INDEX idx_company_priority (company_id, priority, ativo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── 2. Campos de auditoria de confiança em pedidos ───────────────────
        $pdo->exec(<<<'SQL'
            ALTER TABLE biomie_pedidos_canonicos
                ADD COLUMN IF NOT EXISTS channel_confidence
                    ENUM('manual','alta','media','baixa') NULL DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS channel_rule_id
                    INT UNSIGNED NULL DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS channel_classified_at
                    DATETIME NULL DEFAULT NULL
        SQL);

        // ── 3. Mesmos campos em notas fiscais ─────────────────────────────────
        $pdo->exec(<<<'SQL'
            ALTER TABLE biomie_notas_fiscais_canonicas
                ADD COLUMN IF NOT EXISTS channel_confidence
                    ENUM('manual','alta','media','baixa') NULL DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS channel_rule_id
                    INT UNSIGNED NULL DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS channel_classified_at
                    DATETIME NULL DEFAULT NULL
        SQL);

        // ── 4. Seed de regras iniciais ────────────────────────────────────────
        // MAG_FILIAL (1): varejo por padrão, licitação detectada por vendedor/obs
        // Expert (2), Uni Design (3), MAG Matriz (4): 100% licitação por padrão
        $pdo->exec(<<<'SQL'
            INSERT IGNORE INTO biomie_channel_classification_rules
                (company_id, priority, rule_type, match_value, target_channel, confidence)
            VALUES
                -- MAG_FILIAL: detecção por nome do vendedor
                (1,  10, 'vendedor_nome_like', '%LICITAC%',  'licitacao', 'alta'),
                (1,  11, 'vendedor_nome_like', '%LICITAÇ%', 'licitacao', 'alta'),
                (1,  12, 'vendedor_nome_like', '%LICITA%',   'licitacao', 'alta'),
                -- MAG_FILIAL: detecção por observação do pedido
                (1,  20, 'pedido_obs_like', '%pregao%',      'licitacao', 'media'),
                (1,  21, 'pedido_obs_like', '%pregão%',      'licitacao', 'media'),
                (1,  22, 'pedido_obs_like', '%edital%',      'licitacao', 'media'),
                (1,  23, 'pedido_obs_like', '%empenho%',     'licitacao', 'media'),
                (1,  24, 'pedido_obs_like', '%licitac%',     'licitacao', 'media'),
                (1,  25, 'pedido_obs_like', '%licitaç%',    'licitacao', 'media'),
                -- MAG_FILIAL: default = varejo
                (1, 999, 'default', '', 'varejo', 'baixa'),
                -- Expert / Uni Design / MAG Matriz: default = licitação (canal_padrao já é licitacao)
                (2, 999, 'default', '', 'licitacao', 'alta'),
                (3, 999, 'default', '', 'licitacao', 'alta'),
                (4, 999, 'default', '', 'licitacao', 'alta')
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS biomie_channel_classification_rules');
        foreach (['biomie_pedidos_canonicos', 'biomie_notas_fiscais_canonicas'] as $table) {
            $pdo->exec("ALTER TABLE `{$table}`
                DROP COLUMN IF EXISTS channel_classified_at,
                DROP COLUMN IF EXISTS channel_rule_id,
                DROP COLUMN IF EXISTS channel_confidence");
        }
    }
};
