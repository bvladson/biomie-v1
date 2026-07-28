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
            $delStmt = $this->pdo->prepare(
                'DELETE FROM mart_produto_periodo
                  WHERE company_id = :company_id AND competencia = :competencia'
            );
            $delStmt->execute(['company_id' => $this->companyId(), 'competencia' => $competencia]);
            $deleted = $delStmt->rowCount();

            $scopeFilter      = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', 'n');
            $visibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', 'n');
            $insertSql = <<<SQL
                INSERT INTO mart_produto_periodo (
                    company_id, business_channel, competencia, codigo_produto, descricao_produto,
                    nfes_count, quantidade, valor_total, valor_desconto,
                    refreshed_at
                )
                SELECT
                    n.company_id,
                    n.business_channel,
                    :competencia,
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU'),
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao')),
                    COUNT(DISTINCT i.nota_key),
                    SUM(i.quantidade),
                    SUM(i.valor_total),
                    SUM(i.valor_desconto),
                    NOW()
                FROM biomie_notas_fiscais_itens_canonicos i
                JOIN biomie_notas_fiscais_canonicas n
                  ON n.company_id = i.company_id
                 AND n.nota_key = i.nota_key
                 AND n.status_fiscal = 'autorizada'
                WHERE n.company_id = :company_id
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end{$scopeFilter}{$visibilityFilter}
                GROUP BY n.company_id, n.business_channel,
                         COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
            SQL;

            $inserted = $this->exec(
                $insertSql,
                ['company_id' => $this->companyId(), 'competencia' => $competencia, 'start' => $start, 'end' => $end],
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
