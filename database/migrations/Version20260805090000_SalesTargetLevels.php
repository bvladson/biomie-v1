<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/** Normaliza os níveis antes guardados somente em tiers_json. */
return new class extends AbstractMigration {
    public function getVersion(): string { return '20260805090000'; }

    public function getDescription(): string
    {
        return 'Escada de metas auditável por nível, mantendo tiers_json como compatibilidade.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sales_target_levels (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                vendor_id VARCHAR(64) NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                level_number TINYINT UNSIGNED NOT NULL,
                level_name VARCHAR(80) NOT NULL,
                target_amount DECIMAL(15,2) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by VARCHAR(120) NULL,
                updated_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_target_level (company_id, vendor_id, `year_month`, level_number),
                KEY idx_target_level_month (company_id, `year_month`, active),
                CONSTRAINT fk_target_level_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sales_target_level_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                vendor_id VARCHAR(64) NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                level_number TINYINT UNSIGNED NOT NULL,
                old_amount DECIMAL(15,2) NULL,
                new_amount DECIMAL(15,2) NULL,
                changed_by VARCHAR(120) NULL,
                change_reason VARCHAR(255) NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_target_level_audit (company_id, vendor_id, `year_month`, changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Backfill idempotente: Meta 1 + níveis do JSON legado.
        $rows = $pdo->query(
            'SELECT company_id, vendor_id, `year_month`, target_amount, tiers_json, created_by, updated_by
               FROM biomie_sales_targets_monthly WHERE target_amount > 0'
        )->fetchAll(PDO::FETCH_ASSOC);
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO biomie_sales_target_levels
                (company_id, vendor_id, `year_month`, level_number, level_name, target_amount, created_by, updated_by)
             VALUES (:company_id, :vendor_id, :year_month, :level_number, :level_name, :amount, :created_by, :updated_by)'
        );
        foreach ($rows as $row) {
            $levels = [(float) $row['target_amount']];
            $decoded = json_decode((string) ($row['tiers_json'] ?? ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $amount) {
                    if (is_numeric($amount) && (float) $amount > 0) $levels[] = (float) $amount;
                }
            }
            sort($levels, SORT_NUMERIC);
            $levels = array_values(array_unique($levels, SORT_REGULAR));
            foreach ($levels as $index => $amount) {
                $insert->execute([
                    'company_id' => $row['company_id'], 'vendor_id' => $row['vendor_id'],
                    'year_month' => $row['year_month'], 'level_number' => $index + 1,
                    'level_name' => $index === 0 ? 'Compromisso' : ($index === 1 ? 'Superação' : ($index === 2 ? 'Excelência' : 'Nível ' . ($index + 1))),
                    'amount' => $amount, 'created_by' => $row['created_by'], 'updated_by' => $row['updated_by'],
                ]);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_sales_target_level_audit', 'biomie_sales_target_levels']);
    }
};
