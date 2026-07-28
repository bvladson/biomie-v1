<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * COM.R3 — Fase 4: Saldos de estoque por produto × local.
 *
 * Greenfield: Omie tem endpoint ConsultarEstoque / ResumoEstoque.
 * Uma linha por (codigo_produto, codigo_local). Inicialmente vazia.
 * Product 360 usa esta tabela; se vazia → empty state "Estoque não sincronizado".
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260530000002';
    }

    public function getDescription(): string
    {
        return 'COM.R3 Fase 4: biomie_produtos_estoque_saldos — saldos de estoque por produto e local (greenfield).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_produtos_estoque_saldos')) {
            $pdo->exec("
                CREATE TABLE biomie_produtos_estoque_saldos (
                    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                    codigo_produto    VARCHAR(60)      NOT NULL,
                    codigo_local      VARCHAR(60)      NOT NULL DEFAULT '',
                    descricao_produto VARCHAR(255)     NOT NULL DEFAULT '',
                    nome_local        VARCHAR(255)     NOT NULL DEFAULT '',
                    estoque_atual     DECIMAL(15,4)    NOT NULL DEFAULT 0.0000,
                    estoque_minimo    DECIMAL(15,4)    NOT NULL DEFAULT 0.0000,
                    estoque_inicial   DECIMAL(15,4)    NOT NULL DEFAULT 0.0000,
                    unidade           VARCHAR(20)      NOT NULL DEFAULT '',
                    custo_medio       DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
                    raw_source        VARCHAR(50)      NOT NULL DEFAULT 'omie',
                    atualizado_em     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    created_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_produto_local (codigo_produto, codigo_local),
                    KEY idx_produto (codigo_produto),
                    KEY idx_estoque_minimo (codigo_produto, estoque_atual, estoque_minimo),
                    KEY idx_local (codigo_local)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Saldos de estoque por produto e local. Sync via ConsultarEstoque/ResumoEstoque. Vazia até COM.R4+.'
            ");
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_produtos_estoque_saldos']);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
