<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050300';
    }

    public function getDescription(): string
    {
        return 'Metas mensais e trilha de auditoria do Plano de Voo.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        $this->ensureMonthly($pdo);
        $this->ensureAudit($pdo);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_sales_targets_audit',
            'biomie_sales_targets_monthly',
        ]);
    }

    // -------------------------------------------------------------------------

    private function ensureMonthly(\PDO $pdo): void
    {
        $t = 'biomie_sales_targets_monthly';

        if (!$this->tableExists($pdo, $t)) {
            $pdo->exec(
                "CREATE TABLE biomie_sales_targets_monthly ("
                . "id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,"
                . "vendor_id     VARCHAR(32)      NOT NULL,"
                . "vendor_name   VARCHAR(120)     NULL,"
                . "year_month    CHAR(7)          NOT NULL,"
                . "target_amount DECIMAL(15,2)    NOT NULL,"
                . "currency      CHAR(3)          NOT NULL DEFAULT 'BRL',"
                . "source        ENUM('manual','import','api') NOT NULL DEFAULT 'manual',"
                . "notes         VARCHAR(255)     NULL,"
                . "created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                . "created_by    VARCHAR(64)      NULL,"
                . "updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,"
                . "updated_by    VARCHAR(64)      NULL,"
                . "PRIMARY KEY (id),"
                . "UNIQUE KEY uq_vendor_month (vendor_id, year_month),"
                . "KEY idx_month (year_month),"
                . "KEY idx_vendor (vendor_id)"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return;
        }

        // Table already exists — add only what is missing, one statement at a time.
        if (!$this->columnExists($pdo, $t, 'vendor_name')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN vendor_name VARCHAR(120) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'currency')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'BRL'");
        }
        if (!$this->columnExists($pdo, $t, 'source')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN source ENUM('manual','import','api') NOT NULL DEFAULT 'manual'");
        }
        if (!$this->columnExists($pdo, $t, 'notes')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN notes VARCHAR(255) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'created_by')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN created_by VARCHAR(64) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'updated_at')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!$this->columnExists($pdo, $t, 'updated_by')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD COLUMN updated_by VARCHAR(64) NULL");
        }

        if (!$this->indexExists($pdo, $t, 'uq_vendor_month')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD UNIQUE KEY uq_vendor_month (vendor_id, year_month)");
        }
        if (!$this->indexExists($pdo, $t, 'idx_month')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD INDEX idx_month (year_month)");
        }
        if (!$this->indexExists($pdo, $t, 'idx_vendor')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_monthly ADD INDEX idx_vendor (vendor_id)");
        }
    }

    private function ensureAudit(\PDO $pdo): void
    {
        $t = 'biomie_sales_targets_audit';

        if (!$this->tableExists($pdo, $t)) {
            $pdo->exec(
                "CREATE TABLE biomie_sales_targets_audit ("
                . "id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
                . "vendor_id         VARCHAR(32)     NOT NULL,"
                . "year_month        CHAR(7)         NOT NULL,"
                . "old_target_amount DECIMAL(15,2)   NULL,"
                . "new_target_amount DECIMAL(15,2)   NOT NULL,"
                . "changed_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                . "changed_by        VARCHAR(64)     NULL,"
                . "change_reason     VARCHAR(255)    NULL,"
                . "PRIMARY KEY (id),"
                . "KEY idx_vendor_month (vendor_id, year_month),"
                . "KEY idx_changed_at (changed_at)"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return;
        }

        if (!$this->columnExists($pdo, $t, 'old_target_amount')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_audit ADD COLUMN old_target_amount DECIMAL(15,2) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'changed_by')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_audit ADD COLUMN changed_by VARCHAR(64) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'change_reason')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_audit ADD COLUMN change_reason VARCHAR(255) NULL");
        }

        if (!$this->indexExists($pdo, $t, 'idx_vendor_month')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_audit ADD INDEX idx_vendor_month (vendor_id, year_month)");
        }
        if (!$this->indexExists($pdo, $t, 'idx_changed_at')) {
            $pdo->exec("ALTER TABLE biomie_sales_targets_audit ADD INDEX idx_changed_at (changed_at)");
        }
    }

    // ── information_schema helpers ──────────────────────────────────────────

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
