<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260621020000';
    }

    public function getDescription(): string
    {
        return 'Phase 4: Deduplicacao cross-empresa de clientes por CNPJ (biomie_clientes_grupo).';
    }

    public function up(\PDO $pdo): void
    {
        // ── 1. Grupo canônico por CNPJ ────────────────────────────────────────
        // Um CNPJ pode aparecer como cliente em várias empresas do grupo.
        // Esta tabela consolida a identidade canônica.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_clientes_grupo (
                cnpj               CHAR(14) NOT NULL,
                nome_canonical     VARCHAR(255) NOT NULL DEFAULT '',
                primary_company_id TINYINT UNSIGNED NOT NULL,
                total_companies    TINYINT UNSIGNED NOT NULL DEFAULT 1,
                total_pedidos      INT UNSIGNED NOT NULL DEFAULT 0,
                total_nfs          INT UNSIGNED NOT NULL DEFAULT 0,
                total_receita      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                first_seen_at      DATE NULL,
                last_seen_at       DATE NULL,
                built_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (cnpj),
                INDEX idx_primary_company (primary_company_id),
                INDEX idx_total_companies (total_companies),
                INDEX idx_receita (total_receita DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── 2. Membros do grupo (instância por empresa) ───────────────────────
        // Cada linha representa o CNPJ em uma empresa específica,
        // com o código Omie local e estatísticas dessa empresa.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_clientes_grupo_membros (
                id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                cnpj                  CHAR(14) NOT NULL,
                company_id            TINYINT UNSIGNED NOT NULL,
                codigo_cliente_omie   BIGINT UNSIGNED NULL,
                nome_local            VARCHAR(255) NOT NULL DEFAULT '',
                canal_predominante    ENUM('varejo','licitacao','misto') NOT NULL DEFAULT 'varejo',
                total_pedidos         INT UNSIGNED NOT NULL DEFAULT 0,
                total_nfs             INT UNSIGNED NOT NULL DEFAULT 0,
                total_receita         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                first_seen_at         DATE NULL,
                last_seen_at          DATE NULL,
                built_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cnpj_company (cnpj, company_id),
                INDEX idx_company (company_id),
                INDEX idx_codigo_omie (company_id, codigo_cliente_omie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── 3. Histórico de consolidação (audit trail) ────────────────────────
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_clientes_grupo_builds (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                built_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms    INT UNSIGNED NOT NULL DEFAULT 0,
                grupos_total   INT UNSIGNED NOT NULL DEFAULT 0,
                grupos_shared  INT UNSIGNED NOT NULL DEFAULT 0,
                membros_total  INT UNSIGNED NOT NULL DEFAULT 0,
                status         ENUM('ok','error') NOT NULL DEFAULT 'ok',
                message        VARCHAR(500) NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_clientes_grupo_builds',
            'biomie_clientes_grupo_membros',
            'biomie_clientes_grupo',
        ]);
    }
};
