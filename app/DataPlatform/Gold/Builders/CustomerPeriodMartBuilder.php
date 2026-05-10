<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use BIomie\DataGovernance\KpiDateContract;

final class CustomerPeriodMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $competencia, string $start, string $end): int
    {
        $commercialWhere = KpiDateContract::wherePeriod('commercial', 'p');
        $sqlComercial = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                p.codigo_cliente_omie,
                p.nome_cliente,
                p.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                SUM(p.status_comercial = 'faturado'),
                SUM(IF(p.status_comercial = 'faturado', p.valor_total, 0)),
                SUM(IF(p.status_comercial = 'faturado', p.valor_mercadorias, 0)),
                0, 0,
                0, 0, 0, 0,
                NOW()
            FROM biomie_pedidos_canonicos p
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = p.codigo_cliente_omie
            WHERE p.codigo_cliente_omie IS NOT NULL
              AND {$commercialWhere}
            GROUP BY p.codigo_cliente_omie, p.nome_cliente, p.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa          = VALUES(tipo_pessoa),
                segmento_cnae        = VALUES(segmento_cnae),
                pedidos_faturados    = VALUES(pedidos_faturados),
                valor_faturado       = VALUES(valor_faturado),
                mercadorias_faturado = VALUES(mercadorias_faturado),
                refreshed_at         = NOW()
        SQL;

        $rows = $this->exec($sqlComercial, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_comercial');

        $fiscalWhere = KpiDateContract::wherePeriod('fiscal', 'n');
        $sqlFiscal = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                n.codigo_cliente_omie,
                n.nome_cliente,
                n.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                0, 0, 0,
                SUM(n.status_fiscal = 'autorizada'),
                SUM(IF(n.status_fiscal = 'autorizada', n.valor_total, 0)),
                0, 0, 0, 0,
                NOW()
            FROM biomie_notas_fiscais_canonicas n
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = n.codigo_cliente_omie
            WHERE n.codigo_cliente_omie IS NOT NULL
              AND {$fiscalWhere}
            GROUP BY n.codigo_cliente_omie, n.nome_cliente, n.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa   = IF(tipo_pessoa = 'desconhecido', VALUES(tipo_pessoa), tipo_pessoa),
                segmento_cnae = IF(segmento_cnae = 'Não classificado', VALUES(segmento_cnae), segmento_cnae),
                nfes_emitidas = VALUES(nfes_emitidas),
                valor_nfe     = VALUES(valor_nfe),
                refreshed_at  = NOW()
        SQL;

        $this->exec($sqlFiscal, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_fiscal');

        $financialWhere = KpiDateContract::wherePeriod('financial', 't');
        $diasAtraso     = KpiDateContract::diasAtraso('t', 'CURDATE()');
        $sqlFinanceiro = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                t.codigo_cliente_omie,
                t.nome_cliente,
                t.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                0, 0, 0,
                0, 0,
                COUNT(*),
                SUM(t.valor_aberto),
                SUM(t.valor_liquido),
                MAX({$diasAtraso}),
                NOW()
            FROM biomie_titulos_financeiros_canonicos t
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = t.codigo_cliente_omie
            WHERE t.codigo_cliente_omie IS NOT NULL
              AND {$financialWhere}
            GROUP BY t.codigo_cliente_omie, t.nome_cliente, t.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa     = IF(tipo_pessoa = 'desconhecido', VALUES(tipo_pessoa), tipo_pessoa),
                segmento_cnae   = IF(segmento_cnae = 'Não classificado', VALUES(segmento_cnae), segmento_cnae),
                titulos_total   = VALUES(titulos_total),
                valor_aberto    = VALUES(valor_aberto),
                valor_recebido  = VALUES(valor_recebido),
                dias_atraso_max = VALUES(dias_atraso_max),
                refreshed_at    = NOW()
        SQL;

        $this->exec($sqlFinanceiro, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_financeiro');

        return $rows;
    }
}
