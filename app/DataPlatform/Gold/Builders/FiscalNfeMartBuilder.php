<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class FiscalNfeMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        $this->exec(
            'DELETE FROM mart_fiscal_nfe
              WHERE company_id = :company_id
                AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end',
            ['company_id' => $this->companyId(), 'start' => $start, 'end' => $end],
            'materialize_fiscal_nfe',
            'mart_fiscal_nfe',
            'delete_mart_fiscal_nfe_periodo',
        );

        $scopeFilter = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', 'n');
        $visibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', 'n');
        $commercialBase = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_base_comercial_nf')
            && $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::preComputedBaseSql('n')
            : ($this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
                ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('n')
                : 'n.valor_total');
        $targetHasCommercialBase = $this->columnExists('mart_fiscal_nfe', 'valor_base_comercial_nf');
        $targetBaseColumn = $targetHasCommercialBase ? ', valor_base_comercial_nf' : '';
        $targetBaseValue = $targetHasCommercialBase ? ",\n                {$commercialBase}" : '';
        $targetBaseUpdate = $targetHasCommercialBase
            ? "\n                valor_base_comercial_nf = VALUES(valor_base_comercial_nf),"
            : '';
        $sql = <<<SQL
            INSERT INTO mart_fiscal_nfe (
                company_id, business_channel, competencia, nota_key, numero_nf,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente,
                status_fiscal, data_emissao, data_competencia,
                valor_total{$targetBaseColumn}, valor_mercadorias, valor_descontos,
                pedido_id_omie, tem_pedido, tem_titulo, bridge_status, refreshed_at
            )
            SELECT
                n.company_id,
                n.business_channel,
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
                n.valor_total{$targetBaseValue},
                n.valor_mercadorias,
                n.valor_descontos,
                -- O pedido vinha SO da bridge. Como mart_bridge_pedido_nfe_titulo
                -- so tem ~200 linhas para ~2.150 NFs, 2.054 notas ficavam sem
                -- pedido no mart fiscal — embora o canonico traga
                -- pedido_id_omie preenchido em 100% delas, vindo da propria Omie.
                -- A bridge segue tendo precedencia (ela reconcilia pedido x NF x
                -- titulo); o canonico e o fallback, nao o contrario.
                COALESCE(b.pedido_id_omie, NULLIF(n.pedido_id_omie, 0)),
                IF(COALESCE(b.pedido_id_omie, NULLIF(n.pedido_id_omie, 0)) IS NOT NULL, 1, 0),
                IF(b.titulo_key IS NOT NULL, 1, 0),
                COALESCE(b.bridge_status, 'nf_sem_titulo'),
                NOW()
            FROM biomie_notas_fiscais_canonicas n
            LEFT JOIN mart_bridge_pedido_nfe_titulo b
              ON b.company_id = n.company_id
             AND b.business_channel = n.business_channel
             AND b.nota_key = n.nota_key
            WHERE n.company_id = :company_id
              AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end{$scopeFilter}{$visibilityFilter}
            ON DUPLICATE KEY UPDATE
                competencia       = VALUES(competencia),
                status_fiscal     = VALUES(status_fiscal),
                data_emissao      = VALUES(data_emissao),
                data_competencia  = VALUES(data_competencia),
                valor_total       = VALUES(valor_total),{$targetBaseUpdate}
                valor_mercadorias = VALUES(valor_mercadorias),
                valor_descontos   = VALUES(valor_descontos),
                pedido_id_omie    = VALUES(pedido_id_omie),
                tem_pedido        = VALUES(tem_pedido),
                tem_titulo        = VALUES(tem_titulo),
                bridge_status     = VALUES(bridge_status),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, [
            'company_id' => $this->companyId(),
            'start' => $start,
            'end' => $end,
        ], 'materialize_fiscal_nfe', 'mart_fiscal_nfe', 'insert_mart_fiscal_nfe');
    }
}
