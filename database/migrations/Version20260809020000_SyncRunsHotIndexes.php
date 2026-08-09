<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260809020000'; }
    public function getDescription(): string { return 'Indices para as varreduras completas de biomie_sync_runs no status da Omie.'; }

    public function up(PDO $pdo): void
    {
        // MEDIDO, nao suposto. `api/omie_status.php` consulta esta tabela SEIS
        // vezes por request e era o unico endpoint genuinamente lento do
        // sistema: 460 chamadas em 30 dias, media de 5s, apenas 7% delas abaixo
        // de meio segundo (todos os outros endpoints sao 78-91% rapidos).
        //
        // EXPLAIN antes, com 55.191 linhas na tabela:
        //
        //   MAX(finished_at) WHERE status IN (...)      type=ALL      55.191 linhas
        //   SUM(items_processed) WHERE started_at >= X  type=ALL      55.191 linhas
        //   ORDER BY started_at DESC, id DESC LIMIT 1   filesort      55.191 linhas
        //
        // Nenhum dos 7 indices existentes tinha `started_at` como PRIMEIRA
        // coluna, e nenhum cobria `finished_at`.
        //
        // Depois (medido em producao):
        //
        //   244ms -> 56ms   index-only
        //   297ms -> 36ms   1.085 linhas em vez de 55.191
        //   284ms -> 35ms   UMA linha examinada, filesort eliminado
        //
        // `(started_at, id, items_processed)` resolve DOIS casos de uma vez: o
        // `id` na segunda posicao faz o indice casar `ORDER BY started_at DESC,
        // id DESC` (a versao sem ele, `(started_at, items_processed)`, foi
        // testada e o otimizador recusou — o desempate ficava na coluna errada),
        // e `items_processed` no fim mantem a soma coberta pelo indice.
        //
        // ALGORITHM=INPLACE, LOCK=NONE: a tabela recebe escrita a cada janela de
        // sync (677 linhas/dia); DDL bloqueante aqui pararia o pipeline.
        $pdo->exec(
            'ALTER TABLE biomie_sync_runs '
            . 'ADD INDEX IF NOT EXISTS idx_sync_runs_status_finished (status, finished_at), '
            . 'ADD INDEX IF NOT EXISTS idx_sync_runs_started_id_items (started_at, id, items_processed), '
            . 'ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE biomie_sync_runs '
            . 'DROP INDEX IF EXISTS idx_sync_runs_status_finished, '
            . 'DROP INDEX IF EXISTS idx_sync_runs_started_id_items'
        );
    }
};
