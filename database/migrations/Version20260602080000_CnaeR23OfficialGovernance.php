<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260602080000';
    }

    public function getDescription(): string
    {
        return 'CNAE.R2.3 - governanca oficial CNAE, auditoria e cobertura de origem por cliente.';
    }

    public function getConnectionName(): string
    {
        return 'canonical';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_cnae_dim')) {
            $this->executeSql($pdo, "
                CREATE TABLE biomie_cnae_dim (
                  cnae_codigo VARCHAR(20) NOT NULL,
                  cnae_codigo_normalizado VARCHAR(20) NULL,
                  cnae_descricao VARCHAR(255) NULL,
                  origem VARCHAR(40) NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (cnae_codigo),
                  KEY idx_cnae_norm (cnae_codigo_normalizado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $this->addColumns($pdo, 'biomie_cnae_dim', [
            'descricao_oficial'    => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `descricao_oficial` VARCHAR(255) NULL AFTER `cnae_descricao`",
            'secao'                => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `secao` VARCHAR(4) NULL AFTER `descricao_oficial`",
            'secao_descricao'      => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `secao_descricao` VARCHAR(255) NULL AFTER `secao`",
            'divisao'              => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `divisao` VARCHAR(4) NULL AFTER `secao_descricao`",
            'divisao_descricao'    => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `divisao_descricao` VARCHAR(255) NULL AFTER `divisao`",
            'grupo'                => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `grupo` VARCHAR(8) NULL AFTER `divisao_descricao`",
            'grupo_descricao'      => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `grupo_descricao` VARCHAR(255) NULL AFTER `grupo`",
            'classe'               => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `classe` VARCHAR(12) NULL AFTER `grupo_descricao`",
            'classe_descricao'     => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `classe_descricao` VARCHAR(255) NULL AFTER `classe`",
            'subclasse'            => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `subclasse` VARCHAR(16) NULL AFTER `classe_descricao`",
            'subclasse_descricao'  => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `subclasse_descricao` VARCHAR(255) NULL AFTER `subclasse`",
            'versao'               => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `versao` VARCHAR(40) NULL AFTER `subclasse_descricao`",
            'fonte'                => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `fonte` VARCHAR(120) NULL AFTER `versao`",
            'hash_fonte'           => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `hash_fonte` CHAR(64) NULL AFTER `fonte`",
            'valid_from'           => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `valid_from` DATE NULL AFTER `hash_fonte`",
            'valid_to'             => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `valid_to` DATE NULL AFTER `valid_from`",
            'is_active'            => "ALTER TABLE `biomie_cnae_dim` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `valid_to`",
        ]);

        if ($this->tableExists($pdo, 'biomie_clientes_dim')) {
            $this->addColumns($pdo, 'biomie_clientes_dim', [
                'cnae_source'        => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cnae_source` VARCHAR(40) NULL AFTER `cnae_descricao`",
                'cnae_confidence'    => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cnae_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `cnae_source`",
                'cnae_captured_at'   => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cnae_captured_at` DATETIME NULL AFTER `cnae_confidence`",
                'cnae_conflict_flag' => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cnae_conflict_flag` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cnae_captured_at`",
                'cnae_unknown_reason'=> "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cnae_unknown_reason` VARCHAR(120) NULL AFTER `cnae_conflict_flag`",
            ]);
        }

        $this->executeSql($pdo, "
            CREATE TABLE IF NOT EXISTS biomie_cnae_sync_runs (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              source_type VARCHAR(20) NOT NULL,
              source_uri VARCHAR(500) NULL,
              source_hash CHAR(64) NULL,
              dry_run TINYINT(1) NOT NULL DEFAULT 1,
              status VARCHAR(20) NOT NULL DEFAULT 'started',
              rows_read INT UNSIGNED NOT NULL DEFAULT 0,
              rows_valid INT UNSIGNED NOT NULL DEFAULT 0,
              rows_invalid INT UNSIGNED NOT NULL DEFAULT 0,
              rows_inserted INT UNSIGNED NOT NULL DEFAULT 0,
              rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
              warnings_json JSON NULL,
              started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              finished_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_cnae_sync_status (status, started_at),
              KEY idx_cnae_sync_hash (source_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->executeSql($pdo, "
            CREATE TABLE IF NOT EXISTS biomie_cliente_cnae_audit (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              codigo_cliente_omie BIGINT NULL,
              documento_normalizado VARCHAR(20) NULL,
              old_cnae VARCHAR(20) NULL,
              new_cnae VARCHAR(20) NULL,
              source VARCHAR(40) NULL,
              confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
              reason VARCHAR(160) NULL,
              payload_hash CHAR(64) NULL,
              sync_run_id BIGINT UNSIGNED NULL,
              actor VARCHAR(80) NULL,
              changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_cliente_cnae_codigo (codigo_cliente_omie, changed_at),
              KEY idx_cliente_cnae_doc (documento_normalizado, changed_at),
              KEY idx_cliente_cnae_new (new_cnae)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addIndexes($pdo, 'biomie_cnae_dim', [
            'idx_cnae_secao'   => 'ALTER TABLE `biomie_cnae_dim` ADD INDEX `idx_cnae_secao` (`secao`)',
            'idx_cnae_divisao' => 'ALTER TABLE `biomie_cnae_dim` ADD INDEX `idx_cnae_divisao` (`divisao`)',
            'idx_cnae_hash'    => 'ALTER TABLE `biomie_cnae_dim` ADD INDEX `idx_cnae_hash` (`hash_fonte`)',
            'idx_cnae_active'  => 'ALTER TABLE `biomie_cnae_dim` ADD INDEX `idx_cnae_active` (`is_active`, `versao`)',
        ]);

        if ($this->tableExists($pdo, 'biomie_clientes_dim')) {
            $this->addIndexes($pdo, 'biomie_clientes_dim', [
                'idx_clientes_cnae_source' => 'ALTER TABLE `biomie_clientes_dim` ADD INDEX `idx_clientes_cnae_source` (`cnae_source`, `cnae_confidence`)',
            ]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_cliente_cnae_audit',
            'biomie_cnae_sync_runs',
        ]);

        // Columns in existing dimensional tables are intentionally preserved to avoid
        // losing governance history during rollback. Remove manually after backup if needed.
    }

    /**
     * @param array<string,string> $columns
     */
    private function addColumns(\PDO $pdo, string $table, array $columns): void
    {
        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($pdo, $table, $column)) {
                $pdo->exec($sql);
            }
        }
    }

    /**
     * @param array<string,string> $indexes
     */
    private function addIndexes(\PDO $pdo, string $table, array $indexes): void
    {
        foreach ($indexes as $index => $sql) {
            if (!$this->indexExists($pdo, $table, $index)) {
                $pdo->exec($sql);
            }
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
