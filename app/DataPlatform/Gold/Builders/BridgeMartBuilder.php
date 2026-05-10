<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class BridgeMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        $sql = <<<'SQL'
            INSERT INTO mart_bridge_pedido_nfe_titulo (
                competencia, pedido_id_omie, codigo_pedido,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                status_comercial, data_emissao, data_faturamento,
                valor_pedido, valor_mercadorias_pedido,
                nota_key, status_fiscal, data_competencia_nfe, valor_nfe,
                titulo_key, status_titulo, data_vencimento, data_liquidacao,
                valor_aberto, valor_recebido,
                bridge_status, refreshed_at
            )
            SELECT
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
                n.nota_key,
                n.status_fiscal,
                n.data_competencia,
                COALESCE(n.valor_total, 0),
                t.titulo_key,
                t.status_titulo,
                t.data_vencimento,
                t.data_liquidacao,
                COALESCE(t.valor_aberto, 0),
                COALESCE(t.valor_liquido, 0),
                CASE
                    WHEN p.status_comercial = 'cancelado'    THEN 'cancelado'
                    WHEN n.nota_key IS NULL                   THEN 'pedido_sem_nf'
                    WHEN n.status_fiscal = 'cancelada'        THEN 'nf_cancelada'
                    WHEN t.titulo_key IS NULL                 THEN 'nf_sem_titulo'
                    ELSE 'completo'
                END,
                NOW()
            FROM biomie_pedidos_canonicos p
            LEFT JOIN (
                SELECT pedido_id_omie, MIN(id) AS min_id
                FROM biomie_notas_fiscais_canonicas
                GROUP BY pedido_id_omie
            ) best_n ON best_n.pedido_id_omie = p.pedido_id_omie
            LEFT JOIN biomie_notas_fiscais_canonicas n ON n.id = best_n.min_id
            LEFT JOIN (
                SELECT
                    COALESCE(nota_key, ''),
                    pedido_id_omie,
                    MIN(id) AS min_id,
                    nota_key AS fk_nota
                FROM biomie_titulos_financeiros_canonicos
                GROUP BY COALESCE(nota_key, ''), pedido_id_omie
            ) best_t ON (best_t.fk_nota = n.nota_key OR (best_t.fk_nota IS NULL AND best_t.pedido_id_omie = p.pedido_id_omie))
            LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.id = best_t.min_id
            WHERE COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end
            ON DUPLICATE KEY UPDATE
                competencia              = VALUES(competencia),
                codigo_pedido            = VALUES(codigo_pedido),
                codigo_vendedor_omie     = VALUES(codigo_vendedor_omie),
                nome_vendedor            = VALUES(nome_vendedor),
                codigo_cliente_omie      = VALUES(codigo_cliente_omie),
                nome_cliente             = VALUES(nome_cliente),
                documento_cliente        = VALUES(documento_cliente),
                status_comercial         = VALUES(status_comercial),
                data_emissao             = VALUES(data_emissao),
                data_faturamento         = VALUES(data_faturamento),
                valor_pedido             = VALUES(valor_pedido),
                valor_mercadorias_pedido = VALUES(valor_mercadorias_pedido),
                nota_key                 = VALUES(nota_key),
                status_fiscal            = VALUES(status_fiscal),
                data_competencia_nfe     = VALUES(data_competencia_nfe),
                valor_nfe                = VALUES(valor_nfe),
                titulo_key               = VALUES(titulo_key),
                status_titulo            = VALUES(status_titulo),
                data_vencimento          = VALUES(data_vencimento),
                data_liquidacao          = VALUES(data_liquidacao),
                valor_aberto             = VALUES(valor_aberto),
                valor_recebido           = VALUES(valor_recebido),
                bridge_status            = VALUES(bridge_status),
                refreshed_at             = NOW()
        SQL;

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_bridge', 'mart_bridge_pedido_nfe_titulo', 'insert_mart_bridge');
    }
}
