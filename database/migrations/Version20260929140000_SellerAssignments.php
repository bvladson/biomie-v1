<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Vendedor atribuído no BIomie a NF/pedido que chegou do Omie SEM vendedor.
 *
 * Muda só o BI: o Omie não é tocado. A escrita canônica reaplica a atribuição a
 * cada sync enquanto o Omie continuar sem vendedor; se o Omie passar a ter
 * vendedor, o Omie vence e a atribuição vira 'superada'. O vendedor original
 * (o que o Omie mandou) fica guardado para auditoria e para desfazer.
 *
 * down(): devolve às linhas canônicas o vendedor original das atribuições
 * ativas e remove a tabela.
 */
return new class extends AbstractMigration {
    private const TABLE = 'biomie_seller_assignments';

    public function getVersion(): string
    {
        return '20260929140000';
    }

    public function getDescription(): string
    {
        return 'Atribuição de vendedor no BIomie para NF/pedido sem vendedor no Omie (Omie intocado).';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                entity_type ENUM('nf', 'pedido') NOT NULL,
                entity_key VARCHAR(80) NOT NULL COMMENT 'nota_key da NF ou pedido_id_omie do pedido',
                documento VARCHAR(40) NULL COMMENT 'numero_nf / codigo_pedido, para exibição',
                codigo_vendedor_omie VARCHAR(32) NOT NULL,
                nome_vendedor VARCHAR(120) NOT NULL,
                vendedor_original_codigo VARCHAR(32) NULL,
                vendedor_original_nome VARCHAR(120) NULL,
                motivo VARCHAR(255) NULL,
                status ENUM('ativa', 'superada', 'removida') NOT NULL DEFAULT 'ativa',
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                closed_reason VARCHAR(255) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_seller_assignment (company_id, entity_type, entity_key),
                KEY idx_seller_assignment_status (company_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
        );
        if ((int) $exists->fetchColumn() === 0) {
            return;
        }

        // Sem a tabela, o próximo sync gravaria de novo "Sem vendedor"; aqui já
        // devolvemos o original para o BI não ficar com vendedor sem trilha.
        $pdo->exec(
            "UPDATE biomie_notas_fiscais_canonicas n
               JOIN " . self::TABLE . " a
                 ON a.company_id = n.company_id AND a.entity_type = 'nf'
                AND a.entity_key = n.nota_key AND a.status = 'ativa'
                SET n.codigo_vendedor_omie = COALESCE(a.vendedor_original_codigo, ''),
                    n.nome_vendedor = COALESCE(a.vendedor_original_nome, 'Sem vendedor'),
                    n.channel_classified_at = NULL"
        );
        $pdo->exec(
            "UPDATE biomie_pedidos_canonicos p
               JOIN " . self::TABLE . " a
                 ON a.company_id = p.company_id AND a.entity_type = 'pedido'
                AND a.entity_key = CAST(p.pedido_id_omie AS CHAR)
                AND a.status = 'ativa'
                SET p.codigo_vendedor_omie = COALESCE(a.vendedor_original_codigo, ''),
                    p.nome_vendedor = COALESCE(a.vendedor_original_nome, 'Sem vendedor'),
                    p.channel_classified_at = NULL"
        );
        $pdo->exec('DROP TABLE ' . self::TABLE);
    }
};
