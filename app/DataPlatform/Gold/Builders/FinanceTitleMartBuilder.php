<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class FinanceTitleMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        $sql = <<<SQL
            INSERT INTO mart_financeiro_titulo (
                competencia, titulo_key, numero_documento, status_titulo, especie_documento,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                data_vencimento, data_liquidacao, data_competencia,
                valor_aberto, valor_recebido, dias_atraso,
                nota_key, pedido_id_omie, tem_nfe, tem_pedido, bridge_status, refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(t.data_competencia, t.data_vencimento), '%Y-%m'),
                t.titulo_key,
                t.numero_documento,
                t.status_titulo,
                t.especie_documento,
                COALESCE(NULLIF(t.codigo_vendedor_omie, ''), b.codigo_vendedor_omie,
                    (SELECT n.codigo_vendedor_omie
                     FROM biomie_bridge_nf_titulo_items bi
                     JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
                     WHERE bi.titulo_key = t.titulo_key
                       AND n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> ''
                     LIMIT 1), ''),
                COALESCE(NULLIF(t.nome_vendedor, 'Sem vendedor'), b.nome_vendedor,
                    (SELECT n.nome_vendedor
                     FROM biomie_bridge_nf_titulo_items bi
                     JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
                     WHERE bi.titulo_key = t.titulo_key
                       AND n.nome_vendedor IS NOT NULL AND n.nome_vendedor NOT IN ('', 'Sem vendedor')
                     LIMIT 1), 'Sem vendedor'),
                COALESCE(NULLIF(t.codigo_cliente_omie, 0), b.codigo_cliente_omie),
                COALESCE(NULLIF(t.nome_cliente, 'Cliente nao informado'), b.nome_cliente,
                    (SELECT n.nome_cliente
                     FROM biomie_bridge_nf_titulo_items bi
                     JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
                     WHERE bi.titulo_key = t.titulo_key
                       AND n.nome_cliente IS NOT NULL AND n.nome_cliente NOT IN ('', 'Cliente nao informado')
                     LIMIT 1), 'Cliente nao informado'),
                t.documento_cliente,
                t.data_vencimento,
                t.data_liquidacao,
                t.data_competencia,
                t.valor_aberto,
                t.valor_liquido,
                CASE
                    WHEN t.data_vencimento IS NOT NULL AND t.data_vencimento < CURDATE() AND t.valor_aberto > 0
                    THEN DATEDIFF(CURDATE(), t.data_vencimento)
                    ELSE 0
                END,
                t.nota_key,
                b.pedido_id_omie,
                IF(t.nota_key IS NOT NULL, 1, 0),
                IF(b.pedido_id_omie IS NOT NULL, 1, 0),
                CASE
                    WHEN b.pedido_id_omie IS NOT NULL AND t.nota_key IS NOT NULL THEN 'completo'
                    WHEN t.nota_key IS NOT NULL THEN 'nf_sem_titulo'
                    ELSE 'titulo_sem_pedido'
                END,
                NOW()
            FROM biomie_titulos_financeiros_canonicos t
            LEFT JOIN mart_bridge_pedido_nfe_titulo b
                ON b.titulo_key = t.titulo_key OR b.nota_key = t.nota_key
            WHERE COALESCE(t.data_competencia, t.data_vencimento) BETWEEN :start AND :end
            ON DUPLICATE KEY UPDATE
                competencia          = VALUES(competencia),
                status_titulo        = VALUES(status_titulo),
                codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                nome_vendedor        = VALUES(nome_vendedor),
                codigo_cliente_omie  = VALUES(codigo_cliente_omie),
                nome_cliente         = VALUES(nome_cliente),
                documento_cliente    = VALUES(documento_cliente),
                data_vencimento      = VALUES(data_vencimento),
                data_liquidacao      = VALUES(data_liquidacao),
                valor_aberto         = VALUES(valor_aberto),
                valor_recebido       = VALUES(valor_recebido),
                dias_atraso          = VALUES(dias_atraso),
                nota_key             = VALUES(nota_key),
                pedido_id_omie       = VALUES(pedido_id_omie),
                tem_nfe              = VALUES(tem_nfe),
                tem_pedido           = VALUES(tem_pedido),
                bridge_status        = VALUES(bridge_status),
                refreshed_at         = NOW()
        SQL;

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_financeiro_titulo', 'mart_financeiro_titulo', 'insert_mart_financeiro_titulo');
    }
}
