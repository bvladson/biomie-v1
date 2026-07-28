<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260620040000';
    }

    public function getDescription(): string
    {
        return 'Isola por empresa a chave unica da fila de enriquecimento de clientes.';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_clientes_para_enriquecer')) {
            return;
        }

        if ($this->indexExists($pdo, 'biomie_clientes_para_enriquecer', 'uq_queue_cliente')) {
            $pdo->exec(
                'ALTER TABLE biomie_clientes_para_enriquecer
                 DROP INDEX uq_queue_cliente'
            );
        }
        if (!$this->indexExists($pdo, 'biomie_clientes_para_enriquecer', 'uq_company_queue_cliente')) {
            $pdo->exec(
                'ALTER TABLE biomie_clientes_para_enriquecer
                 ADD UNIQUE KEY uq_company_queue_cliente (company_id, codigo_cliente_omie)'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_clientes_para_enriquecer')) {
            return;
        }

        if ($this->indexExists($pdo, 'biomie_clientes_para_enriquecer', 'uq_company_queue_cliente')) {
            $pdo->exec(
                'ALTER TABLE biomie_clientes_para_enriquecer
                 DROP INDEX uq_company_queue_cliente'
            );
        }
        if (!$this->indexExists($pdo, 'biomie_clientes_para_enriquecer', 'uq_queue_cliente')) {
            $pdo->exec(
                'ALTER TABLE biomie_clientes_para_enriquecer
                 ADD UNIQUE KEY uq_queue_cliente (codigo_cliente_omie)'
            );
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
