<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class FiscalNfeMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        $sql = <<<'SQL'
            INSERT INTO mart_fiscal_nfe (
                competencia, nota_key, numero_nf,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente,
                status_fiscal, data_emissao, data_competencia,
                valor_total, valor_mercadorias, valor_descontos,
                pedido_id_omie, tem_pedido, tem_titulo, bridge_status, refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(n.data_competencia, n.data_emissao), '%Y-%m'),
                n.nota_key,
                n.numero_nf,
                n.codigo_vendedor_omie,
                n.nome_vendedor,
                n.codigo_cliente_omie,
                n.nome_cliente,
                n.status_fiscal,
                n.data_emissao,
                n.data_competencia,
                n.valor_total,
                n.valor_mercadorias,
                n.valor_descontos,
                b.pedido_id_omie,
                IF(b.pedido_id_omie IS NOT NULL, 1, 0),
                IF(b.titulo_key IS NOT NULL, 1, 0),
                COALESCE(b.bridge_status, 'nf_sem_titulo'),
                NOW()
            FROM biomie_notas_fiscais_canonicas n
            LEFT JOIN mart_bridge_pedido_nfe_titulo b ON b.nota_key = n.nota_key
            WHERE COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
            ON DUPLICATE KEY UPDATE
                competencia       = VALUES(competencia),
                status_fiscal     = VALUES(status_fiscal),
                data_emissao      = VALUES(data_emissao),
                data_competencia  = VALUES(data_competencia),
                valor_total       = VALUES(valor_total),
                valor_mercadorias = VALUES(valor_mercadorias),
                valor_descontos   = VALUES(valor_descontos),
                pedido_id_omie    = VALUES(pedido_id_omie),
                tem_pedido        = VALUES(tem_pedido),
                tem_titulo        = VALUES(tem_titulo),
                bridge_status     = VALUES(bridge_status),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_fiscal_nfe', 'mart_fiscal_nfe', 'insert_mart_fiscal_nfe');
    }
}
