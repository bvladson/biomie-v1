<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 7: snapshots de identidade na auditoria.
 *
 * Para afirmar historicamente "Carlos foi cobrado por Vladson" NÃO se pode usar
 * o vendedor ATUAL do canônico: um pedido pode ser transferido depois, e o
 * histórico passaria a mentir sobre quem era o responsável na época.
 *
 * Os IDs continuam sendo a identidade oficial; os nomes em snapshot preservam a
 * leitura histórica mesmo após renome ou exclusão do usuário/vendedor — uma
 * trilha precisa registrar com clareza "quando, quem e o quê" (OWASP Logging).
 *
 *   manager_name_snapshot  — nome do gestor NO MOMENTO da cobrança
 *   seller_id_at_charge    — vendedor responsável NO MOMENTO da cobrança
 *   seller_name_at_charge  — nome desse vendedor NO MOMENTO da cobrança
 *
 * Momento ideal: a tabela de auditoria ainda está VAZIA (0 linhas), então não há
 * backfill nem histórico ambíguo.
 *
 * Reversível: down() remove as três colunas.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_quote_followup_charge_audit';

    /** @var array<int,array{name:string,ddl:string}> */
    private const COLUMNS = [
        ['name' => 'manager_name_snapshot', 'ddl' => "VARCHAR(160) NULL COMMENT 'nome do gestor no momento da cobranca'"],
        ['name' => 'seller_id_at_charge',   'ddl' => "VARCHAR(32) NULL COMMENT 'vendedor responsavel no momento da cobranca'"],
        ['name' => 'seller_name_at_charge', 'ddl' => "VARCHAR(160) NULL COMMENT 'nome do vendedor no momento da cobranca'"],
    ];

    public function getVersion(): string
    {
        return '20260718120000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 7: snapshots de identidade (gestor e vendedor) na auditoria de cobrança, para trilha histórica fiel.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }
        foreach (self::COLUMNS as $c) {
            if (!$this->columnExists($pdo, self::TABLE, $c['name'])) {
                $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', self::TABLE, $c['name'], $c['ddl']));
            }
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }
        foreach (array_reverse(self::COLUMNS) as $c) {
            if ($this->columnExists($pdo, self::TABLE, $c['name'])) {
                $pdo->exec(sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, $c['name']));
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
