<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260618233000';
    }

    public function getDescription(): string
    {
        return 'Read model indexado para acoes de cobranca financeira com backfill do log administrativo.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_finance_collection_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo_key VARCHAR(160) NOT NULL,
  cliente_codigo_omie VARCHAR(64) NULL,
  vendedor_codigo_omie VARCHAR(64) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action_type VARCHAR(50) NOT NULL,
  note TEXT NULL,
  next_action_date DATE NULL,
  source_admin_log_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fin_collection_source_log (source_admin_log_id),
  KEY idx_fin_collection_title_created (titulo_key, created_at),
  KEY idx_fin_collection_client_created (cliente_codigo_omie, created_at),
  KEY idx_fin_collection_vendor_created (vendedor_codigo_omie, created_at),
  KEY idx_fin_collection_actor_created (actor_user_id, created_at),
  KEY idx_fin_collection_action_created (action_type, created_at),
  KEY idx_fin_collection_next_action (next_action_date, action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO biomie_finance_collection_actions (
  titulo_key, cliente_codigo_omie, vendedor_codigo_omie, actor_user_id,
  action_type, note, next_action_date, source_admin_log_id, created_at
)
SELECT
  JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.titulo_key')),
  NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.cliente_codigo_omie')), ''),
  NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.vendedor_codigo_omie')), ''),
  l.actor_user_id,
  JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.action_type')),
  NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.note')), ''),
  STR_TO_DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.next_action_date')), ''), '%Y-%m-%d'),
  l.id,
  l.created_at
FROM biomie_admin_logs l
WHERE l.event_type = 'finance.collection_action'
  AND l.details_json IS NOT NULL
  AND JSON_VALID(l.details_json) = 1
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.titulo_key')), '') <> ''
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.details_json, '$.action_type')), '') <> ''
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_finance_collection_actions']);
    }
};
