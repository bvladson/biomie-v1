<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * COM.R3 — Fase 4: Dimensão de locais de estoque.
 *
 * Greenfield: Omie tem endpoints ConsultarLocaisEstoque / ListarLocaisEstoque.
 * Tabela de dimensão para locais físicos (filiais, depósitos, prateleiras).
 * Inicialmente vazia; populada por sync COM.R4+.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260530000001';
    }

    public function getDescription(): string
    {
        return 'COM.R3 Fase 4: biomie_estoque_locais_dim — dimensão de locais de estoque Omie (greenfield).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_estoque_locais_dim')) {
            $pdo->exec("
                CREATE TABLE biomie_estoque_locais_dim (
                    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    codigo_local  VARCHAR(60)     NOT NULL,
                    nome_local    VARCHAR(255)    NOT NULL DEFAULT '',
                    ativo         TINYINT(1)      NOT NULL DEFAULT 1,
                    permite_venda TINYINT(1)      NOT NULL DEFAULT 1,
                    raw_source    VARCHAR(50)     NOT NULL DEFAULT 'omie',
                    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_local (codigo_local),
                    KEY idx_local_ativo (ativo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Locais de estoque Omie (filiais, depósitos). Sincronizado via ConsultarLocaisEstoque.'
            ");
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_estoque_locais_dim']);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
