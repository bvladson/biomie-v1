<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use Throwable;

final class ProductPeriodMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $competencia, string $start, string $end): int
    {
        if (!$this->tableExists('mart_produto_periodo')) {
            $this->logger->warning('ProductPeriodMartBuilder: tabela mart_produto_periodo nao existe, ignorando.', [
                'competencia' => $competencia,
            ]);
            return 0;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            // Remove all rows for this competência before rebuilding — prevents ghost products
            $delStmt = $this->pdo->prepare('DELETE FROM mart_produto_periodo WHERE competencia = :competencia');
            $delStmt->execute(['competencia' => $competencia]);
            $deleted = $delStmt->rowCount();

            $scopeFilter = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', 'n');
            $insertSql = <<<SQL
                INSERT INTO mart_produto_periodo (
                    competencia, codigo_produto, descricao_produto,
                    nfes_count, quantidade, valor_total, valor_desconto,
                    refreshed_at
                )
                SELECT
                    :competencia,
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU'),
                    COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao'),
                    COUNT(DISTINCT i.nota_key),
                    SUM(i.quantidade),
                    SUM(i.valor_total),
                    SUM(i.valor_desconto),
                    NOW()
                FROM biomie_notas_fiscais_itens_canonicos i
                JOIN biomie_notas_fiscais_canonicas n
                  ON n.nota_key = i.nota_key AND n.status_fiscal = 'autorizada'
                WHERE COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end{$scopeFilter}
                GROUP BY i.codigo_produto, i.descricao_produto
            SQL;

            $inserted = $this->exec(
                $insertSql,
                ['competencia' => $competencia, 'start' => $start, 'end' => $end],
                'materialize_produto_periodo',
                'mart_produto_periodo',
                'insert_mart_produto_periodo'
            );

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            $this->logger->info('ProductPeriodMartBuilder: competencia refrescada.', [
                'competencia' => $competencia,
                'start'       => $start,
                'end'         => $end,
                'deleted'     => $deleted,
                'inserted'    => $inserted,
            ]);

            return $inserted;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error('ProductPeriodMartBuilder: rollback apos erro na competencia.', [
                'competencia' => $competencia,
                'message'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
