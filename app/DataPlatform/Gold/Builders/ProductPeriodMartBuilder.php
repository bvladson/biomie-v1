<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class ProductPeriodMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $competencia, string $start, string $end): int
    {
        $sql = <<<'SQL'
            INSERT INTO mart_produto_periodo (
                competencia, codigo_produto, descricao_produto,
                nfes_count, quantidade, valor_total, valor_desconto,
                refreshed_at
            )
            SELECT
                :competencia,
                COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU'),
                i.descricao_produto,
                COUNT(DISTINCT i.nota_key),
                SUM(i.quantidade),
                SUM(i.valor_total),
                SUM(i.valor_desconto),
                NOW()
            FROM biomie_notas_fiscais_itens_canonicos i
            JOIN biomie_notas_fiscais_canonicas n
              ON n.nota_key = i.nota_key AND n.status_fiscal = 'autorizada'
            WHERE COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
            GROUP BY codigo_produto, descricao_produto
            ON DUPLICATE KEY UPDATE
                nfes_count     = VALUES(nfes_count),
                quantidade     = VALUES(quantidade),
                valor_total    = VALUES(valor_total),
                valor_desconto = VALUES(valor_desconto),
                refreshed_at   = NOW()
        SQL;

        return $this->exec($sql, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_produto_periodo', 'mart_produto_periodo', 'insert_mart_produto_periodo');
    }
}
