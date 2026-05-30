<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * COM.R3 — Fase 4: Movimentos de estoque por produto × local.
 *
 * Greenfield: Omie tem endpoint ConsultarMovimentoEstoque.
 * Histórico de entradas e saídas com referência a NF/pedido quando disponível.
 * Inicialmente vazia; populada por sync COM.R4+.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260530000003';
    }

    public function getDescription(): string
    {
        return 'COM.R3 Fase 4: biomie_produtos_estoque_movimentos — histórico de movimentos de estoque (greenfield).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_produtos_estoque_movimentos')) {
            $pdo->exec("
                CREATE TABLE biomie_produtos_estoque_movimentos (
                    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    codigo_produto      VARCHAR(60)     NOT NULL,
                    codigo_local        VARCHAR(60)     NOT NULL DEFAULT '',
                    data_movimento      DATE            NOT NULL,
                    tipo_movimento      VARCHAR(30)     NOT NULL DEFAULT '',
                    quantidade          DECIMAL(15,4)   NOT NULL DEFAULT 0.0000,
                    valor               DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
                    documento           VARCHAR(60)     NOT NULL DEFAULT '',
                    nota_key            VARCHAR(100)    NULL,
                    pedido_id_omie      BIGINT UNSIGNED NULL,
                    cliente_fornecedor  VARCHAR(255)    NOT NULL DEFAULT '',
                    raw_source          VARCHAR(50)     NOT NULL DEFAULT 'omie',
                    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_produto_data (codigo_produto, data_movimento),
                    KEY idx_local_data   (codigo_local, data_movimento),
                    KEY idx_nota_key     (nota_key),
                    KEY idx_pedido       (pedido_id_omie)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Movimentos de estoque (entradas/saídas). Sync via ConsultarMovimentoEstoque. Vazia até COM.R4+.'
            ");
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_produtos_estoque_movimentos']);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
