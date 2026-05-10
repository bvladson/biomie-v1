<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Schema;

use PDO;

/**
 * Gerencia a criação e evolução do schema canônico no banco de dados.
 *
 * Extraído de CanonicalRepository (Fase 5). CanonicalRepository mantém
 * os métodos públicos originais como fachada, delegando para esta classe.
 */
final class CanonicalSchemaManager
{
    private const GEO_ANALYTICS_REQUIRED_TABLES = [
        'biomie_notas_fiscais_canonicas',
        'biomie_clientes_dim',
        'biomie_clientes_geo_dim',
        'biomie_vendas_geo_cliente_fact',
        'biomie_geo_vendas_diaria',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureFiscalSchema(): void
    {
        $this->ensureBaseTablesExist();
        $this->ensureInvoiceTablesExist();
        $this->ensureSeparatedDomainTablesExist();
        $this->ensureEventStoreTableExists();
        $this->ensureStateTableExists();
        $this->ensureReconciliationTablesExist();
    }

    public function isAvailable(): bool
    {
        try {
            return $this->hasTable('biomie_vendedores_omie')
                && $this->hasTable('biomie_pedidos_canonicos')
                && $this->hasTable('biomie_notas_fiscais_canonicas')
                && $this->hasTable('biomie_divergencias');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function missingGeoAnalyticsTables(): array
    {
        $missing = [];
        foreach (self::GEO_ANALYTICS_REQUIRED_TABLES as $table) {
            if (!$this->hasTable($table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
            LIMIT 1
        SQL);
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    public function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        SQL);
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (bool) $stmt->fetchColumn();
    }

    public function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index_name
            LIMIT 1
        SQL);
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (bool) $stmt->fetchColumn();
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }
        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
        ));
    }

    public function addIndexIfMissing(string $table, string $index, string $definition): void
    {
        if ($this->hasIndex($table, $index)) {
            return;
        }
        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` %s',
            $table,
            $index,
            $definition
        ));
    }

    public function ensureBaseTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_vendedores_omie (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo_omie VARCHAR(32) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                email VARCHAR(200) NULL,
                comissao DECIMAL(5,2) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_codigo_omie (codigo_omie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_pedidos_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pedido_id_omie BIGINT NOT NULL,
                codigo_pedido VARCHAR(32) NOT NULL,
                codigo_integracao VARCHAR(64) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                status_comercial ENUM('faturado','autorizado','cancelado','outro') NOT NULL DEFAULT 'outro',
                etapa_omie VARCHAR(32) NULL,
                status_pedido_omie VARCHAR(64) NULL,
                data_emissao DATE NULL,
                data_faturamento DATE NULL,
                data_cancelamento DATE NULL,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
                quantidade_itens INT UNSIGNED NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_pedido_omie (pedido_id_omie),
                KEY idx_vendedor_status (codigo_vendedor_omie, status_comercial),
                KEY idx_status_faturamento (status_comercial, data_faturamento),
                KEY idx_data_emissao (data_emissao),
                KEY idx_data_faturamento (data_faturamento),
                KEY idx_sincronizado (sincronizado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_divergencias (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo VARCHAR(64) NOT NULL,
                rule_code VARCHAR(80) NOT NULL DEFAULT '',
                severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                entity_type VARCHAR(32) NOT NULL DEFAULT 'generic',
                source_domain VARCHAR(32) NOT NULL DEFAULT 'reconciliation',
                source_origin VARCHAR(64) NULL,
                referencia VARCHAR(128) NULL,
                descricao TEXT NULL,
                contexto_json TEXT NULL,
                suggested_action VARCHAR(255) NULL,
                operational_status ENUM('aberta','reincidente','resolvida','ignorada_regra') NOT NULL DEFAULT 'aberta',
                first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_period_start DATE NULL,
                last_seen_period_end DATE NULL,
                occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
                detectado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolvido_em TIMESTAMP NULL,
                resolvido_por VARCHAR(64) NULL,
                resolution_note TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_divergencia_regra_ref (rule_code, referencia),
                KEY idx_divergencia_status (operational_status, severity, last_detected_at),
                KEY idx_divergencia_rule (rule_code),
                KEY idx_divergencia_entity (entity_type, referencia),
                KEY idx_tipo (tipo),
                KEY idx_detectado (detectado_em),
                KEY idx_aberto (resolvido_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->ensureDivergenceTableShape();
    }

    public function ensureDivergenceTableShape(): void
    {
        if (!$this->hasTable('biomie_divergencias')) {
            return;
        }

        $this->addColumnIfMissing('biomie_divergencias', 'rule_code', "VARCHAR(80) NOT NULL DEFAULT '' AFTER `tipo`");
        $this->addColumnIfMissing('biomie_divergencias', 'severity', "ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER `rule_code`");
        $this->addColumnIfMissing('biomie_divergencias', 'entity_type', "VARCHAR(32) NOT NULL DEFAULT 'generic' AFTER `severity`");
        $this->addColumnIfMissing('biomie_divergencias', 'source_domain', "VARCHAR(32) NOT NULL DEFAULT 'reconciliation' AFTER `entity_type`");
        $this->addColumnIfMissing('biomie_divergencias', 'source_origin', "VARCHAR(64) NULL AFTER `source_domain`");
        $this->addColumnIfMissing('biomie_divergencias', 'suggested_action', "VARCHAR(255) NULL AFTER `contexto_json`");
        $this->addColumnIfMissing('biomie_divergencias', 'operational_status', "ENUM('aberta','reincidente','resolvida','ignorada_regra') NOT NULL DEFAULT 'aberta' AFTER `suggested_action`");
        $this->addColumnIfMissing('biomie_divergencias', 'first_detected_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `operational_status`");
        $this->addColumnIfMissing('biomie_divergencias', 'last_detected_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `first_detected_at`");
        $this->addColumnIfMissing('biomie_divergencias', 'last_seen_period_start', "DATE NULL AFTER `last_detected_at`");
        $this->addColumnIfMissing('biomie_divergencias', 'last_seen_period_end', "DATE NULL AFTER `last_seen_period_start`");
        $this->addColumnIfMissing('biomie_divergencias', 'occurrence_count', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `last_seen_period_end`");
        $this->addColumnIfMissing('biomie_divergencias', 'resolution_note', "TEXT NULL AFTER `resolvido_por`");

        $this->pdo->exec(<<<'SQL'
            UPDATE biomie_divergencias
            SET rule_code = CASE
                    WHEN rule_code IS NULL OR rule_code = '' THEN COALESCE(NULLIF(tipo, ''), 'divergencia')
                    ELSE rule_code
                END,
                first_detected_at = COALESCE(detectado_em, first_detected_at, NOW()),
                last_detected_at = COALESCE(detectado_em, last_detected_at, NOW()),
                occurrence_count = CASE
                    WHEN occurrence_count IS NULL OR occurrence_count < 1 THEN 1
                    ELSE occurrence_count
                END,
                operational_status = CASE
                    WHEN resolvido_em IS NOT NULL AND operational_status IN ('aberta', 'reincidente') THEN 'resolvida'
                    ELSE operational_status
                END
        SQL);

        $this->addIndexIfMissing('biomie_divergencias', 'idx_divergencia_status', "(`operational_status`, `severity`, `last_detected_at`)");
        $this->addIndexIfMissing('biomie_divergencias', 'idx_divergencia_rule', "(`rule_code`)");
        $this->addIndexIfMissing('biomie_divergencias', 'idx_divergencia_entity', "(`entity_type`, `referencia`)");
    }

    public function ensureInvoiceTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_notas_fiscais_canonicas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nota_key VARCHAR(80) NOT NULL,
                nota_id_omie BIGINT NULL,
                chave_nfe VARCHAR(64) NULL,
                numero_nf VARCHAR(32) NOT NULL DEFAULT '',
                serie_nf VARCHAR(16) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                status_fiscal ENUM('autorizada','cancelada') NOT NULL DEFAULT 'autorizada',
                data_emissao DATE NULL,
                data_cancelamento DATE NULL,
                data_competencia DATE NULL,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
                quantidade_itens INT UNSIGNED NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_nota_key (nota_key),
                KEY idx_invoice_status_competencia (status_fiscal, data_competencia),
                KEY idx_invoice_vendor_status (codigo_vendedor_omie, status_fiscal, data_competencia),
                KEY idx_invoice_emissao (data_emissao),
                KEY idx_invoice_cancelamento (data_cancelamento),
                KEY idx_invoice_chave (chave_nfe)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_notas_fiscais_itens_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nota_key VARCHAR(80) NOT NULL,
                sequencia_item INT UNSIGNED NOT NULL,
                codigo_produto VARCHAR(60) NULL,
                descricao_produto VARCHAR(255) NOT NULL DEFAULT 'Produto sem descricao',
                quantidade DECIMAL(15,4) NOT NULL DEFAULT 0,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invoice_item (nota_key, sequencia_item),
                KEY idx_invoice_item_product (codigo_produto, descricao_produto),
                KEY idx_invoice_item_note (nota_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function ensureSeparatedDomainTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_documentos_xml_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                xml_key VARCHAR(96) NOT NULL,
                nota_key VARCHAR(80) NULL,
                nota_id_omie BIGINT NULL,
                chave_nfe VARCHAR(64) NULL,
                numero_nf VARCHAR(32) NOT NULL DEFAULT '',
                serie_nf VARCHAR(16) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                documento_tipo VARCHAR(32) NOT NULL DEFAULT 'nfe_xml',
                status_documento ENUM('pendente','disponivel','indisponivel','cancelado') NOT NULL DEFAULT 'pendente',
                data_emissao DATE NULL,
                data_cancelamento DATE NULL,
                xml_payload LONGTEXT NULL,
                metadata_json LONGTEXT NULL,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_xml_key (xml_key),
                KEY idx_xml_chave (chave_nfe),
                KEY idx_xml_nota (nota_key),
                KEY idx_xml_status_emissao (status_documento, data_emissao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_titulos_financeiros_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                titulo_key VARCHAR(96) NOT NULL,
                titulo_id_omie BIGINT NULL,
                codigo_integracao VARCHAR(80) NULL,
                numero_documento VARCHAR(64) NOT NULL DEFAULT '',
                status_titulo VARCHAR(32) NOT NULL DEFAULT 'outro',
                especie_documento VARCHAR(64) NULL,
                forma_pagamento VARCHAR(64) NULL,
                documento_origem_tipo VARCHAR(32) NULL,
                documento_origem_ref VARCHAR(120) NULL,
                nota_key VARCHAR(80) NULL,
                chave_nfe VARCHAR(64) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_conta_corrente_omie VARCHAR(32) NULL,
                data_emissao DATE NULL,
                data_vencimento DATE NULL,
                data_liquidacao DATE NULL,
                data_competencia DATE NULL,
                valor_pago DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_titulo_key (titulo_key),
                KEY idx_titulo_competencia (data_competencia, status_titulo),
                KEY idx_titulo_cliente (codigo_cliente_omie, data_competencia),
                KEY idx_titulo_vendedor (codigo_vendedor_omie, data_competencia),
                KEY idx_titulo_origem_nota (nota_key),
                KEY idx_titulo_origem_pedido (pedido_id_omie),
                KEY idx_titulo_chave_nfe (chave_nfe)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function ensureEventStoreTableExists(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_eventos_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_name VARCHAR(32) NOT NULL,
                omie_entity_id BIGINT NULL,
                entity_code VARCHAR(60) NULL,
                integration_code VARCHAR(80) NULL,
                event_type VARCHAR(64) NOT NULL,
                event_at_omie DATETIME NULL,
                event_order INT UNSIGNED NOT NULL DEFAULT 0,
                stage_code VARCHAR(16) NULL,
                event_user VARCHAR(32) NULL,
                document_key VARCHAR(64) NULL,
                document_number VARCHAR(32) NULL,
                document_series VARCHAR(16) NULL,
                source_payload_hash CHAR(64) NOT NULL,
                idempotency_hash CHAR(64) NOT NULL,
                source_payload_json LONGTEXT NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'cron',
                ingested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_canonical_event_idempotency (idempotency_hash),
                KEY idx_event_entity_time (entity_name, omie_entity_id, event_at_omie),
                KEY idx_event_type_time (event_type, event_at_omie),
                KEY idx_event_code_time (entity_code, event_at_omie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function ensureReconciliationTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_type VARCHAR(32) NOT NULL DEFAULT 'omie_csv',
                source_file VARCHAR(255) NOT NULL DEFAULT '',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                notes_csv INT UNSIGNED NOT NULL DEFAULT 0,
                notes_system INT UNSIGNED NOT NULL DEFAULT 0,
                differences_count INT UNSIGNED NOT NULL DEFAULT 0,
                matches_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_user_id BIGINT NULL,
                created_user_name VARCHAR(120) NULL,
                latest_for_period TINYINT(1) NOT NULL DEFAULT 1,
                report_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_period (period_start, period_end, latest_for_period),
                KEY idx_reconciliation_created (created_at),
                KEY idx_reconciliation_source (source_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_vendedores (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                vendor_key VARCHAR(180) NOT NULL DEFAULT '',
                vendor_name VARCHAR(160) NOT NULL DEFAULT 'Sem vendedor',
                has_difference TINYINT(1) NOT NULL DEFAULT 0,
                payload_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_vendor_run (run_id),
                KEY idx_reconciliation_vendor_name (vendor_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_diferencas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                note_key VARCHAR(120) NOT NULL DEFAULT '',
                note_ref VARCHAR(80) NOT NULL DEFAULT '',
                difference_type VARCHAR(40) NOT NULL DEFAULT 'mismatch',
                payload_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_diff_run (run_id),
                KEY idx_reconciliation_diff_note (note_ref),
                KEY idx_reconciliation_diff_type (difference_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function ensureStateTableExists(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sync_state (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sync_name VARCHAR(64) NOT NULL DEFAULT 'canonical_orders',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                phase ENUM('idle','vendors_synced','orders_running','completed','failed','chunked') NOT NULL DEFAULT 'idle',
                source VARCHAR(32) NOT NULL DEFAULT 'cron',
                checkpoint_date DATE DEFAULT NULL,
                checkpoint_page INT UNSIGNED DEFAULT NULL,
                checkpoint_batch INT UNSIGNED DEFAULT NULL,
                vendors_synced INT UNSIGNED NOT NULL DEFAULT 0,
                orders_inserted INT UNSIGNED NOT NULL DEFAULT 0,
                orders_updated INT UNSIGNED NOT NULL DEFAULT 0,
                orders_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
                orders_skipped INT UNSIGNED NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                next_allowed_at DATETIME DEFAULT NULL,
                last_sync DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_biomie_sync_state_period (sync_name, period_start, period_end),
                KEY idx_biomie_sync_state_phase (phase),
                KEY idx_biomie_sync_state_checkpoint (checkpoint_date, checkpoint_page, checkpoint_batch),
                KEY idx_biomie_sync_state_last_sync (last_sync)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);
    }
}
