<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class CommercialOrderMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        $this->exec(
            'DELETE FROM mart_comercial_pedido
              WHERE company_id = :company_id
                AND COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end',
            ['company_id' => $this->companyId(), 'start' => $start, 'end' => $end],
            'materialize_comercial_pedido',
            'mart_comercial_pedido',
            'delete_mart_comercial_pedido_periodo',
        );

        $scopeFilter = $this->commercialScopeFragment('biomie_pedidos_canonicos', 'p');
        $visibilityFilter = $this->visibilityFragment('biomie_pedidos_canonicos', 'p');
        $sql = <<<SQL
            INSERT INTO mart_comercial_pedido (
                company_id, business_channel, competencia, pedido_id_omie, codigo_pedido,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                status_comercial, data_emissao, data_faturamento,
                valor_total, valor_mercadorias, valor_descontos,
                tem_nfe, tem_titulo, bridge_status, refreshed_at
            )
            SELECT
                p.company_id,
                p.business_channel,
                DATE_FORMAT(COALESCE(p.data_faturamento, p.data_emissao), '%Y-%m'),
                p.pedido_id_omie,
                p.codigo_pedido,
                p.codigo_vendedor_omie,
                p.nome_vendedor,
                p.codigo_cliente_omie,
                p.nome_cliente,
                p.documento_cliente,
                p.status_comercial,
                p.data_emissao,
                p.data_faturamento,
                p.valor_total,
                p.valor_mercadorias,
                p.valor_descontos,
                IF(b.nota_key IS NOT NULL, 1, 0),
                IF(b.titulo_key IS NOT NULL, 1, 0),
                COALESCE(b.bridge_status, 'pedido_sem_nf'),
                NOW()
            FROM biomie_pedidos_canonicos p
            LEFT JOIN mart_bridge_pedido_nfe_titulo b
              ON b.company_id = p.company_id
             AND b.business_channel = p.business_channel
             AND b.pedido_id_omie = p.pedido_id_omie
            WHERE p.company_id = :company_id
              AND COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end{$scopeFilter}{$visibilityFilter}
            ON DUPLICATE KEY UPDATE
                competencia          = VALUES(competencia),
                status_comercial     = VALUES(status_comercial),
                data_emissao         = VALUES(data_emissao),
                data_faturamento     = VALUES(data_faturamento),
                valor_total          = VALUES(valor_total),
                valor_mercadorias    = VALUES(valor_mercadorias),
                valor_descontos      = VALUES(valor_descontos),
                tem_nfe              = VALUES(tem_nfe),
                tem_titulo           = VALUES(tem_titulo),
                bridge_status        = VALUES(bridge_status),
                refreshed_at         = NOW()
        SQL;

        return $this->exec($sql, [
            'company_id' => $this->companyId(),
            'start' => $start,
            'end' => $end,
        ], 'materialize_comercial_pedido', 'mart_comercial_pedido', 'insert_mart_comercial_pedido');
    }
}
