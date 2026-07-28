<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Prioridade nas notificações.
 *
 * Pedidos, clientes e faturamento (NF) são o núcleo comercial e precisam saltar
 * aos olhos; contas a pagar / estoque são ruído operacional. A prioridade é
 * definida na ESCRITA (OmieEventClassifier), então a listagem não paga nada.
 *
 * Índice (company_id, priority, created_at) serve a ordenação do painel:
 * alta prioridade primeiro, depois por recência.
 */
return new class extends AbstractMigration {

    public function getVersion(): string
    {
        return '20260713100000';
    }

    public function getDescription(): string
    {
        return 'Notificações: colunas priority (high/normal/low) e icon + índice para destacar o núcleo comercial.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'biomie_notifications', 'priority')) {
            $pdo->exec(
                "ALTER TABLE biomie_notifications
                 ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal' AFTER severity"
            );
        }

        // Ícone escolhido na classificação — evita o frontend adivinhar por
        // palavra-chave no título.
        if (!$this->columnExists($pdo, 'biomie_notifications', 'icon')) {
            $pdo->exec(
                'ALTER TABLE biomie_notifications
                 ADD COLUMN icon VARCHAR(40) NULL DEFAULT NULL AFTER priority'
            );
        }

        if (!$this->indexExists($pdo, 'biomie_notifications', 'idx_notif_priority')) {
            $pdo->exec(
                'ALTER TABLE biomie_notifications
                 ADD INDEX idx_notif_priority (company_id, priority, created_at)'
            );
        }
    }

    public function down(PDO $pdo): void
    {
        if ($this->indexExists($pdo, 'biomie_notifications', 'idx_notif_priority')) {
            $pdo->exec('ALTER TABLE biomie_notifications DROP INDEX idx_notif_priority');
        }
        foreach (['icon', 'priority'] as $column) {
            if ($this->columnExists($pdo, 'biomie_notifications', $column)) {
                $pdo->exec('ALTER TABLE biomie_notifications DROP COLUMN ' . $column);
            }
        }
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

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
        );
        $stmt->execute(['t' => $table, 'i' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
