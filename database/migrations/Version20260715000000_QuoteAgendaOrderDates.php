<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 1: datas canônicas do pedido.
 *
 * Adiciona três colunas nullable em biomie_pedidos_canonicos para separar,
 * de forma confiável, a inclusão real do pedido da previsão de faturamento —
 * hoje ambas colapsadas em `data_emissao` (o mapper grava data_previsao com
 * fallback dInc; ~22% dos orçamentos têm dInc ≠ previsão).
 *
 *   data_inclusao_omie         DATETIME  ← infoCadastro.dInc + hInc
 *   data_alteracao_omie        DATETIME  ← infoCadastro.dAlt + hAlt (staleness)
 *   data_previsao_faturamento  DATE      ← cabecalho.data_previsao
 *
 * REGRA DE OURO (MVP): aditivo. NÃO altera o significado de `data_emissao`,
 * não cria índice, não faz backfill, não preenche defaults artificiais. O mapper
 * passa a preencher estas colunas na Fase 2; consumidores legados seguem
 * intactos. Tabela minúscula (~250 linhas) — InnoDB/Dynamic aceita INSTANT ADD.
 *
 * Reversível: down() remove as três colunas em ordem inversa. Ordem de rollback
 * em produção: (1) desligar flags/jobs, (2) reverter o mapper, (3) rodar down —
 * caso contrário o próximo sync tentaria escrever em colunas removidas.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_pedidos_canonicos';

    /** @var array<int,array{name:string,ddl:string}> ordem de criação */
    private const COLUMNS = [
        ['name' => 'data_inclusao_omie',        'ddl' => 'DATETIME NULL'],
        ['name' => 'data_alteracao_omie',       'ddl' => 'DATETIME NULL'],
        ['name' => 'data_previsao_faturamento', 'ddl' => 'DATE NULL'],
    ];

    public function getVersion(): string
    {
        return '20260715000000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos: colunas data_inclusao_omie, data_alteracao_omie e data_previsao_faturamento em biomie_pedidos_canonicos (aditivo, sem índice, sem backfill).';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (!$this->columnExists($pdo, self::TABLE, $column['name'])) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s %s',
                    self::TABLE,
                    $column['name'],
                    $column['ddl']
                ));
            }
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        foreach (array_reverse(self::COLUMNS) as $column) {
            if ($this->columnExists($pdo, self::TABLE, $column['name'])) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s DROP COLUMN %s',
                    self::TABLE,
                    $column['name']
                ));
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
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND COLUMN_NAME  = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
